<?php

namespace App\Http\Controllers\Api\V1\Empresa;

use App\Http\Controllers\Controller;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsumoController extends Controller
{
    /** Platillos elegidos por día de la propia empresa en un mes (AAAA-MM; por defecto el actual), para la gráfica. */
    public function index(Request $request): JsonResponse
    {
        $mes = $request->validate(['mes' => 'nullable|date_format:Y-m'])['mes'] ?? now()->format('Y-m');

        return response()->json(self::porDia(ContextoDelUsuario::empresa($request)->id, $mes));
    }

    /** @return array<int, array{fecha: string, platillos: int}> */
    public static function porDia(int $empresaId, string $mes): array
    {
        return DB::table('elecciones_empleado as e')->join('empleados as emp', 'emp.id', '=', 'e.empleado_id')
            ->where('emp.empresa_id', $empresaId)->whereRaw("to_char(e.fecha, 'YYYY-MM') = ?", [$mes])
            ->groupBy('e.fecha')->orderBy('e.fecha')->selectRaw('e.fecha, count(*) as platillos')->get()
            ->map(fn ($f) => ['fecha' => $f->fecha, 'platillos' => (int) $f->platillos])->all();
    }
}
