<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\ContratoComedor;
use App\Models\ContratoEmpresa;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Provider;
use App\Models\Repartidor;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Las altas: empresa y comedor (cada uno con su contacto, su invitación y su contrato), repartidor y empleados de una empresa.
 *
 * Cada alta es una sola transacción (si algo falla, no queda nada a medias) y las cuentas nacen inactivas con una invitación: la persona
 * elige su contraseña al aceptar. Los correos salen cuando ya se guardó todo.
 */
class AltaDeCuentas
{
    public function __construct(private readonly Invitaciones $invitaciones, private readonly CalendarioDeServicio $calendario) {}

    // ------------------------------------------------------------------ empresa

    /**
     * Da de alta una empresa: su contacto de RR. HH., la empresa y su contrato de 6 meses con el mínimo y el subsidio acordados.
     *
     * @param  array{nombre: string, contacto_nombre: string, contacto_correo: string, contacto_telefono?: ?string, direccion_entrega: string, zona: string, vigencia_inicio: string, compra_minima_mensual: int, porcentaje_subsidio: float|int|string, precio_por_platillo?: float|int|string|null, ventana_entrega_inicio: string, ventana_entrega_fin: string, hora_entrega?: ?string}  $d
     */
    public function crearEmpresa(User $admin, array $d): Empresa
    {
        $nombre = trim($d['nombre']);
        if (Empresa::query()->whereRaw('lower(nombre) = ?', [Str::lower($nombre)])->exists()) {
            throw new ReglaDeNegocioException('empresa_repetida', 'Ya hay una empresa con ese nombre.');
        }
        $zona = $this->zonaExistente($d['zona']);
        $correo = Str::lower(trim($d['contacto_correo']));
        $inicio = CarbonImmutable::parse($d['vigencia_inicio']);

        [$empresa, $invitacion, $token] = DB::transaction(function () use ($admin, $d, $nombre, $zona, $correo, $inicio) {
            $contacto = $this->invitaciones->prepararCuenta($d['contacto_nombre'], $correo, 'empresa');
            if (! empty($d['contacto_telefono'])) {
                UserProfile::query()->where('user_id', $contacto->id)->update(['phone' => $d['contacto_telefono']]);
            }

            $empresa = Empresa::create(['usuario_id' => $contacto->id, 'nombre' => $nombre, 'dominio' => Str::after($correo, '@'), 'zona' => $zona, 'direccion_entrega' => trim($d['direccion_entrega'])]);
            ContratoEmpresa::create([
                'empresa_id' => $empresa->id, 'porcentaje_subsidio' => $d['porcentaje_subsidio'], 'compra_minima_mensual' => $d['compra_minima_mensual'],
                'precio_por_platillo' => $d['precio_por_platillo'] ?? config('easylunch.precio_por_platillo_empresa'),
                'vigencia_inicio' => $inicio->toDateString(), 'vigencia_fin' => $inicio->addMonthsNoOverflow((int) config('easylunch.meses_de_contrato'))->subDay()->toDateString(),
                'hora_entrega' => $d['hora_entrega'] ?? $d['ventana_entrega_inicio'], 'ventana_entrega_inicio' => $d['ventana_entrega_inicio'], 'ventana_entrega_fin' => $d['ventana_entrega_fin'],
            ]);
            [$invitacion, $token] = $this->invitaciones->crearInvitacion($contacto, 'empresa', $empresa->id, 'empresa');
            Bitacora::registrar($admin, 'crear', 'empresas', $empresa->id, "Dio de alta la empresa {$empresa->nombre} (contacto: {$contacto->email}).");

            return [$empresa, $invitacion, $token];
        });

        $this->invitaciones->enviar($invitacion->refresh(), $token);

        return $empresa;
    }

    // ------------------------------------------------------------------ comedor

