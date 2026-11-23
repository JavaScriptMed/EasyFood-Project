<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Mail\InvitacionDeAcceso;
use App\Models\Empresa;
use App\Models\Invitacion;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\Api\ContextoDelUsuario;
use App\Support\Api\RolesDeApi;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invitaciones de acceso: alguien de administración, de una empresa o de un comedor invita a una persona por correo.
 *
 * - La cuenta nace al invitar, inactiva y con una contraseña que nadie conoce; se activa cuando la persona acepta y elige la suya.
 * - Del enlace solo se guarda el hash. El enlace completo viaja únicamente en el correo (y, en desarrollo, en storage/logs/enlaces.log).
 * - Vence a los 7 días; se puede reenviar (enlace nuevo, el anterior deja de servir) mientras no se acepte.
 * - Cada quien invita solo dentro de lo suyo: administración a cualquier organización, la empresa a su empresa y el comedor a su comedor.
 */
class Invitaciones
{
    /** Roles (del front) que se pueden invitar a cada tipo de organización. Los empleados se dan de alta por la empresa, no con esta pantalla. */
    public const ROLES_INVITABLES = ['comedor' => ['comedor'], 'empresa' => ['empresa'], 'plataforma' => ['administrador', 'soporte']];

    public function __construct(private readonly EnlacesDeCuenta $enlaces, private readonly CalendarioDeServicio $calendario) {}

    // ------------------------------------------------------------------ quien puede qué

    /** @throws \Symfony\Component\HttpKernel\Exception\HttpException 403 si esa persona no administra a la gente de esa organización */
    public function autorizar(User $actor, string $tipo, ?int $organizacionId): void
    {
        $rol = $actor->role?->name;
        $permitido = match ($rol) {
            'admin' => true,
            'company' => $tipo === 'empresa' && $organizacionId !== null && ContextoDelUsuario::empresaDe($actor)?->id === $organizacionId,
            'provider' => $tipo === 'comedor' && $organizacionId !== null && ContextoDelUsuario::comedorDe($actor)?->id === $organizacionId,
            default => false,
        };
        abort_unless($permitido, 403, 'No puedes administrar a las personas de esa organización.');
    }

    // ------------------------------------------------------------------ invitar y reenviar

    /**
     * Invita a una persona a entrar al portal de su rol. Devuelve la invitación y el enlace, que solo se conoce aquí (no se guarda).
     *
     * @return array{0: Invitacion, 1: string}
     */
    public function invitar(User $actor, string $tipo, ?int $organizacionId, string $nombre, string $correo, string $rolDelFront): array
    {
        $this->autorizar($actor, $tipo, $organizacionId);
        if (! in_array($rolDelFront, self::ROLES_INVITABLES[$tipo] ?? [], true)) {
            throw new ReglaDeNegocioException('rol_no_corresponde', 'Ese rol no corresponde a esta organización.');
        }

        return $this->crearCuenta($actor, $tipo, $organizacionId, $nombre, $correo, $rolDelFront);
    }

    /**
     * Invita a varias personas a la vez (un archivo de nombres y correos). Cada una recibe su invitación por separado: un correo que ya
     * tiene cuenta o que viene repetido en la lista se omite y se dice cuál y por qué, sin romper al resto.
     *
     * @param  array<int, array{nombre: string, correo: string}>  $personas
     * @return array{invitados: int, omitidos: int, correos_omitidos: array<int, array{nombre: string, correo: string, motivo: string}>}
     */
    public function invitarVarios(User $actor, string $tipo, ?int $organizacionId, string $rolDelFront, array $personas): array
    {
        $this->autorizar($actor, $tipo, $organizacionId);
        if (! in_array($rolDelFront, self::ROLES_INVITABLES[$tipo] ?? [], true)) {
            throw new ReglaDeNegocioException('rol_no_corresponde', 'Ese rol no corresponde a esta organización.');
        }

        $vistos = [];
        $omitidos = [];
        $invitados = 0;
        foreach ($personas as $persona) {
            $nombre = trim($persona['nombre']);
            $correo = Str::lower(trim($persona['correo']));
            if (isset($vistos[$correo])) {
                $omitidos[] = ['nombre' => $nombre, 'correo' => $correo, 'motivo' => 'repetido_en_el_archivo'];

                continue;
            }
            try {
                $this->crearCuenta($actor, $tipo, $organizacionId, $nombre, $correo, $rolDelFront);
            } catch (ReglaDeNegocioException $e) {
                if ($e->codigo !== 'correo_en_uso') {
                    throw $e;
                }
                $omitidos[] = ['nombre' => $nombre, 'correo' => $correo, 'motivo' => 'ya_tiene_cuenta'];

                continue;
            }
            $vistos[$correo] = true;
            $invitados++;
        }

        return ['invitados' => $invitados, 'omitidos' => count($omitidos), 'correos_omitidos' => $omitidos];
    }

