<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\ContratoComedor;
use App\Models\ContratoEmpresa;
use App\Models\Configuracion;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Invitacion;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Provider;
use App\Models\Repartidor;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\Api\RolesDeApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Editar y desactivar lo que el administrador (o cada empresa) da de alta. En EasyLunch nada se borra: «eliminar» es desactivar,
 * y se puede reactivar. Cada cambio deja su renglón en la bitácora.
 */
class EdicionDeRegistros
{
    public function __construct(
        private readonly AltaDeCuentas $altas,
        private readonly Invitaciones $invitaciones,
        private readonly CalendarioDeServicio $calendario,
        private readonly ZonasDeEntrega $zonas,
    ) {}

    // ------------------------------------------------------------------ empresas

    /**
     * Cambia los datos de la empresa, de su contacto y de su contrato vigente. Lo ya elegido conserva el precio y el subsidio con que se
     * eligió (se congelan al elegir): el cambio vale para lo que se elija desde ahora. Si se mandan `latitud` y `longitud`, se recalcula
     * su zona con PostGIS (`ST_Covers`) y se rechaza si cae fuera de cobertura; si no se mandan, conserva su zona y punto actuales.
     *
     * @param  array{nombre: string, direccion_entrega: string, latitud?: float|int|string|null, longitud?: float|int|string|null, contacto_nombre?: ?string, contacto_correo?: ?string, contacto_telefono?: ?string, porcentaje_subsidio: float|int|string, compra_minima_mensual: int, ventana_entrega_inicio: string, ventana_entrega_fin: string}  $d
     */
    public function editarEmpresa(User $actor, Empresa $empresa, array $d): Empresa
    {
        $nombre = trim($d['nombre']);
        if (Empresa::query()->whereRaw('lower(nombre) = ?', [Str::lower($nombre)])->where('id', '<>', $empresa->id)->exists()) {
            throw new ReglaDeNegocioException('empresa_repetida', 'Ya hay otra empresa con ese nombre.');
        }
        if ($d['ventana_entrega_fin'] <= $d['ventana_entrega_inicio']) {
            throw new ReglaDeNegocioException('ventana_invalida', 'La ventana de entrega debe terminar después de empezar.');
        }
        $contrato = ContratoEmpresa::query()->activos()->where('empresa_id', $empresa->id)->orderByDesc('vigencia_inicio')->first()
            ?? throw new ReglaDeNegocioException('sin_contrato', 'La empresa no tiene un contrato activo que editar: renueva el contrato primero.');

        $datosEmpresa = ['nombre' => $nombre, 'direccion_entrega' => trim($d['direccion_entrega'])];
        if (isset($d['latitud'], $d['longitud']) && $d['latitud'] !== '' && $d['longitud'] !== '') {
            $latitud = round((float) $d['latitud'], 6);
            $longitud = round((float) $d['longitud'], 6);
            $zona = $this->zonas->zonaParaPunto($latitud, $longitud);
            if ($zona === null) {
                throw new ReglaDeNegocioException('fuera_de_cobertura', 'El punto de entrega está fuera de las zonas de cobertura activas.');
            }
            $datosEmpresa += ['latitud' => $latitud, 'longitud' => $longitud, 'zona' => $zona];
        }

        $correoNuevo = ! empty($d['contacto_correo']) && $empresa->usuario ? $this->invitaciones->validarCambioDeCorreo($empresa->usuario, $d['contacto_correo']) : null;

        DB::transaction(function () use ($actor, $empresa, $contrato, $d, $datosEmpresa) {
            $empresa->update($datosEmpresa);
            $contrato->update([
                'porcentaje_subsidio' => $d['porcentaje_subsidio'], 'compra_minima_mensual' => $d['compra_minima_mensual'],
                'ventana_entrega_inicio' => $d['ventana_entrega_inicio'], 'ventana_entrega_fin' => $d['ventana_entrega_fin'], 'hora_entrega' => $d['ventana_entrega_inicio'],
            ]);
            $this->renombrar($empresa->usuario, $d['contacto_nombre'] ?? null, $d['contacto_telefono'] ?? null);
            Bitacora::registrar($actor, 'actualizar', 'empresas', $empresa->id, "Editó la empresa {$empresa->nombre}.");
        });
        if ($correoNuevo) {
            $this->invitaciones->aplicarCambioDeCorreo($actor, $empresa->usuario, $correoNuevo, $d['contacto_correo']);
        }

        return $empresa->refresh();
    }