    /**
     * Da de alta un comedor: su contacto (que recibe la invitación para entrar al portal de comedores), el comedor y su contrato de
     * 6 meses con el precio por platillo y el mínimo mensual acordados. No toca el modelo `Provider`: solo lo usa.
     *
     * @param  array{nombre: string, razon_social?: ?string, rfc?: ?string, contacto_nombre: string, contacto_correo: string, telefono: string, direccion: string, codigo_postal?: ?string, zona: string, capacidad_diaria?: ?int, vigencia_inicio: string, precio_por_platillo: float|int|string, compra_minima_mensual: int}  $d
     */
    public function crearComedor(User $admin, array $d): Provider
    {
        $nombre = trim($d['nombre']);
        $razon = trim((string) ($d['razon_social'] ?? '')) ?: $nombre;
        $rfc = ! empty($d['rfc']) ? Str::upper(trim($d['rfc'])) : null;
        if (Provider::query()->where(fn ($q) => $q->whereRaw('lower(business_name) = ?', [Str::lower($razon)])->orWhereRaw('lower(commercial_name) = ?', [Str::lower($nombre)]))->exists()) {
            throw new ReglaDeNegocioException('comedor_repetido', 'Ya hay un comedor con ese nombre.');
        }
        if ($rfc && Provider::withTrashed()->where('rfc', $rfc)->exists()) {
            throw new ReglaDeNegocioException('rfc_repetido', 'Ya hay un comedor con ese RFC.');
        }
        $zona = $this->zonaExistente($d['zona']);
        $correo = Str::lower(trim($d['contacto_correo']));
        $inicio = CarbonImmutable::parse($d['vigencia_inicio']);

        [$comedor, $invitacion, $token] = DB::transaction(function () use ($admin, $d, $nombre, $razon, $rfc, $zona, $correo, $inicio) {
            $contacto = $this->invitaciones->prepararCuenta($d['contacto_nombre'], $correo, 'comedor');
            UserProfile::query()->where('user_id', $contacto->id)->update(['phone' => $d['telefono']]);

            $comedor = Provider::create([
                'user_id' => $contacto->id, 'business_name' => $razon, 'commercial_name' => $nombre, 'rfc' => $rfc, 'phone' => $d['telefono'], 'email' => $correo,
                'address' => trim($d['direccion']), 'postal_code' => $d['codigo_postal'] ?? null, 'zona' => $zona, 'daily_capacity' => $d['capacidad_diaria'] ?? 100,
            ]);
            ContratoComedor::create([
                'comedor_id' => $comedor->id, 'precio_por_platillo' => $d['precio_por_platillo'], 'compra_minima_mensual' => $d['compra_minima_mensual'],
                'vigencia_inicio' => $inicio->toDateString(), 'vigencia_fin' => $inicio->addMonthsNoOverflow((int) config('easylunch.meses_de_contrato'))->subDay()->toDateString(),
            ]);
            [$invitacion, $token] = $this->invitaciones->crearInvitacion($contacto, 'comedor', $comedor->id, 'comedor');
            Bitacora::registrar($admin, 'crear', 'comedores', $comedor->id, "Dio de alta el comedor {$nombre} (contacto: {$contacto->email}).");

            return [$comedor, $invitacion, $token];
        });

        $this->invitaciones->enviar($invitacion->refresh(), $token);

        return $comedor->refresh();
    }

    // ------------------------------------------------------------------ repartidor

    /**
     * Da de alta a un repartidor: su cuenta (con invitación) y su ficha. Solo se reparte en auto, así que las placas son obligatorias.
     *
     * @param  array{nombre: string, correo: string, telefono?: ?string, numero_empleado?: ?string, placas: string, zona_base: string}  $d
     */
    public function crearRepartidor(User $admin, array $d): Repartidor
    {
        $zona = $this->zonaExistente($d['zona_base']);
        $numero = trim((string) ($d['numero_empleado'] ?? '')) ?: $this->siguienteNumeroDeEmpleado();
        if (Repartidor::query()->where('numero_empleado', $numero)->exists()) {
            throw new ReglaDeNegocioException('numero_de_empleado_repetido', "El número de empleado {$numero} ya lo tiene otro repartidor.");
        }

        [$repartidor, $invitacion, $token] = DB::transaction(function () use ($admin, $d, $zona, $numero) {
            $usuario = $this->invitaciones->prepararCuenta($d['nombre'], $d['correo'], 'repartidor');
            if (! empty($d['telefono'])) {
                UserProfile::query()->where('user_id', $usuario->id)->update(['phone' => $d['telefono']]);
            }
            $repartidor = Repartidor::create(['usuario_id' => $usuario->id, 'numero_empleado' => $numero, 'placas' => Str::upper(trim($d['placas'])), 'zona_base' => $zona]);
            [$invitacion, $token] = $this->invitaciones->crearInvitacion($usuario, 'plataforma', null, 'repartidor');
            Bitacora::registrar($admin, 'crear', 'repartidores', $repartidor->id, "Dio de alta al repartidor {$usuario->name} ({$numero}, zona {$zona}).");

            return [$repartidor, $invitacion, $token];
        });

        $this->invitaciones->enviar($invitacion->refresh(), $token);

        return $repartidor->refresh(); // se vuelve a leer: los valores por defecto de la base (estado) no vienen en un modelo recién creado
    }