    /**
     * Crea la cuenta (inactiva) con su invitación y manda el correo. Lo usa la pantalla de invitar.
     *
     * @return array{0: Invitacion, 1: string}
     */
    public function crearCuenta(?User $actor, string $tipo, ?int $organizacionId, string $nombre, string $correo, string $rolDelFront, ?CarbonInterface $ahora = null): array
    {
        $organizacion = $this->nombreDeLaOrganizacion($tipo, $organizacionId);
        [$invitacion, $token] = DB::transaction(function () use ($actor, $tipo, $organizacionId, $nombre, $correo, $rolDelFront, $ahora, $organizacion) {
            $usuario = $this->prepararCuenta($nombre, $correo, $rolDelFront);
            [$invitacion, $token] = $this->crearInvitacion($usuario, $tipo, $organizacionId, $rolDelFront, $ahora);
            Bitacora::registrar($actor, 'invitar', 'usuarios', $usuario->id, "Invitó a {$usuario->name} ({$rolDelFront}) a {$organizacion}.");

            return [$invitacion, $token];
        });
        $this->enviar($invitacion->refresh(), $token);

        return [$invitacion, $token];
    }

    /**
     * La cuenta de una persona antes de que acepte: inactiva y con una contraseña que nadie conoce (la elige al aceptar). Parte de las altas
     * (empresa, repartidor, empleados): llámalo dentro de la transacción del alta y manda el correo con enviar() cuando ya se guardó.
     *
     * @throws ReglaDeNegocioException correo_en_uso | rol_desconocido
     */
    public function prepararCuenta(string $nombre, string $correo, string $rolDelFront): User
    {
        $nombre = trim($nombre);
        $correo = Str::lower(trim($correo));
        $rol = Role::query()->where('name', RolesDeApi::base($rolDelFront))->first() ?? throw new ReglaDeNegocioException('rol_desconocido', 'Ese rol no existe.');
        if (User::query()->whereRaw('lower(email) = ?', [$correo])->exists()) {
            throw new ReglaDeNegocioException('correo_en_uso', 'Ese correo ya tiene una cuenta o una invitación.');
        }

        $usuario = User::create(['role_id' => $rol->id, 'name' => $nombre, 'email' => $correo, 'password' => Str::random(48), 'status' => 'inactive']);
        [$nombrePila, $apellidos] = array_pad(explode(' ', $nombre, 2), 2, '');
        UserProfile::create(['user_id' => $usuario->id, 'first_name' => $nombrePila, 'last_name' => trim($apellidos) ?: $nombrePila]);

        return $usuario;
    }

    /**
     * La invitación de una cuenta ya creada (solo se guarda el hash del enlace). Devuelve la invitación y el enlace, que solo se conoce aquí.
     *
     * @return array{0: Invitacion, 1: string}
     */
    public function crearInvitacion(User $usuario, string $tipo, ?int $organizacionId, string $rolDelFront, ?CarbonInterface $ahora = null): array
    {
        $ahora = $this->calendario->ahora($ahora);
        $token = Str::random(48);
        $invitacion = Invitacion::create([
            'usuario_id' => $usuario->id, 'correo' => $usuario->email, 'rol_id' => $usuario->role_id, 'organizacion_tipo' => $tipo,
            'comedor_id' => $tipo === 'comedor' ? $organizacionId : null, 'empresa_id' => $tipo === 'empresa' ? $organizacionId : null,
            'token_hash' => hash('sha256', $token), 'enviada_en' => $ahora, 'vence_en' => $ahora->addDays((int) config('easylunch.vigencia_invitacion_dias')),
        ]);

        return [$invitacion, $token];
    }