    public function cambiarEstadoDeEmpresa(User $actor, Empresa $empresa, bool $activo): Empresa
    {
        if ($empresa->activo !== $activo) {
            $activo ? $empresa->reactivar() : $empresa->desactivar();
            Bitacora::registrar($actor, $activo ? 'reactivar' : 'desactivar', 'empresas', $empresa->id, ($activo ? 'Reactivó' : 'Desactivó')." la empresa {$empresa->nombre}.");
        }

        return $empresa->refresh();
    }

    // ------------------------------------------------------------------ comedores y platillos

    /**
     * Edita el comedor, su contacto y las condiciones de su contrato vigente (precio por platillo y mínimo mensual). El precio nuevo aplica
     * a lo que se entregue desde ahora. No cambia las clases del equipo: solo lee y guarda con ellas.
     *
     * @param  array{nombre: string, zona: string, contacto_nombre?: ?string, contacto_correo?: ?string, contacto_telefono?: ?string, precio_por_platillo: float|int|string, compra_minima_mensual: int}  $d
     */
    public function editarComedor(User $actor, Provider $comedor, array $d): Provider
    {
        $nombre = trim($d['nombre']);
        if (Provider::query()->where('id', '<>', $comedor->id)->where(fn ($q) => $q->whereRaw('lower(commercial_name) = ?', [Str::lower($nombre)])->orWhereRaw('lower(business_name) = ?', [Str::lower($nombre)]))->exists()) {
            throw new ReglaDeNegocioException('comedor_repetido', 'Ya hay otro comedor con ese nombre.');
        }
        $zona = $this->altas->zonaExistente($d['zona']);
        $contrato = ContratoComedor::query()->activos()->where('comedor_id', $comedor->id)->orderByDesc('vigencia_inicio')->first()
            ?? throw new ReglaDeNegocioException('sin_contrato', 'El comedor no tiene un contrato activo que editar: renueva el contrato primero.');
        $correoNuevo = ! empty($d['contacto_correo']) && $comedor->user ? $this->invitaciones->validarCambioDeCorreo($comedor->user, $d['contacto_correo']) : null;

        DB::transaction(function () use ($actor, $comedor, $contrato, $d, $nombre, $zona, $correoNuevo) {
            $comedor->update(array_filter([
                'commercial_name' => $nombre, 'zona' => $zona,
                'phone' => ! empty($d['contacto_telefono']) ? trim($d['contacto_telefono']) : null,
                'email' => $correoNuevo ? Str::lower(trim($d['contacto_correo'])) : null,
            ], fn ($v) => $v !== null));
            $contrato->update(['precio_por_platillo' => $d['precio_por_platillo'], 'compra_minima_mensual' => $d['compra_minima_mensual']]);
            $this->renombrar($comedor->user, $d['contacto_nombre'] ?? null, $d['contacto_telefono'] ?? null);
            Bitacora::registrar($actor, 'actualizar', 'comedores', $comedor->id, "Editó el comedor {$nombre}.");
        });
        if ($correoNuevo) {
            $this->invitaciones->aplicarCambioDeCorreo($actor, $comedor->user, $correoNuevo, $d['contacto_correo']);
        }

        return $comedor->refresh();
    }

    /**
     * Desactiva o reactiva un comedor. Desactivado deja de ofrecer TODOS sus platillos (sin cambiar el estado de cada uno, así que al
     * reactivarlo todo vuelve como estaba) y no entra a liquidaciones nuevas. No se puede desactivar con entregas por hacer.
     */
    public function cambiarEstadoDeComedor(User $actor, Provider $comedor, bool $activo): Provider
    {
        if ($comedor->is_active === $activo) {
            return $comedor;
        }
        if (! $activo && ($pendientes = $this->pedidosPorEntregar(Menu::query()->where('provider_id', $comedor->id)->pluck('id')->all())) > 0) {
            throw new ReglaDeNegocioException('comedor_con_pedidos', "Ese comedor tiene {$pendientes} platillo(s) por entregar: espera a que se entreguen antes de desactivarlo.");
        }

        $comedor->update(['is_active' => $activo]);
        $nombre = $comedor->commercial_name ?: $comedor->business_name;
        Bitacora::registrar($actor, $activo ? 'reactivar' : 'desactivar', 'comedores', $comedor->id, ($activo ? 'Reactivó' : 'Desactivó')." el comedor {$nombre}.");

        return $comedor->refresh();
    }

