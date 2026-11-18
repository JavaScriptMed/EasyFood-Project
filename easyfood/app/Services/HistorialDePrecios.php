<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\ContratoComedor;
use App\Models\Menu;
use App\Models\PrecioDePlatillo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto valió, vale y valdrá cada platillo. `menus.price` es el precio de hoy; aquí se lleva la línea de tiempo:
 *  - un cambio de precio inmediato (al editar el platillo) cierra el tramo de hoy y abre otro desde hoy;
 *  - un precio programado entra en vigor a partir de la fecha elegida (mañana o después) y el comando diario lo copia a `menus.price`;
 *  - programar otro precio mientras hay uno pendiente lo reemplaza (el pendiente nunca llegó a aplicarse).
 */
class HistorialDePrecios
{
    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    /** @return Collection<int, PrecioDePlatillo> del más reciente al más antiguo, incluido el precio programado */
    public function de(Menu $platillo): Collection
    {
        $this->asegurarPunto($platillo);

        return PrecioDePlatillo::query()->where('menu_id', $platillo->id)->orderByDesc('vigente_desde')->get();
    }

    /** Lo llaman los endpoints que editan el platillo: si el precio de hoy cambió, deja constancia en la línea de tiempo. */
    public function sincronizar(Menu $platillo): void
    {
        $hoy = $this->hoy();
        $precio = (float) $platillo->price;
        if ($precio <= 0) {
            return;
        }

        DB::transaction(function () use ($platillo, $hoy, $precio) {
            $actual = $this->vigenteHoy($platillo, $hoy);
            if (! $actual) {
                $this->asegurarPunto($platillo);

                return;
            }
            if (abs((float) $actual->precio_venta - $precio) < 0.005) {
                return;
            }
            if ($actual->vigente_desde->toDateString() === $hoy->toDateString()) {
                $actual->update(['precio_venta' => $precio, 'costo' => $this->costoDe($platillo)]); // cambió dos veces el mismo día
                return;
            }

            $pendiente = $this->pendiente($platillo, $hoy);
            $actual->update(['vigente_hasta' => $hoy->subDay()->toDateString()]);
            PrecioDePlatillo::create([
                'menu_id' => $platillo->id, 'precio_venta' => $precio, 'costo' => $this->costoDe($platillo),
                'vigente_desde' => $hoy->toDateString(), 'vigente_hasta' => $pendiente?->vigente_desde->subDay()->toDateString(),
            ]);
        });
    }

    /**
     * Programa un precio nuevo a partir de una fecha futura. El precio de hoy queda vigente hasta el día anterior.
     */
    public function programar(User $actor, Menu $platillo, float $precio, string $vigenteDesde): PrecioDePlatillo
    {
        $hoy = $this->hoy();
        $desde = CarbonImmutable::parse($vigenteDesde);
        if ($desde->lte($hoy)) {
            throw new ReglaDeNegocioException('fecha_de_precio_invalida', 'El precio nuevo debe empezar a partir de mañana.');
        }
        if ($precio <= 0) {
            throw new ReglaDeNegocioException('precio_invalido', 'El precio debe ser mayor a cero.');
        }

        $registro = DB::transaction(function () use ($platillo, $precio, $desde, $hoy) {
            $this->asegurarPunto($platillo);
            $actual = $this->vigenteHoy($platillo, $hoy) ?? throw new ReglaDeNegocioException('sin_precio_vigente', 'El platillo no tiene un precio vigente.');
            if (abs((float) $actual->precio_venta - $precio) < 0.005) {
                throw new ReglaDeNegocioException('precio_sin_cambio', 'Ese ya es el precio vigente del platillo.');
            }

            $pendiente = $this->pendiente($platillo, $hoy);
            $actual->update(['vigente_hasta' => $desde->subDay()->toDateString()]);
            if ($pendiente) {
                $pendiente->update(['precio_venta' => $precio, 'costo' => $this->costoDe($platillo), 'vigente_desde' => $desde->toDateString()]);

                return $pendiente;
            }

            return PrecioDePlatillo::create([
                'menu_id' => $platillo->id, 'precio_venta' => $precio, 'costo' => $this->costoDe($platillo), 'vigente_desde' => $desde->toDateString(),
            ]);
        });

        Bitacora::registrar($actor, 'platillo.precio_programado', 'platillos', $platillo->id,
            "Programó el precio de «{$platillo->name}» en \${$registro->precio_venta} desde {$registro->vigente_desde->toDateString()}.");

        return $registro->refresh();
    }

    /** Copia a `menus.price` el precio que está en vigor hoy. Devuelve cuántos platillos cambiaron de precio. */
    public function aplicarVigentes(): int
    {
        $hoy = $this->hoy()->toDateString();

        $cambiados = DB::update(
            'UPDATE menus m SET price = p.precio_venta, updated_at = now()
             FROM precios_de_platillos p
             WHERE p.menu_id = m.id AND p.vigente_desde <= ? AND (p.vigente_hasta IS NULL OR p.vigente_hasta >= ?) AND m.price <> p.precio_venta',
            [$hoy, $hoy],
        );
        if ($cambiados > 0) {
            Bitacora::registrar(null, 'platillo.precios_aplicados', 'platillos', $hoy, "Entraron en vigor los precios programados de {$cambiados} platillo(s).");
        }

        return $cambiados;
    }

    // ------------------------------------------------------------------ apoyo

    private function hoy(): CarbonImmutable
    {
        return $this->calendario->ahora()->startOfDay();
    }

    private function vigenteHoy(Menu $platillo, CarbonImmutable $hoy): ?PrecioDePlatillo
    {
        return PrecioDePlatillo::query()->where('menu_id', $platillo->id)->whereDate('vigente_desde', '<=', $hoy->toDateString())
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhereDate('vigente_hasta', '>=', $hoy->toDateString()))
            ->orderByDesc('vigente_desde')->lockForUpdate()->first();
    }

    private function pendiente(Menu $platillo, CarbonImmutable $hoy): ?PrecioDePlatillo
    {
        return PrecioDePlatillo::query()->where('menu_id', $platillo->id)->whereDate('vigente_desde', '>', $hoy->toDateString())->orderBy('vigente_desde')->lockForUpdate()->first();
    }

    /**
     * Un platillo sin historial (creado después de la migración) arranca con su precio de hoy. Hay que llamarlo ANTES de editar el
     * precio, para que el tramo de partida lleve el precio anterior y no el nuevo.
     */
    public function asegurarPunto(Menu $platillo): void
    {
        if ((float) $platillo->price <= 0 || PrecioDePlatillo::query()->where('menu_id', $platillo->id)->exists()) {
            return;
        }
        $creado = $platillo->created_at?->toDateString();
        $hoy = $this->hoy()->toDateString();

        PrecioDePlatillo::create([
            'menu_id' => $platillo->id, 'precio_venta' => $platillo->price, 'costo' => $this->costoDe($platillo), 'vigente_desde' => $creado && $creado < $hoy ? $creado : $hoy,
        ]);
    }

    /** Lo que se le paga al comedor por platillo según su contrato vigente (0 si no tiene). */
    private function costoDe(Menu $platillo): float
    {
        return (float) (ContratoComedor::query()->activos()->where('comedor_id', $platillo->provider_id)->orderByDesc('vigencia_inicio')->value('precio_por_platillo') ?? 0);
    }
}