    /** Manda el correo de una invitación (y deja el enlace en el log de desarrollo). Llámalo cuando la invitación ya quedó guardada. */
    public function enviar(Invitacion $invitacion, string $token): void
    {
        $invitacion->loadMissing(['usuario', 'rol']);
        $organizacion = $this->nombreDeLaOrganizacion($invitacion->organizacion_tipo, $invitacion->comedor_id ?? $invitacion->empresa_id);
        $this->enviarCorreo($invitacion, $invitacion->usuario->name, $organizacion, $token, RolesDeApi::front($invitacion->rol->name), $invitacion->organizacion_tipo);
    }

    /**
     * Renueva el enlace y la vigencia de una invitación que no se ha aceptado y vuelve a mandar el correo.
     *
     * @return array{0: Invitacion, 1: string}
     */
    public function reenviar(User $actor, int $invitacionId, ?CarbonInterface $ahora = null): array
    {
        $invitacion = Invitacion::query()->with(['usuario', 'rol'])->findOrFail($invitacionId);
        $this->autorizar($actor, $invitacion->organizacion_tipo, $invitacion->comedor_id ?? $invitacion->empresa_id);
        if ($invitacion->aceptada_en) {
            throw new ReglaDeNegocioException('invitacion_aceptada', 'Esta invitación ya fue aceptada.');
        }

        $ahora = $this->calendario->ahora($ahora);
        $token = Str::random(48);
        $invitacion->update(['token_hash' => hash('sha256', $token), 'enviada_en' => $ahora, 'vence_en' => $ahora->addDays((int) config('easylunch.vigencia_invitacion_dias'))]);
        Bitacora::registrar($actor, 'reenviar_invitacion', 'usuarios', $invitacion->usuario_id, "Reenvió la invitación a {$invitacion->correo}.");

        $rolDelFront = RolesDeApi::front($invitacion->rol->name);
        $this->enviarCorreo($invitacion->refresh(), $invitacion->usuario->name, $this->nombreDeLaOrganizacion($invitacion->organizacion_tipo, $invitacion->comedor_id ?? $invitacion->empresa_id), $token, $rolDelFront, $invitacion->organizacion_tipo);

        return [$invitacion, $token];
    }

    // ------------------------------------------------------------------ corregir el correo de quien aún no acepta

    /**
     * Revisa si se puede cambiar el correo de una persona (sin escribir nada). El correo es el acceso de la cuenta: solo se corrige
     * mientras la persona NO haya aceptado su invitación (típico: un correo mal escrito o armado por el sistema al subir un CSV).
     * Quien ya entró es dueño de su cuenta y no se le redirige a otro correo desde aquí.
     *
     * @return Invitacion|null la invitación pendiente que se va a reenviar; null si el correo es el mismo (no hay nada que cambiar)
     */
    public function validarCambioDeCorreo(User $usuario, string $correo): ?Invitacion
    {
        $correo = Str::lower(trim($correo));
        if ($correo === Str::lower($usuario->email)) {
            return null;
        }
        $invitacion = Invitacion::query()->where('usuario_id', $usuario->id)->first();
        if (! $invitacion || $invitacion->aceptada_en) {
            throw new ReglaDeNegocioException('correo_no_editable', 'Esa persona ya entró a su cuenta: su correo es su acceso y no se cambia aquí.');
        }
        if (User::query()->whereRaw('lower(email) = ?', [$correo])->where('id', '<>', $usuario->id)->exists()) {
            throw new ReglaDeNegocioException('correo_en_uso', 'Ese correo ya tiene una cuenta o una invitación.');
        }

        return $invitacion;
    }

