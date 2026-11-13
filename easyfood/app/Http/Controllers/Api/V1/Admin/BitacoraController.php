<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\RegistroAuditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BitacoraController extends Controller
{
    private const ROLES_INVERSO = [
        'administrador' => 'admin', 'soporte' => 'support', 'comedor' => 'provider', 'empresa' => 'company',
        'empleado' => 'employee', 'cliente' => 'customer', 'repartidor' => 'driver',
    ];

    /** Filtros: usuario_id («sistema» = acciones automáticas), rol, desde y hasta (AAAA-MM-DD), limite (200 por defecto). */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'usuario_id' => 'nullable|string|max:20', 'rol' => 'nullable|in:'.implode(',', array_keys(self::ROLES_INVERSO)),
            'desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d|after_or_equal:desde', 'limite' => 'nullable|integer|min:1|max:1000',
        ]);

        $registros = RegistroAuditoria::query()->with('usuario.role')
            ->when(($f['usuario_id'] ?? null) === 'sistema', fn ($q) => $q->whereNull('usuario_id'))
            ->when(isset($f['usuario_id']) && $f['usuario_id'] !== 'sistema', fn ($q) => $q->where('usuario_id', (int) $f['usuario_id']))
            ->when($f['rol'] ?? null, fn ($q, $rol) => $q->whereHas('usuario.role', fn ($r) => $r->where('name', self::ROLES_INVERSO[$rol])))
            ->when($f['desde'] ?? null, fn ($q, $v) => $q->where('creado_en', '>=', "{$v} 00:00:00"))
            ->when($f['hasta'] ?? null, fn ($q, $v) => $q->where('creado_en', '<', now()->parse($v)->addDay()->toDateString().' 00:00:00'))
            ->orderByDesc('creado_en')->orderByDesc('id')->limit((int) ($f['limite'] ?? 200))->get();

        return response()->json($registros->map(fn ($r) => Vistas::registroAuditoria($r))->values());
    }

    /** Quiénes aparecen en la bitácora (para el filtro): personas con su rol. */
    public function actores(): JsonResponse
    {
        $ids = DB::table('registros_auditoria')->whereNotNull('usuario_id')->distinct()->pluck('usuario_id');

        return response()->json(
            \App\Models\User::query()->with('role')->whereIn('id', $ids)->orderBy('name')->get()
                ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name, 'rol' => Vistas::rol($u->role?->name)])->values()
        );
    }

    /** Historial de una entidad concreta (p. ej. /auditoria/contratos_empresa/12). */
    public function entidad(string $entidad, string $entidadId): JsonResponse
    {
        return response()->json(
            RegistroAuditoria::query()->with('usuario.role')->where('entidad', $entidad)->where('entidad_id', $entidadId)
                ->orderByDesc('creado_en')->orderByDesc('id')->get()->map(fn ($r) => Vistas::registroAuditoria($r))->values()
        );
    }
}
