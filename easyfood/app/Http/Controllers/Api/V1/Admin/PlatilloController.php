<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Menu;
use App\Services\Bitacora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatilloController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filtro = $request->validate([
            'comedor_id' => 'nullable|integer|exists:providers,id',
            'estado_revision' => 'nullable|in:borrador,en_revision,publicado,rechazado',
        ]);

        return response()->json(
            Menu::query()->with('provider')
                ->when($filtro['comedor_id'] ?? null, fn ($q, $v) => $q->where('provider_id', $v))
                ->when($filtro['estado_revision'] ?? null, fn ($q, $v) => $q->where('estado_revision', $v))
                ->orderBy('id')->get()->map(fn (Menu $m) => Vistas::platillo($m))->values()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate(self::reglas(crear: true) + ['comedor_id' => 'required|integer|exists:providers,id']);

        $platillo = Menu::create(self::atributos($datos) + ['provider_id' => $datos['comedor_id']]);
        Bitacora::registrar($request->user(), 'platillo.creado', 'platillos', $platillo->id, "Creó el platillo «{$platillo->name}».");

        return response()->json(Vistas::platillo($platillo->refresh()->load('provider')), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $platillo = Menu::query()->findOrFail($id);
        $datos = $request->validate(self::reglas(crear: false));

        $platillo->update(self::atributos($datos));

        return response()->json(Vistas::platillo($platillo->refresh()->load('provider')));
    }

    /** El administrador aprueba (publica) o rechaza (con motivo) un platillo que está en revisión. */
    public function revision(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'decision' => 'required|in:aprobar,rechazar',
            'motivo' => 'required_if:decision,rechazar|nullable|string|max:500',
        ]);
        $platillo = Menu::query()->findOrFail($id);

        if ($platillo->estado_revision !== 'en_revision') {
            throw new ReglaDeNegocioException('platillo_no_en_revision', 'Solo se puede aprobar o rechazar un platillo que está en revisión.');
        }

        $aprobar = $datos['decision'] === 'aprobar';
        $platillo->update(['estado_revision' => $aprobar ? 'publicado' : 'rechazado', 'motivo_rechazo' => $aprobar ? null : $datos['motivo']]);
        Bitacora::registrar($request->user(), $aprobar ? 'platillo.aprobado' : 'platillo.rechazado', 'platillos', $platillo->id,
            $aprobar ? "Aprobó «{$platillo->name}»." : "Rechazó «{$platillo->name}»: {$datos['motivo']}");

        return response()->json(Vistas::platillo($platillo->refresh()->load('provider')));
    }

    /**
     * Reglas compartidas con el portal del comedor. Quien captura solo manda a revisión: publicar o rechazar
     * es decisión del administrador (endpoint de revisión).
     *
     * @return array<string, string>
     */
    public static function reglas(bool $crear): array
    {
        $r = $crear ? 'required' : 'sometimes';

        return [
            'nombre' => "{$r}|string|max:150", 'descripcion' => 'nullable|string|max:2000', 'tiempo_comida' => "{$r}|in:desayuno,comida,cena",
            'calorias' => 'nullable|integer|min:0|max:10000', 'alergenos' => 'nullable|array', 'alergenos.*' => 'string|max:50',
            'etiquetas' => 'nullable|array', 'etiquetas.*' => 'string|max:50', 'precio_venta' => "{$r}|numeric|gt:0|max:99999999",
            'estado_revision' => 'sometimes|in:borrador,en_revision',
        ];
    }

    /** Traduce los nombres del front a las columnas de `menus`. */
    public static function atributos(array $datos): array
    {
        return array_filter([
            'name' => $datos['nombre'] ?? null, 'description' => $datos['descripcion'] ?? null, 'tiempo_comida' => $datos['tiempo_comida'] ?? null,
            'calorias' => $datos['calorias'] ?? null, 'alergenos' => $datos['alergenos'] ?? null, 'etiquetas' => $datos['etiquetas'] ?? null,
            'price' => $datos['precio_venta'] ?? null, 'estado_revision' => $datos['estado_revision'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