    // ------------------------------------------------------------------ empleados de una empresa

    /**
     * Agrega empleados a una empresa (uno por uno o desde un CSV). Cada uno recibe una invitación para crear su contraseña.
     * Un correo que ya tiene cuenta, o que viene repetido en la lista, se omite (no se rompe todo el alta).
     * Si falta el correo se arma con el nombre y el dominio de la empresa (ana.ruiz@nexo.mx).
     *
     * @param  array<int, array{nombre: string, correo?: ?string}>  $personas
     * @return array{agregados: int, omitidos: int}
     */
    public function agregarEmpleados(User $actor, Empresa $empresa, array $personas): array
    {
        $vistos = [];
        $pendientes = [];
        $agregados = 0;

        DB::transaction(function () use ($actor, $empresa, $personas, &$vistos, &$pendientes, &$agregados) {
            foreach ($personas as $persona) {
                $nombre = trim($persona['nombre']);
                $correo = Str::lower(trim((string) ($persona['correo'] ?? ''))) ?: $this->correoDesdeElNombre($nombre, $empresa->dominio);
                if (isset($vistos[$correo]) || User::query()->whereRaw('lower(email) = ?', [$correo])->exists()) {
                    continue;
                }
                $vistos[$correo] = true;

                $usuario = $this->invitaciones->prepararCuenta($nombre, $correo, 'empleado');
                Empleado::create(['usuario_id' => $usuario->id, 'empresa_id' => $empresa->id]);
                $pendientes[] = $this->invitaciones->crearInvitacion($usuario, 'empresa', $empresa->id, 'empleado');
                $agregados++;
            }
            if ($agregados > 0) {
                Bitacora::registrar($actor, 'crear', 'empleados', $empresa->id, "Agregó {$agregados} empleado(s) a {$empresa->nombre}.");
            }
        });

        foreach ($pendientes as [$invitacion, $token]) {
            $this->invitaciones->enviar($invitacion->refresh(), $token);
        }

        return ['agregados' => $agregados, 'omitidos' => count($personas) - $agregados];
    }

    // ------------------------------------------------------------------ apoyo

    /** El nombre de la zona tal como está cargada. Sin zona cargada nadie podría pedir ni se podrían armar rutas. */
    public function zonaExistente(string $zona): string
    {
        $existente = DB::table('zonas')->whereRaw('lower(nombre) = ?', [Str::lower(trim($zona))])->value('nombre');
        if ($existente) {
            return $existente;
        }
        $disponibles = DB::table('zonas')->orderBy('nombre')->pluck('nombre')->implode(', ');

        throw new ReglaDeNegocioException('zona_inexistente', 'Esa zona no existe'.($disponibles ? ". Zonas disponibles: {$disponibles}." : ': todavía no hay zonas cargadas.'));
    }

    private function siguienteNumeroDeEmpleado(): string
    {
        $siguiente = (int) Repartidor::query()->get('numero_empleado')->map(fn ($r) => (int) preg_replace('/\D/', '', $r->numero_empleado))->max() + 1;

        return 'R-'.str_pad((string) $siguiente, 3, '0', STR_PAD_LEFT);
    }

    private function correoDesdeElNombre(string $nombre, string $dominio): string
    {
        return Str::slug($nombre, '.').'@'.$dominio;
    }
}
