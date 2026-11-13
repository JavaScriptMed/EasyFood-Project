<?php

namespace App\Services;

use App\Models\Aviso;
use App\Models\ContratoEmpresa;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Avisa a la empresa (su contacto de RR. HH.) y a los administradores cuando un contrato activo vence en el mes en curso
 * o en los 7 días siguientes a ese mes. Facturar un mes no se prorratea: se usa el contrato más reciente que lo toque,
 * así que conviene renovar antes de que venza.
 *
 * Es idempotente: hay un aviso por destinatario, contrato y fecha de vencimiento. Si se renueva el contrato (nueva fecha
 * de vencimiento) y vuelve a caer en la ventana, se avisa otra vez.
 */
class AvisosDeVencimiento
{
    /** Días después de terminar el mes en que todavía se avisa. */
    public const DIAS_DE_MARGEN = 7;

    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    /** @return int avisos nuevos creados */
    public function generar(?CarbonInterface $ahora = null): int
    {
        $ahora = $this->calendario->ahora($ahora);
        $desde = $ahora->startOfMonth()->toDateString();
        $hasta = $ahora->endOfMonth()->addDays(self::DIAS_DE_MARGEN)->toDateString();

        $administradores = User::query()->where('status', 'active')->whereHas('role', fn ($q) => $q->where('name', 'admin'))->pluck('id');
        $contratos = ContratoEmpresa::query()->activos()->with('empresa')
            ->whereDate('vigencia_fin', '>=', $desde)->whereDate('vigencia_fin', '<=', $hasta)
            ->whereHas('empresa', fn ($q) => $q->activos())
            ->get();

        $nuevos = 0;
        foreach ($contratos as $contrato) {
            $fin = $contrato->vigencia_fin->toDateString();
            $destinatarios = $administradores->concat([$contrato->empresa->usuario_id])->unique();
            $mensaje = "El contrato de {$contrato->empresa->nombre} vence el {$contrato->vigencia_fin->locale('es')->isoFormat('D [de] MMMM [de] YYYY')}: hay que renovarlo para no interrumpir el servicio.";

            foreach ($destinatarios as $usuarioId) {
                $aviso = Aviso::query()->firstOrCreate(
                    ['usuario_id' => $usuarioId, 'tipo' => 'contrato_por_vencer', 'contrato_empresa_id' => $contrato->id, 'vigencia_fin' => $fin],
                    ['mensaje' => $mensaje],
                );
                $nuevos += $aviso->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $nuevos;
    }
}