    /**
     * Pone o quita un platillo de la oferta (`is_available`): desactivado ya nadie lo elige ni lo pide, pero conserva su historial.
     * No se puede quitar mientras tenga elecciones o pedidos por entregar.
     */
    public function cambiarEstadoDePlatillo(User $actor, Menu $platillo, bool $activo): Menu
    {
        if ($platillo->is_available === $activo) {
            return $platillo;
        }
        if (! $activo && ($pendientes = $this->pedidosPorEntregar([$platillo->id])) > 0) {
            throw new ReglaDeNegocioException('platillo_con_pedidos', "«{$platillo->name}» tiene {$pendientes} entrega(s) por hacer: espera a que se entreguen antes de desactivarlo.");
        }

        $platillo->update(['is_available' => $activo]);
        Bitacora::registrar($actor, $activo ? 'platillo.reactivado' : 'platillo.desactivado', 'platillos', $platillo->id, ($activo ? 'Reactivó' : 'Desactivó')." el platillo «{$platillo->name}».");

        return $platillo->refresh();
    }

    /** Platillos elegidos por empleados o pedidos por clientes, de hoy en adelante y aún por entregar. */
    private function pedidosPorEntregar(array $menuIds): int
    {
        if ($menuIds === []) {
            return 0;
        }
        $hoy = $this->calendario->ahora()->toDateString();

        $empleados = DB::table('elecciones_empleado')->whereIn('menu_id', $menuIds)->whereDate('fecha', '>=', $hoy)->count();
        $clientes = DB::table('pedido_dia_platillos as c')->join('pedido_dias as d', 'd.id', '=', 'c.pedido_dia_id')->join('pedidos as p', 'p.id', '=', 'd.pedido_id')
            ->whereIn('c.menu_id', $menuIds)->whereDate('d.fecha', '>=', $hoy)->where('d.estado', 'programada')->whereIn('p.estado', ['pendiente_pago', 'recibido', 'activo'])->count();

        return $empleados + $clientes;
    }

    // ------------------------------------------------------------------ empleados

    /** La empresa cambia el nombre y el departamento de uno de sus empleados. */
    public function editarEmpleado(User $actor, Empleado $empleado, string $nombre, ?string $departamento, ?string $correo = null): Empleado
    {
        $correoNuevo = $correo ? $this->invitaciones->validarCambioDeCorreo($empleado->usuario, $correo) : null;

        DB::transaction(function () use ($actor, $empleado, $nombre, $departamento) {
            $this->renombrar($empleado->usuario, $nombre, null);
            $empleado->update(['departamento' => $departamento !== null && trim($departamento) !== '' ? trim($departamento) : null]);
            Bitacora::registrar($actor, 'actualizar', 'empleados', $empleado->id, "Editó al empleado {$empleado->usuario->name}.");
        });
        if ($correoNuevo) {
            $this->invitaciones->aplicarCambioDeCorreo($actor, $empleado->usuario, $correoNuevo, $correo);
        }

        return $empleado->refresh();
    }

    // ------------------------------------------------------------------ repartidores

    /**
     * @param  array{nombre: string, telefono?: ?string, numero_empleado?: ?string, tipo_vehiculo?: ?string, placas: string, zona_base: string}  $d
     */
    public function editarRepartidor(User $actor, Repartidor $repartidor, array $d): Repartidor
    {
        if (($d['tipo_vehiculo'] ?? 'automovil') !== 'automovil') {
            throw new ReglaDeNegocioException('solo_automovil', 'Solo se reparte en auto: elige «automóvil» y captura sus placas.');
        }
        $numero = trim((string) ($d['numero_empleado'] ?? '')) ?: $repartidor->numero_empleado;
        if (Repartidor::query()->where('numero_empleado', $numero)->where('id', '<>', $repartidor->id)->exists()) {
            throw new ReglaDeNegocioException('numero_empleado_repetido', 'Ese número de empleado ya lo tiene otro repartidor.');
        }
        $zona = $this->altas->zonaExistente($d['zona_base']);

        DB::transaction(function () use ($actor, $repartidor, $d, $numero, $zona) {
            $this->renombrar($repartidor->usuario, $d['nombre'], $d['telefono'] ?? null);
            $repartidor->update(['numero_empleado' => $numero, 'placas' => Str::upper(trim($d['placas'])), 'zona_base' => $zona]);
            Bitacora::registrar($actor, 'actualizar', 'repartidores', $repartidor->id, "Editó al repartidor {$repartidor->usuario->name}.");
        });

        return $repartidor->refresh();
    }

