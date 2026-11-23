<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Mail\RestablecerContrasena;
use App\Models\Restablecimiento;
use App\Models\User;
use App\Support\Api\RolesDeApi;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Restablecer la contraseña desde «olvidé mi contraseña».
 *
 * - Pedirlo responde siempre lo mismo, exista o no el correo, para no revelar qué cuentas existen.
 * - Solo las cuentas activas con portal web (administración, soporte, comedores, empresas y empleados) reciben el correo.
 * - El enlace vence a los 60 minutos y sirve una vez; pedir otro deja sin efecto los anteriores. Solo se guarda su hash.
 * - Al cambiar la contraseña se cierran todas las sesiones abiertas de esa cuenta.
 */
class RestablecimientoDeContrasena
{
    public function __construct(private readonly EnlacesDeCuenta $enlaces, private readonly CalendarioDeServicio $calendario) {}

    public function solicitar(string $correo, ?CarbonInterface $ahora = null): void
    {
        $usuario = User::query()->with('role')->whereRaw('lower(email) = ?', [Str::lower(trim($correo))])->where('status', 'active')->first();
        $portal = $usuario ? RolesDeApi::portal(RolesDeApi::front($usuario->role->name)) : null;
        if (! $usuario || ! $portal) {
            return;
        }

        $ahora = $this->calendario->ahora($ahora);
        $token = Str::random(48);
        DB::transaction(function () use ($usuario, $token, $ahora) {
            Restablecimiento::query()->where('usuario_id', $usuario->id)->whereNull('usado_en')->update(['usado_en' => $ahora]); // los enlaces anteriores dejan de servir
            Restablecimiento::create(['usuario_id' => $usuario->id, 'token_hash' => hash('sha256', $token), 'vence_en' => $ahora->addMinutes((int) config('easylunch.vigencia_restablecimiento_min'))]);
        });

        $url = $this->enlaces->restablecimiento($token, $portal);
        Mail::to($usuario->email)->send(new RestablecerContrasena($usuario->name, $url, (int) config('easylunch.vigencia_restablecimiento_min')));
        $this->enlaces->mostrarEnDesarrollo('RESTABLECER CONTRASEÑA', $usuario->email, $url);
    }

    /** ¿Sirve todavía el enlace? Si sí, da el correo con asteriscos para confirmarle a la persona de quién es. */
    public function validar(string $token, ?CarbonInterface $ahora = null): array
    {
        $registro = Restablecimiento::query()->with('usuario')->where('token_hash', hash('sha256', $token))->first();
        $motivo = match (true) {
            ! $registro || ! $registro->usuario => 'invalido',
            $registro->usado_en !== null => 'usado',
            $registro->vence_en->lte($this->calendario->ahora($ahora)) => 'vencido',
            default => null,
        };
        if ($motivo) {
            return ['valido' => false, 'motivo' => $motivo];
        }
        [$local, $dominio] = explode('@', $registro->usuario->email);

        return ['valido' => true, 'correo_oculto' => Str::substr($local, 0, 1).'***@'.$dominio];
    }

    /** Cambia la contraseña con un enlace válido; el enlace queda usado y se cierran las sesiones de esa cuenta. */
    public function aplicar(string $token, string $contrasena, ?CarbonInterface $ahora = null): void
    {
        DB::transaction(function () use ($token, $contrasena, $ahora) {
            $registro = Restablecimiento::query()->with('usuario')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $registro || ! $registro->usuario || $registro->usado_en !== null || $registro->vence_en->lte($this->calendario->ahora($ahora)) || $registro->usuario->status !== 'active') {
                throw new ReglaDeNegocioException('enlace_invalido', 'Este enlace ya no es válido. Solicita uno nuevo.');
            }

            $registro->usuario->update(['password' => $contrasena]);
            $registro->update(['usado_en' => $this->calendario->ahora($ahora)]);
            $registro->usuario->tokens()->delete(); // si alguien más tenía la sesión abierta, se cierra
            Bitacora::registrar($registro->usuario, 'restablecer_contrasena', 'usuarios', $registro->usuario_id, 'Restableció su contraseña.');
        });
    }
}
