<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Zona;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AddressController extends Controller
{
    // Listar direcciones del usuario autenticado con su respectiva zona
    public function index(Request $request)
    {
        $addresses = $request->user()->addresses()->with('zona')->orderBy('is_default', 'desc')->get();
        return response()->json($addresses, 200);
    }

    // Registrar nueva dirección dentro de la ZMG
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

        // 1. Si no se envían coordenadas, las calculamos enfocadas estrictamente en la ZMG
        if (empty($validated['latitude']) || empty($validated['longitude'])) {
            $cp = $validated['postal_code'] ?? '';
            $cpText = !empty($cp) ? "C.P. {$cp}" : "";
            // Forzamos el contexto de la ZMG (Zapopan, Guadalajara, Jalisco, México)
            $fullAddress = "{$validated['street_address']}, {$cpText}, Zapopan / Guadalajara, Jalisco, México";
            
            try {
                $response = Http::withUserAgent('EasyLunchApp')->get('https://nominatim.openstreetmap.org/search', [
                    'q'          => $fullAddress,
                    'postalcode' => $cp,
                    'city'       => 'Guadalajara', // O puedes dejarlo abierto a la ZMG
                    'country'    => 'México',
                    'format'     => 'json',
                    'limit'      => 1
                ]);

                if ($response->successful() && count($response->json()) > 0) {
                    $data = $response->json()[0];
                    $validated['latitude'] = $data['lat'];
                    $validated['longitude'] = $data['lon'];
                } else {
                    // Respaldo genérico en el centro de la ZMG
                    $validated['latitude'] = 20.676667;
                    $validated['longitude'] = -103.347222;
                }
            } catch (\Exception $e) {
                $validated['latitude'] = 20.676667;
                $validated['longitude'] = -103.347222;
            }
        }

        // 2. Validar mediante PostGIS si las coordenadas caen dentro de una Zona activa de EasyLunch en la ZMG
        $zona = Zona::whereRaw(
            "ST_Contains(geometria, ST_GeomFromText(?, 4326))", 
            ["POINT({$validated['longitude']} {$validated['latitude']})"]
        )->first();

        // Opcional: Si quieres bloquear direcciones fuera de cobertura, puedes descomentar la siguiente validación:
        /*
        if (!$zona) {
            return response()->json([
                'message' => 'Lo sentimos, por el momento EasyLunch solo tiene cobertura dentro de las zonas habilitadas en la Zona Metropolitana de Guadalajara.'
            ], 422);
        }
        */

        if ($zona) {
            $validated['zona_id'] = $zona->id;
        }

        // 3. Gestionar dirección predeterminada
        if (!empty($validated['is_default']) && $validated['is_default']) {
            $user->addresses()->update(['is_default' => false]);
        } else {
            if ($user->addresses()->count() === 0) {
                $validated['is_default'] = true;
            }
        }

        $address = $user->addresses()->create($validated);

        return response()->json([
            'message' => 'Dirección guardada exitosamente dentro de la ZMG.',
            'address' => $address->load('zona'),
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

        // Si cambia la calle o C.P., recalculamos
        if ((isset($validated['street_address']) || isset($validated['postal_code'])) && (empty($validated['latitude']) || empty($validated['longitude']))) {
            $street = $validated['street_address'] ?? $address->street_address;
            $cp = $validated['postal_code'] ?? $address->postal_code;
            $cpText = !empty($cp) ? "C.P. {$cp}" : "";
            $fullAddress = "{$street}, {$cpText}, Jalisco, México";

            try {
                $response = Http::withUserAgent('EasyLunchApp')->get('https://nominatim.openstreetmap.org/search', [
                    'q'          => $fullAddress,
                    'postalcode' => $cp,
                    'country'    => 'México',
                    'format'     => 'json',
                    'limit'      => 1
                ]);

                if ($response->successful() && count($response->json()) > 0) {
                    $data = $response->json()[0];
                    $validated['latitude'] = $data['lat'];
                    $validated['longitude'] = $data['lon'];
                }
            } catch (\Exception $e) {
                // Conserva valores previos
            }
        }

        // Recalcular zona si las coordenadas cambiaron
        $lat = $validated['latitude'] ?? $address->latitude;
        $lng = $validated['longitude'] ?? $address->longitude;
        
        if ($lat && $lng) {
            $zona = Zona::whereRaw(
                "ST_Contains(geometria, ST_GeomFromText(?, 4326))", 
                ["POINT({$lng} {$lat})"]
            )->first();

            if ($zona) {
                $validated['zona_id'] = $zona->id;
            }
        }

        if (isset($validated['is_default']) && $validated['is_default']) {
            $request->user()->addresses()->where('id', '<>', $id)->update(['is_default' => false]);
        }

        $address->update($validated);

        return response()->json([
            'message' => 'Dirección actualizada exitosamente.',
            'address' => $address->load('zona'),
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