    /** Desactivar a quien todavía tiene rutas por hacer dejaría entregas sin repartidor: primero hay que reasignarlas. */
    public function cambiarEstadoDeRepartidor(User $actor, Repartidor $repartidor, bool $activo): Repartidor
    {
        if ($repartidor->activo === $activo) {
            return $repartidor;
        }
        if (! $activo) {
            $hoy = $this->calendario->ahora()->toDateString();
            if ($repartidor->rutas()->whereDate('fecha', '>=', $hoy)->exists()) {
                throw new ReglaDeNegocioException('repartidor_con_rutas', 'Ese repartidor tiene rutas por hacer: reasigna sus rutas antes de desactivarlo.');
            }
        }

        $activo ? $repartidor->reactivar() : $repartidor->desactivar();
        $repartidor->update(['estado' => $activo ? 'libre' : 'no_disponible']);
        $nombre = $repartidor->usuario?->name ?? "repartidor {$repartidor->id}";
        Bitacora::registrar($actor, $activo ? 'reactivar' : 'desactivar', 'repartidores', $repartidor->id, ($activo ? 'Reactivó' : 'Desactivó')." al repartidor {$nombre}.");

        return $repartidor->refresh();
    }

    /** Cuántas entregas caben en un auto. Las rutas que ya existen conservan la capacidad con que se crearon. */
    public function guardarCapacidadDelAuto(User $actor, int $entregas): void
    {
        $anterior = $this->calendario->capacidadDelAuto()['entregas'];
        if ($anterior === $entregas) {
            return;
        }
        Configuracion::query()->whereKey('capacidad_auto_entregas')->update(['valor' => (string) $entregas, 'actualizado_por' => $actor->id, 'actualizado_en' => now()]);
        Bitacora::registrar($actor, 'actualizar', 'configuracion', 'capacidad_auto_entregas', "Cambió la capacidad del auto de {$anterior} a {$entregas} entregas.");
    }

    // ------------------------------------------------------------------ planes (paquetes)

    /**
     * Crea un plan para clientes individuales. Solo hay planes de 1, 2 o 6 días y un solo plan activo por cada duración: para
     * cambiar el de una duración que ya existe, se edita o se desactiva primero.
     */
    public function crearPaquete(User $actor, string $nombre, int $dias, float|int|string $descuento, ?string $detalle, bool $destacado): Paquete
    {
        $nombre = trim($nombre);
        if (Paquete::query()->whereRaw('lower(nombre) = ?', [Str::lower($nombre)])->exists()) {
            throw new ReglaDeNegocioException('plan_repetido', 'Ya hay un plan con ese nombre.');
        }
        if (Paquete::query()->activos()->where('dias', $dias)->exists()) {
            throw new ReglaDeNegocioException('plan_de_esos_dias_activo', "Ya hay un plan activo de {$dias} ".($dias === 1 ? 'día' : 'días').': edítalo o desactívalo para crear otro.');
        }

        $paquete = Paquete::create(['nombre' => $nombre, 'dias' => $dias, 'descuento_porcentaje' => $descuento, 'detalle' => $detalle !== null && trim($detalle) !== '' ? trim($detalle) : null, 'destacado' => $destacado]);
        Bitacora::registrar($actor, 'crear', 'paquetes', $paquete->id, "Creó el plan {$paquete->nombre} ({$dias} días, descuento {$descuento} %).");

        return $paquete->refresh();
    }