    /** Cambia el correo (de la cuenta y de su invitación) y manda la invitación, con un enlace nuevo, al correo corregido. */
    public function aplicarCambioDeCorreo(User $actor, User $usuario, Invitacion $invitacion, string $correo): void
    {
        $correo = Str::lower(trim($correo));
        $anterior = $usuario->email;
        DB::transaction(function () use ($usuario, $invitacion, $correo) {
            $usuario->update(['email' => $correo]);
            $invitacion->update(['correo' => $correo]);
        });
        Bitacora::registrar($actor, 'corregir_correo', 'usuarios', $usuario->id, "Cambió el correo de {$usuario->name} de {$anterior} a {$correo} (aún no aceptaba su invitación).");

        $this->reenviar($actor, $invitacion->id);
    }

    // ------------------------------------------------------------------ el enlace del correo (público)

    /** Lo que se ve al abrir el enlace: de quién es, a qué organización y si todavía sirve. `invalida` si el enlace no existe. */
    public function mostrar(string $token, ?CarbonInterface $ahora = null): array
    {
        $invitacion = $this->porToken($token);
        if (! $invitacion) {
            return ['estado' => 'invalida', 'nombre_invitado' => '', 'correo' => '', 'organizacion_nombre' => '', 'organizacion_tipo' => 'plataforma', 'rol' => 'cliente', 'portal' => 'admin'];
        }
        $rol = RolesDeApi::front($invitacion->rol->name);

        return [
            'estado' => $this->estado($invitacion, $ahora), 'nombre_invitado' => $invitacion->usuario->name, 'correo' => $invitacion->correo,
            'organizacion_nombre' => $this->nombreDeLaOrganizacion($invitacion->organizacion_tipo, $invitacion->comedor_id ?? $invitacion->empresa_id),
            'organizacion_tipo' => $invitacion->organizacion_tipo, 'rol' => $rol, 'portal' => RolesDeApi::portal($rol) ?? 'admin',
        ];
    }

    /**
     * La persona acepta y elige su contraseña: la cuenta se activa y ya puede entrar por el portal de su rol.
     *
     * @return array{portal: string, rol: string, nombre: string, organizacion_nombre: string}
     */
    public function aceptar(string $token, string $contrasena, ?CarbonInterface $ahora = null): array
    {
        return DB::transaction(function () use ($token, $contrasena, $ahora) {
            $invitacion = Invitacion::query()->with(['usuario', 'rol'])->where('token_hash', hash('sha256', $token))->lockForUpdate()->first()
                ?? throw new ReglaDeNegocioException('invitacion_invalida', 'Esta invitación no es válida.');
            match ($this->estado($invitacion, $ahora)) {
                'aceptada' => throw new ReglaDeNegocioException('invitacion_aceptada', 'Esta invitación ya fue aceptada. Inicia sesión.'),
                'vencida' => throw new ReglaDeNegocioException('invitacion_vencida', 'Esta invitación venció. Pide que te la reenvíen.'),
                default => null,
            };

            $invitacion->usuario->update(['password' => $contrasena, 'status' => 'active']);
            $invitacion->update(['aceptada_en' => $this->calendario->ahora($ahora)]);
            $organizacion = $this->nombreDeLaOrganizacion($invitacion->organizacion_tipo, $invitacion->comedor_id ?? $invitacion->empresa_id);
            Bitacora::registrar($invitacion->usuario, 'aceptar_invitacion', 'usuarios', $invitacion->usuario_id, "Aceptó la invitación a {$organizacion}.");

            $rol = RolesDeApi::front($invitacion->rol->name);

            return ['portal' => RolesDeApi::portal($rol) ?? 'admin', 'rol' => $rol, 'nombre' => $invitacion->usuario->name, 'organizacion_nombre' => $organizacion];
        });
    }

    // ------------------------------------------------------------------ quién tiene acceso

