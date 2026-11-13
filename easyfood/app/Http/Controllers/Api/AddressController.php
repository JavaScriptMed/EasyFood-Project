<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AddressController extends Controller
{
    // Listar direcciones del usuario autenticado
    public function index(Request $request)
    {
        $addresses = $request->user()->addresses()->orderBy('is_default', 'desc')->get();
        return response()->json($addresses, 200);
    }

    // Registrar nueva dirección
    public function store(Request $request)
    {
        $validated = $request->validate([
            'label'                 => 'nullable|string|max:50',
            'street_address'        => 'required|string',
            'internal_number'       => 'nullable|string|max:20',
            'postal_code'           => 'nullable|string|max:10',
            'latitude'              => 'nullable|numeric',
            'longitude'             => 'nullable|numeric',
            'delivery_instructions' => 'nullable|string',
            'is_default'            => 'nullable|boolean',
        ]);

        $user = $request->user();

        // Si no se envían coordenadas, las calculamos con una búsqueda de texto flexible
        if (empty($validated['latitude']) || empty($validated['longitude'])) {
            $cpText = !empty($validated['postal_code']) ? "C.P. {$validated['postal_code']}" : "";
            $fullAddress = "{$validated['street_address']}, {$cpText}, Guadalajara, Jalisco, México";
            
            try {
                $response = Http::withUserAgent('EasyLunchApp')->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $fullAddress,
                    'format' => 'json',
                    'limit' => 1
                ]);

                if ($response->successful() && count($response->json()) > 0) {
                    $data = $response->json()[0];
                    $validated['latitude'] = $data['lat'];
                    $validated['longitude'] = $data['lon'];
                } else {
                    // Respaldo secundario buscando solo por calle y ciudad
                    $fallbackResponse = Http::withUserAgent('EasyLunchApp')->get('https://nominatim.openstreetmap.org/search', [
                        'q' => "{$validated['street_address']}, Guadalajara, Jalisco, México",
                        'format' => 'json',
                        'limit' => 1
                    ]);

                    if ($fallbackResponse->successful() && count($fallbackResponse->json()) > 0) {
                        $data = $fallbackResponse->json()[0];
                        $validated['latitude'] = $data['lat'];
                        $validated['longitude'] = $data['lon'];
                    } else {
                        // Coordenadas predeterminadas de respaldo final (ZMG)
                        $validated['latitude'] = 20.676667;
                        $validated['longitude'] = -103.347222;
                    }
                }
            } catch (\Exception $e) {
                $validated['latitude'] = 20.676667;
                $validated['longitude'] = -103.347222;
            }
        }

        // Si se marca como predeterminada, quitamos el flag a las demás
        if (!empty($validated['is_default']) && $validated['is_default']) {
            $user->addresses()->update(['is_default' => false]);
        } else {
            // Si es la primera dirección, la hacemos predeterminada por defecto
            if ($user->addresses()->count() === 0) {
                $validated['is_default'] = true;
            }
        }

        $address = $user->addresses()->create($validated);

        return response()->json([
            'message' => 'Dirección guardada exitosamente.',
            'address' => $address,
        ], 201);
    }

    // Actualizar dirección
    public function update(Request $request, string $id)
    {
        $address = $request->user()->addresses()->find($id);

        if (! $address) {
            return response()->json(['message' => 'Dirección no encontrada.'], 404);
        }

        $validated = $request->validate([
            'label'                 => 'sometimes|string|max:50',
            'street_address'        => 'sometimes|required|string',
            'internal_number'       => 'nullable|string|max:20',
            'postal_code'           => 'nullable|string|max:10',
            'latitude'              => 'sometimes|nullable|numeric',
            'longitude'             => 'sometimes|nullable|numeric',
            'delivery_instructions' => 'nullable|string',
            'is_default'            => 'nullable|boolean',
        ]);

        // Si se modifica la calle o código postal, recalculamos con la nueva búsqueda flexible
        if ((isset($validated['street_address']) || isset($validated['postal_code'])) && (empty($validated['latitude']) || empty($validated['longitude']))) {
            $street = $validated['street_address'] ?? $address->street_address;
            $cp = $validated['postal_code'] ?? $address->postal_code;
            $cpText = !empty($cp) ? "C.P. {$cp}" : "";
            $fullAddress = "{$street}, {$cpText}, Guadalajara, Jalisco, México";

            try {
                $response = Http::withUserAgent('EasyLunchApp')->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $fullAddress,
                    'format' => 'json',
                    'limit' => 1
                ]);

                if ($response->successful() && count($response->json()) > 0) {
                    $data = $response->json()[0];
                    $validated['latitude'] = $data['lat'];
                    $validated['longitude'] = $data['lon'];
                }
            } catch (\Exception $e) {
                // Si falla, conserva los valores anteriores
            }
        }

        if (isset($validated['is_default']) && $validated['is_default']) {
            $request->user()->addresses()->where('id', '<>', $id)->update(['is_default' => false]);
        }

        $address->update($validated);

        return response()->json([
            'message' => 'Dirección actualizada exitosamente.',
            'address' => $address,
        ], 200);
    }

    // Eliminar dirección
    public function destroy(Request $request, string $id)
    {
        $address = $request->user()->addresses()->find($id);

        if (! $address) {
            return response()->json(['message' => 'Dirección no encontrada.'], 404);
        }

        $address->delete();

        return response()->json(['message' => 'Dirección eliminada correctamente.'], 200);
    }
}