    /** Desactiva (deja de ofrecerse; los pedidos que ya lo usan no cambian) o reactiva un plan. */
    public function cambiarEstadoDePaquete(User $actor, Paquete $paquete, bool $activo): Paquete
    {
        if ($paquete->activo === $activo) {
            return $paquete;
        }
        if ($activo && Paquete::query()->activos()->where('dias', $paquete->dias)->exists()) {
            throw new ReglaDeNegocioException('plan_de_esos_dias_activo', "Ya hay otro plan activo de {$paquete->dias} días: desactívalo antes de reactivar este.");
        }

        $activo ? $paquete->reactivar() : $paquete->desactivar();
        Bitacora::registrar($actor, $activo ? 'reactivar' : 'desactivar', 'paquetes', $paquete->id, ($activo ? 'Reactivó' : 'Desactivó')." el plan {$paquete->nombre}.");

        return $paquete->refresh();
    }

    public function editarPaquete(User $actor, Paquete $paquete, string $nombre, float|int|string $descuento, ?string $detalle, bool $destacado): Paquete
    {
        $nombre = trim($nombre);
        if (Paquete::query()->whereRaw('lower(nombre) = ?', [Str::lower($nombre)])->where('id', '<>', $paquete->id)->exists()) {
            throw new ReglaDeNegocioException('plan_repetido', 'Ya hay otro plan con ese nombre.');
        }
        $paquete->update(['nombre' => $nombre, 'descuento_porcentaje' => $descuento, 'detalle' => $detalle !== null && trim($detalle) !== '' ? trim($detalle) : null, 'destacado' => $destacado]);
        Bitacora::registrar($actor, 'actualizar', 'paquetes', $paquete->id, "Editó el plan {$paquete->nombre} (descuento {$paquete->descuento_porcentaje} %).");

        return $paquete->refresh();
    }

    // ------------------------------------------------------------------ usuarios con acceso

    /** @return array<string, mixed> la fila del usuario como la ve la pantalla de accesos */
    public function editarUsuarioDeAcceso(User $actor, int $usuarioId, string $nombre, string $rolDelFront, ?string $correo = null): array
    {
        [$usuario, $tipo, $organizacion, $invitacion] = $this->usuarioDeAcceso($actor, $usuarioId);
        $correoNuevo = $correo ? $this->invitaciones->validarCambioDeCorreo($usuario, $correo) : null;
        $principal = $this->esTitular($usuario, $tipo, $organizacion);
        $rolBase = RolesDeApi::base($rolDelFront);
        $cambiaElRol = $usuario->role->name !== $rolBase;
        $rol = null;

        if ($cambiaElRol) {
            if (! in_array($rolDelFront, Invitaciones::ROLES_INVITABLES[$tipo] ?? [], true)) {
                throw new ReglaDeNegocioException('rol_no_corresponde', 'Ese rol no corresponde a esta organización.');
            }
            if ($principal && $tipo !== 'plataforma') {
                throw new ReglaDeNegocioException('titular_sin_cambio_de_rol', 'El titular de la organización no puede cambiar de rol.');
            }
            if ($usuario->is($actor)) {
                throw new ReglaDeNegocioException('no_tu_propio_rol', 'No puedes cambiar tu propio rol.');
            }
            $rol = Role::query()->where('name', $rolBase)->first() ?? throw new ReglaDeNegocioException('rol_desconocido', 'Ese rol no existe.');
        }

        DB::transaction(function () use ($actor, $usuario, $nombre, $cambiaElRol, $rol, $invitacion) {
            $this->renombrar($usuario, $nombre, null);
            if ($cambiaElRol) {
                $usuario->update(['role_id' => $rol->id]);
                $invitacion?->update(['rol_id' => $rol->id]);
                $usuario->tokens()->delete(); // la sesión abierta era de otro rol
            }
            Bitacora::registrar($actor, 'actualizar', 'users', $usuario->id, "Editó al usuario {$usuario->name}".($cambiaElRol ? " (ahora {$rol->name})." : '.'));
        });

        if ($correoNuevo) {
            $this->invitaciones->aplicarCambioDeCorreo($actor, $usuario, $correoNuevo, $correo);
        }

        return $this->invitaciones->fila($usuario->refresh()->load('role'), $invitacion?->refresh(), $principal, null);
    }