    /**
     * La titular o el titular y las personas invitadas a una organización (los empleados no aparecen aquí: tienen su propia lista).
     *
     * @return array<int, array<string, mixed>>
     */
    public function accesoDe(User $actor, string $tipo, ?int $organizacionId, ?CarbonInterface $ahora = null): array
    {
        $this->autorizar($actor, $tipo, $organizacionId);

        $titulares = match ($tipo) {
            'comedor' => User::query()->whereKey(Provider::query()->whereKey($organizacionId)->value('user_id'))->get(),
            'empresa' => User::query()->whereKey(Empresa::query()->whereKey($organizacionId)->value('usuario_id'))->get(),
            default => User::query()->whereHas('role', fn ($q) => $q->whereIn('name', ['admin', 'support']))->whereNotIn('id', Invitacion::query()->select('usuario_id'))->orderBy('id')->get(),
        };
        $invitaciones = Invitacion::query()->with(['usuario', 'rol'])->where('organizacion_tipo', $tipo)
            ->when($tipo === 'comedor', fn ($q) => $q->where('comedor_id', $organizacionId))->when($tipo === 'empresa', fn ($q) => $q->where('empresa_id', $organizacionId))
            ->whereHas('rol', fn ($q) => $q->where('name', '<>', 'employee'))->orderByDesc('enviada_en')->orderByDesc('id')->get();

        // El contacto de una empresa o comedor dado de alta desde el panel también tiene una invitación (para crear su contraseña):
        // sigue siendo UNA sola fila, la del titular, con el estado de su invitación.
        $delTitular = $invitaciones->keyBy('usuario_id');
        $filas = $titulares->load('role')->map(fn (User $u) => $this->fila($u, $delTitular->get($u->id), true, $ahora));
        foreach ($invitaciones->reject(fn ($i) => $titulares->contains('id', $i->usuario_id)) as $i) {
            $filas->push($this->fila($i->usuario->load('role'), $i, false, $ahora));
        }

        return $filas->values()->all();
    }

    // ------------------------------------------------------------------ presentación y apoyo

    /** La invitación como la ve el front (sin el enlace, que no se guarda). */
    public function vista(Invitacion $i, ?CarbonInterface $ahora = null): array
    {
        $i->loadMissing('rol');

        return [
            'id' => $i->id, 'usuario_id' => $i->usuario_id, 'correo' => $i->correo, 'rol' => RolesDeApi::front($i->rol->name), 'organizacion_tipo' => $i->organizacion_tipo,
            'organizacion_id' => $i->comedor_id ?? $i->empresa_id, 'token' => '', 'enviada_en' => $i->enviada_en->toIso8601String(), 'vence_en' => $i->vence_en->toIso8601String(),
            'aceptada_en' => $i->aceptada_en?->toIso8601String(), 'estado' => $this->estado($i, $ahora),
        ];
    }

    /** @return array<string, mixed> */
    public function fila(User $u, ?Invitacion $i, bool $principal, ?CarbonInterface $ahora): array
    {
        return [
            'usuario_id' => $u->id, 'nombre' => $u->name, 'correo' => $u->email, 'rol' => RolesDeApi::front($u->role->name), 'activo' => $u->status === 'active',
            'es_principal' => $principal, 'invitacion' => $i ? $this->vista($i, $ahora) : null,
        ];
    }

    private function estado(Invitacion $i, ?CarbonInterface $ahora): string
    {
        return $i->aceptada_en ? 'aceptada' : ($i->vence_en->lte($this->calendario->ahora($ahora)) ? 'vencida' : 'enviada');
    }

    private function porToken(string $token): ?Invitacion
    {
        return Invitacion::query()->with(['usuario', 'rol'])->where('token_hash', hash('sha256', $token))->first();
    }

    private function nombreDeLaOrganizacion(string $tipo, ?int $id): string
    {
        return match ($tipo) {
            'comedor' => Provider::query()->find($id)?->commercial_name ?? Provider::query()->find($id)?->business_name ?? throw new ReglaDeNegocioException('organizacion_inexistente', 'Ese comedor no existe.'),
            'empresa' => Empresa::query()->find($id)?->nombre ?? throw new ReglaDeNegocioException('organizacion_inexistente', 'Esa empresa no existe.'),
            default => 'EasyLunch',
        };
    }

    private function enviarCorreo(Invitacion $invitacion, string $nombre, string $organizacion, string $token, string $rolDelFront, string $tipo): void
    {
        $url = $this->enlaces->invitacion($token, $rolDelFront, $tipo);
        Mail::to($invitacion->correo)->send(new InvitacionDeAcceso($nombre, $organizacion, $url, $invitacion->vence_en));
        $this->enlaces->mostrarEnDesarrollo('INVITACIÓN', $invitacion->correo, $url);
    }
}
