<?php

namespace App\Http\Controllers\Api\V1\Cliente;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Address;
use App\Services\Geocodificador;
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
    public function store(Request $request, Geocodificador $geocodificador): JsonResponse
    {
        $datos = $this->conUbicacion($request->validate(self::reglas(crear: true)), $geocodificador);

        $direccion = DB::transaction(function () use ($request, $datos) {
            $nueva = Address::create(self::atributos($datos) + ['user_id' => $request->user()->id]);
            $this->unaPredeterminada($nueva, $datos['predeterminada'] ?? false);

            return $nueva;
        });

        return response()->json(Vistas::direccion($direccion->refresh()), 201);
    }

    public function update(Request $request, int $id, Geocodificador $geocodificador): JsonResponse
    {
        $direccion = Address::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $datos = $this->conUbicacion($request->validate(self::reglas(crear: false)), $geocodificador, $direccion);

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

    /**
     * Si la app no manda las coordenadas, se buscan con el texto de la dirección. Al editar solo se buscan cuando cambió
     * la calle o el código postal (si no, se conserva el punto que ya tenía).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function conUbicacion(array $datos, Geocodificador $geocodificador, ?Address $actual = null): array
    {
        $sinPunto = ! isset($datos['latitud']) && ! isset($datos['longitud']);
        $cambioElLugar = array_key_exists('direccion', $datos) || array_key_exists('codigo_postal', $datos);
        if (! $sinPunto || ! $cambioElLugar) {
            return $datos;
        }

        $punto = $geocodificador->ubicar($datos['direccion'] ?? $actual?->street_address ?? '', $datos['codigo_postal'] ?? $actual?->postal_code);
        if ($punto === null) {
            throw new ReglaDeNegocioException('direccion_no_encontrada', 'No pudimos ubicar esa dirección en el mapa: revisa la calle y el código postal, o marca el punto en el mapa.');
        }

        return $datos + $punto;
    }

    /** @return array<string, mixed> */
    private static function reglas(bool $crear): array
    {
        $r = $crear ? 'required' : 'sometimes';
        // Las coordenadas son opcionales: sin ellas la dirección se ubica por su texto (Geocodificador). Al crear van las dos o ninguna;
        // al editar se puede cambiar solo una.
        $punto = fn (string $otra, string $limites) => "nullable|numeric|{$limites}".($crear ? "|required_with:{$otra}" : '');

        return [
            'etiqueta' => 'sometimes|string|max:50', 'direccion' => "{$r}|string|max:500", 'numero_interior' => 'nullable|string|max:20',
            'codigo_postal' => 'nullable|string|max:10', 'latitud' => $punto('longitud', 'between:-90,90'), 'longitud' => $punto('latitud', 'between:-180,180'),
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
