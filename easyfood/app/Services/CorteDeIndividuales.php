<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Corte;
use App\Models\OrdenProduccion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Corte de los clientes individuales: cierra los pedidos del sábado y deja lista su entrega. Corre el sábado a la hora de corte
 * (distinto del corte general, que cierra a empleados y empresas el día anterior). En una sola transacción:
 *  1. cancela los pedidos por pagar de ese sábado (ya no se pueden pagar) y libera sus porciones;
 *  2. suma a la orden de producción de cada comedor y platillo las porciones de los clientes (si ya había orden por las empresas,
 *     la actualiza y la deja «pendiente»; si no, la crea con la recolección a la hora de corte);
 *  3. arma las rutas de los clientes, separadas de las de empresas.
 *
 * Es idempotente: ejecutarlo otra vez para el mismo sábado no cambia nada y deja un registro «sin_cambios». Si algo falla, nada se
 * guarda salvo un registro «fallido», y el reintento automático sigue las mismas reglas que el corte general.
 */
class CorteDeIndividuales
{
    public function __construct(
        private readonly CalendarioDeServicio $calendario,
        private readonly ContratacionDePaquetes $paquetes,
        private readonly GeneradorDeRutas $rutas,
    ) {}

    /** @return Corte|null el corte ejecutado en esta llamada, o null si no tocaba (el scheduler lo llama cada minuto) */
    public function ejecutarSiCorresponde(?CarbonInterface $ahora = null): ?Corte
    {
        $ahora = $this->calendario->ahora($ahora);
        $hoy = $ahora->startOfDay();

        if (! $this->calendario->esDiaDeEntregaDeIndividuales($hoy)
            || $ahora->lt($this->calendario->momentoDeCierreDeIndividuales($hoy))
            || $this->calendario->corteDeIndividualesYaEjecutado($hoy)) {
            return null;
        }

        $fallidos = Corte::query()->whereDate('fecha_entrega', $hoy->toDateString())->where('tipo', 'individuales')->where('estado', 'fallido')->orderByDesc('ejecutado_en')->get();
        if ($fallidos->count() >= CorteAutomatico::INTENTOS_MAXIMOS) {
            return null;
        }
        if ($fallidos->isNotEmpty() && $fallidos->first()->ejecutado_en->gt($ahora->subMinutes(CorteAutomatico::REINTENTO_CADA_MINUTOS))) {
            return null;
        }

        return $this->ejecutar($hoy, null, $ahora);
    }

    /** @param  int|null  $usuarioId  null = lo lanza el sistema a la hora de corte; con valor, lo lanzó esa persona a mano (puede ser antes). */
    public function ejecutar(CarbonInterface|string $sabado, ?int $usuarioId = null, ?CarbonInterface $ahora = null): Corte
    {
        $dia = $this->calendario->dia($sabado);
        $ahora = $this->calendario->ahora($ahora);

        if (! $this->calendario->esDiaDeEntregaDeIndividuales($dia)) {
            throw new ReglaDeNegocioException('dia_sin_entrega_de_individuales', "El {$dia->toDateString()} no es un sábado de servicio: los clientes individuales solo reciben ese día.");
        }
        if ($dia->lt($ahora->startOfDay())) {
            throw new ReglaDeNegocioException('fecha_pasada', 'El corte de individuales solo puede ejecutarse para el sábado de hoy o uno futuro.');
        }
        if ($usuarioId === null && $ahora->lt($this->calendario->momentoDeCierreDeIndividuales($dia))) {
            throw new ReglaDeNegocioException('corte_prematuro', 'Aún no es la hora de corte de ese sábado.');
        }

        try {
            return DB::transaction(fn () => $this->cerrar($dia, $usuarioId, $ahora));
        } catch (Throwable $error) {
            report($error);

            return Corte::create([
                'tipo' => 'individuales', 'fecha_entrega' => $dia->toDateString(), 'ejecutado_en' => $ahora, 'ejecutado_por' => $usuarioId,
                'estado' => 'fallido', 'sin_cambios' => false, 'mensaje' => mb_substr('El corte de individuales falló y no cambió nada: '.$error->getMessage(), 0, 1000),
            ])->refresh();
        }
    }

    private function cerrar(CarbonImmutable $dia, ?int $usuarioId, CarbonImmutable $ahora): Corte
    {
        $fecha = $dia->toDateString();

        DB::select('select pg_advisory_xact_lock(hashtext(?))', ["corte-individuales:{$fecha}"]);

        if ($this->calendario->corteDeIndividualesYaEjecutado($dia)) {
            return Corte::create([
                'tipo' => 'individuales', 'fecha_entrega' => $fecha, 'ejecutado_en' => $ahora, 'ejecutado_por' => $usuarioId, 'estado' => 'correcto',
                'sin_cambios' => true, 'mensaje' => "El corte de individuales del {$fecha} ya se había ejecutado: no hubo cambios.",
            ])->refresh();
        }

        $this->paquetes->cancelarVencidos($ahora, $fecha);

        $corte = Corte::create([
            'tipo' => 'individuales', 'fecha_entrega' => $fecha, 'ejecutado_en' => $ahora, 'ejecutado_por' => $usuarioId, 'estado' => 'correcto', 'sin_cambios' => false,
        ]);

        $ordenes = $this->generarOrdenes($corte, $fecha);
        [$rutas, $sinRuta] = $this->rutas->generar($corte, $fecha, empresas: false, individuales: true);
        $advertencias = $sinRuta > 0
            ? ["{$sinRuta} entrega(s) a clientes quedaron sin ruta por falta de repartidores con capacidad (o por una dirección sin zona): hay una incidencia abierta por cada una."]
            : [];

        $corte->update([
            'ordenes_generadas' => $ordenes, 'rutas_creadas' => $rutas,
            'estado' => $advertencias ? 'con_advertencias' : 'correcto', 'mensaje' => $advertencias ? implode(' ', $advertencias) : null,
        ]);

        return $corte->refresh();
    }

    /** Suma las porciones de los clientes a la orden de cada comedor y platillo de ese sábado. Devuelve cuántas órdenes tocó. */
    private function generarOrdenes(Corte $corte, string $fecha): int
    {
        $filas = DB::table('pedido_dias as d')
            ->join('pedidos as p', 'p.id', '=', 'd.pedido_id')->join('pedido_dia_platillos as c', 'c.pedido_dia_id', '=', 'd.id')->join('menus as m', 'm.id', '=', 'c.menu_id')
            ->whereDate('d.fecha', $fecha)->where('d.estado', 'programada')->whereIn('p.estado', ['recibido', 'activo'])
            ->groupBy('c.menu_id', 'm.provider_id')->select('c.menu_id', 'm.provider_id', DB::raw('count(*) as cantidad'))->get();

        $recoleccion = $this->calendario->horaDeCorte().':00';
        foreach ($filas as $fila) {
            $orden = OrdenProduccion::query()->firstOrNew(['comedor_id' => $fila->provider_id, 'menu_id' => $fila->menu_id, 'fecha' => $fecha]);
            if ($orden->exists) {
                $orden->estado = 'pendiente'; // llegaron porciones nuevas: lo que estaba «lista» ya no está completo
            } else {
                $orden->fill(['corte_id' => $corte->id, 'porciones_empresas' => 0, 'hora_recoleccion' => $recoleccion]);
            }
            $orden->porciones_individuales = (int) $fila->cantidad;
            $orden->porciones_totales = (int) $orden->porciones_empresas + (int) $fila->cantidad;
            $orden->save();
        }

        return $filas->count();
    }
}
