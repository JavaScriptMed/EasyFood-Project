<?php

namespace App\Http\Controllers\Api\V1\Cliente;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DireccionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Address::query()->where('user_id', $request->user()->id)->orderByDesc('is_default')->orderBy('id')->get()->map(fn ($a) => Vistas::direccion($a))->values());
    }

    /**
     * Guarda una dirección con su punto en el mapa. La zona la calcula la base sola; si queda fuera de cobertura la
     * respuesta trae `sin_cobertura: true` y esa dirección no se podrá usar para pedir.
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate(self::reglas(crear: true));

        $direccion = DB::transaction(function () use ($request, $datos) {
            $nueva = Address::create(self::atributos($datos) + ['user_id' => $request->user()->id]);
            $this->unaPredeterminada($nueva, $datos['predeterminada'] ?? false);

            return $nueva;
        });

        return response()->json(Vistas::direccion($direccion->refresh()), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $direccion = Address::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $datos = $request->validate(self::reglas(crear: false));

        DB::transaction(function () use ($direccion, $datos) {
            $direccion->update(self::atributos($datos));
            $this->unaPredeterminada($direccion, $datos['predeterminada'] ?? false);
        });

        return response()->json(Vistas::direccion($direccion->refresh()));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $direccion = Address::query()->where('user_id', $request->user()->id)->findOrFail($id);

        if ($direccion->pedidos()->exists()) {
            throw new ReglaDeNegocioException('direccion_en_uso', 'Esa dirección tiene pedidos y no se puede eliminar. Puedes editarla o dejar de usarla.');
        }
        $direccion->delete();

        return response()->json(['mensaje' => 'Dirección eliminada.']);
    }

    /** @return array<string, mixed> */
    private static function reglas(bool $crear): array
    {
        $r = $crear ? 'required' : 'sometimes';

        return [
            'etiqueta' => 'sometimes|string|max:50', 'direccion' => "{$r}|string|max:500", 'numero_interior' => 'nullable|string|max:20',
            'codigo_postal' => 'nullable|string|max:10', 'latitud' => "{$r}|numeric|between:-90,90", 'longitud' => "{$r}|numeric|between:-180,180",
            'referencias' => 'nullable|string|max:500', 'predeterminada' => 'sometimes|boolean',
        ];
    }

    private static function atributos(array $d): array
    {
        $mapa = [
            'etiqueta' => 'label', 'direccion' => 'street_address', 'numero_interior' => 'internal_number', 'codigo_postal' => 'postal_code',
            'latitud' => 'latitude', 'longitud' => 'longitude', 'referencias' => 'delivery_instructions', 'predeterminada' => 'is_default',
        ];
        $atributos = [];
        foreach ($mapa as $campo => $columna) {
            if (array_key_exists($campo, $d)) {
                $atributos[$columna] = $d[$campo];
            }
        }

        return $atributos;
    }

    /** Solo una dirección predeterminada por cliente. */
    private function unaPredeterminada(Address $direccion, bool $esta): void
    {
        if ($esta) {
            Address::query()->where('user_id', $direccion->user_id)->where('id', '<>', $direccion->id)->update(['is_default' => false]);
        }
    }
}