    /** @return array<string, mixed> */
    public function cambiarEstadoDeUsuarioDeAcceso(User $actor, int $usuarioId, bool $activo): array
    {
        [$usuario, $tipo, $organizacion, $invitacion] = $this->usuarioDeAcceso($actor, $usuarioId);
        $principal = $this->esTitular($usuario, $tipo, $organizacion);

        if (! $activo && $usuario->is($actor)) {
            throw new ReglaDeNegocioException('no_te_desactives', 'No puedes desactivar tu propia cuenta.');
        }
        if ($activo && $invitacion && ! $invitacion->aceptada_en) {
            throw new ReglaDeNegocioException('invitacion_pendiente', 'Esa persona todavía no acepta su invitación: reenvíasela en lugar de activarla.');
        }

        if (($usuario->status === 'active') !== $activo) {
            $usuario->update(['status' => $activo ? 'active' : 'inactive']);
            if (! $activo) {
                $usuario->tokens()->delete(); // cierra sus sesiones abiertas
            }
            Bitacora::registrar($actor, $activo ? 'reactivar' : 'desactivar', 'users', $usuario->id, ($activo ? 'Reactivó' : 'Desactivó')." al usuario {$usuario->name}.");
        }

        return $this->invitaciones->fila($usuario->refresh()->load('role'), $invitacion, $principal, null);
    }

    // ------------------------------------------------------------------ apoyo

    /**
     * A qué organización pertenece un usuario con acceso y si quien consulta puede administrarla.
     * Los empleados, clientes y repartidores no son «usuarios con acceso»: no existen aquí.
     *
     * @return array{0: User, 1: string, 2: ?int, 3: ?Invitacion}
     */
    private function usuarioDeAcceso(User $actor, int $usuarioId): array
    {
        $usuario = User::query()->with('role')->findOrFail($usuarioId);
        $invitacion = Invitacion::query()->where('usuario_id', $usuario->id)->first();

        if ($invitacion) {
            $tipo = $invitacion->organizacion_tipo;
            $organizacion = $invitacion->comedor_id ?? $invitacion->empresa_id;
        } elseif ($empresa = Empresa::query()->where('usuario_id', $usuario->id)->first()) {
            [$tipo, $organizacion] = ['empresa', $empresa->id];
        } elseif ($comedor = Provider::query()->where('user_id', $usuario->id)->first()) {
            [$tipo, $organizacion] = ['comedor', $comedor->id];
        } else {
            [$tipo, $organizacion] = ['plataforma', null];
        }

        $esDeAcceso = match ($tipo) {
            'plataforma' => in_array($usuario->role->name, ['admin', 'support'], true),
            'empresa' => $usuario->role->name === 'company',
            default => $usuario->role->name === 'provider',
        };
        abort_unless($esDeAcceso, 404);
        $this->invitaciones->autorizar($actor, $tipo, $organizacion);

        return [$usuario, $tipo, $organizacion, $invitacion];
    }

    /** El titular de una empresa o comedor es su contacto (aunque haya entrado por invitación); en la plataforma, quien no llegó por una. */
    private function esTitular(User $usuario, string $tipo, ?int $organizacion): bool
    {
        return match ($tipo) {
            'empresa' => Empresa::query()->whereKey($organizacion)->value('usuario_id') === $usuario->id,
            'comedor' => Provider::query()->whereKey($organizacion)->value('user_id') === $usuario->id,
            default => ! Invitacion::query()->where('usuario_id', $usuario->id)->exists(),
        };
    }

    /** Cambia el nombre (y el teléfono, si viene) de la cuenta y de su perfil; si la cuenta no tiene perfil todavía, se lo crea. */
    private function renombrar(?User $usuario, ?string $nombre, ?string $telefono): void
    {
        if (! $usuario) {
            return;
        }
        $nombre = $nombre !== null ? trim($nombre) : '';
        if ($nombre !== '' && $nombre !== $usuario->name) {
            $usuario->update(['name' => $nombre]);
        }

        $perfil = UserProfile::query()->firstOrNew(['user_id' => $usuario->id]);
        [$pila, $apellidos] = array_pad(explode(' ', $usuario->name, 2), 2, '');
        $perfil->first_name = $pila;
        $perfil->last_name = trim($apellidos) ?: $pila;
        if ($telefono !== null) {
            $perfil->phone = trim($telefono) ?: null;
        }
        $perfil->save();
    }
}
