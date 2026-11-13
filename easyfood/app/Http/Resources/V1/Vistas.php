<?php

namespace App\Http\Resources\V1;

use App\Models\Address;
use App\Models\ContratoComedor;
use App\Models\ContratoEmpresa;
use App\Models\Corte;
use App\Models\DiaSinServicio;
use App\Models\DisponibilidadDia;
use App\Models\EleccionEmpleado;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\EntregaEmpresa;
use App\Models\FacturaMensual;
use App\Models\Incidencia;
use App\Models\LiquidacionComedor;
use App\Models\MensajeTicket;
use App\Models\Menu;
use App\Models\OrdenProduccion;
use App\Models\Paquete;
use App\Models\Parada;
use App\Models\Pedido;
use App\Models\Provider;
use App\Models\RegistroAuditoria;
use App\Models\Repartidor;
use App\Models\Ruta;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Cómo sale cada entidad por la API: los nombres de campo son los de /lib/types.ts del front (snake_case en español),
 * los montos van como números y las fechas como AAAA-MM-DD / ISO. Las tablas del equipo (providers, menus, addresses,
 * users) se traducen aquí, así el resto del código no tiene que conocer los nombres en inglés.
 */
final class Vistas
{
    private const ROLES = [
        'admin' => 'administrador', 'support' => 'soporte', 'provider' => 'comedor', 'company' => 'empresa',
        'employee' => 'empleado', 'customer' => 'cliente', 'driver' => 'repartidor',
    ];

    public static function rol(?string $nombre): ?string
    {
        return $nombre ? (self::ROLES[$nombre] ?? $nombre) : null;
    }

    public static function dinero(null|string|int|float $valor): float
    {
        return round((float) $valor, 2);
    }

    public static function hora(?string $hora): ?string
    {
        return $hora === null ? null : substr($hora, 0, 5);
    }

    public static function iso(?CarbonInterface $momento): ?string
    {
        return $momento?->toIso8601String();
    }

    /** @return array{estado: string, dias_para_vencer: int} */
    public static function vencimiento(CarbonInterface $vigenciaFin): array
    {
        $dias = (int) now()->startOfDay()->diffInDays($vigenciaFin->copy()->startOfDay(), false);

        return ['estado_contrato' => $dias < 0 ? 'vencido' : ($dias <= 30 ? 'por_vencer' : 'activo'), 'dias_para_vencer' => $dias];
    }

    // ------------------------------------------------------------------ personas

    public static function usuario(User $usuario): array
    {
        return [
            'id' => $usuario->id, 'nombre' => $usuario->name, 'correo' => $usuario->email,
            'telefono' => $usuario->profile?->phone, 'rol' => self::rol($usuario->role?->name), 'activo' => $usuario->status === 'active',
        ];
    }

    public static function usuarioResumen(?User $usuario): ?array
    {
        return $usuario ? ['id' => $usuario->id, 'nombre' => $usuario->name, 'correo' => $usuario->email, 'telefono' => $usuario->profile?->phone] : null;
    }

    // ------------------------------------------------------------------ empresas y comedores

    public static function contratoEmpresa(?ContratoEmpresa $c): ?array
    {
        return $c ? [
            'id' => $c->id, 'empresa_id' => $c->empresa_id, 'porcentaje_subsidio' => self::dinero($c->porcentaje_subsidio),
            'compra_minima_mensual' => $c->compra_minima_mensual, 'precio_por_platillo' => self::dinero($c->precio_por_platillo),
            'vigencia_inicio' => $c->vigencia_inicio->toDateString(), 'vigencia_fin' => $c->vigencia_fin->toDateString(),
            'hora_entrega' => self::hora($c->hora_entrega), 'ventana_entrega_inicio' => self::hora($c->ventana_entrega_inicio),
            'ventana_entrega_fin' => self::hora($c->ventana_entrega_fin), 'activo' => $c->activo,
        ] : null;
    }

    public static function contratoComedor(?ContratoComedor $c): ?array
    {
        return $c ? [
            'id' => $c->id, 'comedor_id' => $c->comedor_id, 'precio_por_platillo' => self::dinero($c->precio_por_platillo),
            'compra_minima_mensual' => $c->compra_minima_mensual, 'vigencia_inicio' => $c->vigencia_inicio->toDateString(),
            'vigencia_fin' => $c->vigencia_fin->toDateString(), 'activo' => $c->activo,
        ] : null;
    }

    public static function empresa(Empresa $e): array
    {
        return [
            'id' => $e->id, 'usuario_id' => $e->usuario_id, 'nombre' => $e->nombre, 'dominio' => $e->dominio,
            'zona' => $e->zona, 'direccion_entrega' => $e->direccion_entrega, 'activo' => $e->activo,
        ];
    }

    /** Requiere cargadas: usuario.profile y contratos. `total_empleados` y `platillos_mes` salen de withCount si están. */
    public static function empresaDetalle(Empresa $e): array
    {
        $contrato = $e->contratos->where('activo', true)->sortByDesc('vigencia_inicio')->first() ?? $e->contratos->sortByDesc('vigencia_inicio')->first();

        return self::empresa($e) + [
            'contacto' => self::usuarioResumen($e->usuario), 'contrato' => self::contratoEmpresa($contrato),
            'total_empleados' => (int) ($e->empleados_activos_count ?? 0), 'platillos_mes' => (int) ($e->platillos_mes ?? 0),
        ] + ($contrato ? self::vencimiento($contrato->vigencia_fin) : ['estado_contrato' => 'vencido', 'dias_para_vencer' => 0]);
    }

    public static function comedor(Provider $p): array
    {
        return [
            'id' => $p->id, 'usuario_id' => $p->user_id, 'nombre' => $p->commercial_name ?: $p->business_name, 'razon_social' => $p->business_name,
            'zona' => $p->zona, 'telefono' => $p->phone, 'correo' => $p->email, 'direccion' => $p->address, 'activo' => (bool) $p->is_active,
        ];
    }

    /** Requiere cargadas: user.profile y contratos. */
    public static function comedorDetalle(Provider $p, int $publicados = 0, int $entregadosMes = 0): array
    {
        $contrato = $p->contratos->where('activo', true)->sortByDesc('vigencia_inicio')->first() ?? $p->contratos->sortByDesc('vigencia_inicio')->first();
        $minimo = $contrato?->compra_minima_mensual ?: 0;

        return self::comedor($p) + [
            'contacto' => self::usuarioResumen($p->user), 'contrato' => self::contratoComedor($contrato),
            'platillos_publicados' => $publicados, 'platillos_entregados_mes' => $entregadosMes,
            'avance_minimo_porcentaje' => $minimo > 0 ? (int) round($entregadosMes / $minimo * 100) : 0,
        ] + ($contrato ? self::vencimiento($contrato->vigencia_fin) : ['estado_contrato' => 'vencido', 'dias_para_vencer' => 0]);
    }

    public static function empleado(Empleado $e): array
    {
        return [
            'id' => $e->id, 'usuario_id' => $e->usuario_id, 'empresa_id' => $e->empresa_id, 'departamento' => $e->departamento, 'activo' => $e->activo,
            'nombre' => $e->usuario?->name, 'correo' => $e->usuario?->email,
        ];
    }

    // ------------------------------------------------------------------ platillos y disponibilidad

    public static function platillo(Menu $m): array
    {
        return [
            'id' => $m->id, 'comedor_id' => $m->provider_id, 'comedor_nombre' => $m->provider?->commercial_name ?: $m->provider?->business_name,
            'nombre' => $m->name, 'descripcion' => (string) $m->description, 'tiempo_comida' => $m->tiempo_comida, 'calorias' => $m->calorias,
            'alergenos' => $m->alergenos ?? [], 'etiquetas' => $m->etiquetas ?? [], 'precio_venta' => self::dinero($m->price),
            'foto_url' => $m->image_url, 'estado_revision' => $m->estado_revision, 'motivo_rechazo' => $m->motivo_rechazo, 'activo' => (bool) $m->is_available,
        ];
    }

    public static function disponibilidad(DisponibilidadDia $d): array
    {
        return [
            'id' => $d->id, 'platillo_id' => $d->menu_id, 'platillo_nombre' => $d->relationLoaded('menu') ? $d->menu?->name : null, 'fecha' => $d->fecha->toDateString(), 'porciones_maximas' => $d->porciones_maximas,
            'porciones_tomadas' => $d->porciones_tomadas, 'porciones_restantes' => max(0, $d->porciones_maximas - $d->porciones_tomadas),
            'es_platillo_del_dia' => $d->es_platillo_del_dia,
        ];
    }

    public static function eleccion(EleccionEmpleado $e): array
    {
        return [
            'id' => $e->id, 'empleado_id' => $e->empleado_id, 'fecha' => $e->fecha->toDateString(), 'tiempo_comida' => $e->tiempo_comida,
            'platillo_id' => $e->menu_id, 'precio_congelado' => self::dinero($e->precio_congelado),
            'porcentaje_subsidio_congelado' => self::dinero($e->porcentaje_subsidio_congelado), 'origen' => $e->origen,
        ];
    }

    public static function ordenProduccion(OrdenProduccion $o): array
    {
        return [
            'id' => $o->id, 'comedor_id' => $o->comedor_id, 'platillo_id' => $o->menu_id, 'platillo_nombre' => $o->menu?->name,
            'tiempo_comida' => $o->menu?->tiempo_comida, 'fecha' => $o->fecha->toDateString(), 'porciones_totales' => $o->porciones_totales,
            'porciones_empresas' => $o->porciones_empresas, 'porciones_individuales' => $o->porciones_individuales,
            'hora_recoleccion' => self::hora($o->hora_recoleccion), 'estado' => $o->estado,
        ];
    }

    // ------------------------------------------------------------------ operación y reparto

    public static function corte(Corte $c): array
    {
        return [
            'id' => $c->id, 'fecha_entrega' => $c->fecha_entrega->toDateString(), 'ejecutado_en' => self::iso($c->ejecutado_en), 'ejecutado_por' => $c->ejecutado_por,
            'estado' => $c->estado, 'sin_cambios' => $c->sin_cambios, 'elecciones_cerradas' => $c->elecciones_cerradas,
            'empleados_por_defecto' => $c->empleados_por_defecto, 'ordenes_generadas' => $c->ordenes_generadas,
            'entregas_consolidadas' => $c->entregas_consolidadas, 'rutas_creadas' => $c->rutas_creadas, 'mensaje' => $c->mensaje,
        ];
    }

    public static function diaSinServicio(DiaSinServicio $d): array
    {
        return [
            'id' => $d->id, 'fecha' => $d->fecha->toDateString(), 'tipo' => $d->tipo, 'motivo' => $d->motivo, 'empresa_id' => $d->empresa_id,
            'empresa_nombre' => $d->empresa?->nombre,
        ];
    }

    public static function entrega(EntregaEmpresa $e): array
    {
        return [
            'id' => $e->id, 'empresa_id' => $e->empresa_id, 'empresa_nombre' => $e->empresa?->nombre, 'ruta_id' => $e->ruta_id, 'fecha' => $e->fecha->toDateString(),
            'zona' => $e->empresa?->zona, 'platillos' => $e->platillos, 'ventana_inicio' => self::hora($e->ventana_inicio), 'ventana_fin' => self::hora($e->ventana_fin),
            'estado' => $e->estado, 'tipo_vehiculo' => 'automovil',
        ];
    }

    public static function repartidor(Repartidor $r): array
    {
        return [
            'id' => $r->id, 'usuario_id' => $r->usuario_id, 'nombre' => $r->usuario?->name, 'numero_empleado' => $r->numero_empleado,
            'tipo_vehiculo' => 'automovil', 'placas' => $r->placas, 'zona_base' => $r->zona_base, 'estado' => $r->estado, 'activo' => $r->activo,
            'ultima_ubicacion_en' => self::iso($r->ultima_ubicacion_en),
        ];
    }

    public static function parada(Parada $p): array
    {
        return [
            'id' => $p->id, 'ruta_id' => $p->ruta_id, 'orden' => $p->orden, 'entrega_id' => $p->entrega_empresa_id, 'pedido_dia_id' => $p->pedido_dia_id,
            'nombre_destinatario' => $p->nombre_destinatario, 'direccion' => $p->direccion, 'hora_estimada' => self::hora($p->hora_estimada), 'estado' => $p->estado,
        ];
    }

    public static function ruta(Ruta $r, bool $conParadas = false): array
    {
        $datos = [
            'id' => $r->id, 'fecha' => $r->fecha->toDateString(), 'repartidor_id' => $r->repartidor_id, 'repartidor_nombre' => $r->repartidor?->usuario?->name,
            'tipo_vehiculo' => 'automovil', 'zona' => $r->zona, 'ventana_inicio' => self::hora($r->ventana_inicio), 'ventana_fin' => self::hora($r->ventana_fin),
            'capacidad_entregas' => $r->capacidad_entregas, 'capacidad_platillos' => $r->capacidad_platillos, 'entregas_asignadas' => $r->entregas_asignadas,
            'platillos_asignados' => $r->platillos_asignados, 'distancia_km' => $r->distancia_km === null ? null : self::dinero($r->distancia_km),
            'hora_fin_estimada' => self::hora($r->hora_fin_estimada), 'excede_bloque' => $r->excede_bloque, 'estado' => $r->estado,
        ];

        return $conParadas ? $datos + ['paradas' => $r->paradas->sortBy('orden')->map(fn (Parada $p) => self::parada($p))->values()->all()] : $datos;
    }

    public static function incidencia(Incidencia $i): array
    {
        return [
            'id' => $i->id, 'fecha' => $i->fecha->toDateString(), 'tipo' => $i->tipo, 'descripcion' => $i->descripcion, 'prioridad' => $i->prioridad,
            'estado' => $i->estado, 'hora' => self::hora($i->hora), 'foto_url' => $i->foto_url, 'nota' => $i->nota,
            'repartidor_reasignado_id' => $i->repartidor_reasignado_id, 'ruta_id' => $i->ruta_id, 'entrega_id' => $i->entrega_empresa_id, 'pedido_dia_id' => $i->pedido_dia_id,
            'ruta_zona' => $i->ruta?->zona, 'repartidor_nombre' => $i->ruta?->repartidor?->usuario?->name,
        ];
    }

    // ------------------------------------------------------------------ dinero

    public static function factura(FacturaMensual $f): array
    {
        return [
            'id' => $f->id, 'folio' => $f->folio, 'empresa_id' => $f->empresa_id, 'mes' => $f->mes->format('Y-m'), 'platillos' => $f->platillos,
            'parte_empresa' => self::dinero($f->parte_empresa), 'parte_empleados' => self::dinero($f->parte_empleados),
            'platillos_faltantes' => $f->platillos_faltantes, 'diferencia_minimo' => self::dinero($f->diferencia_minimo), 'total' => self::dinero($f->total),
            'estado' => $f->estado, 'emitida_en' => self::iso($f->emitida_en), 'pagada_en' => self::iso($f->pagada_en),
        ];
    }

    public static function liquidacion(LiquidacionComedor $l): array
    {
        return [
            'id' => $l->id, 'comedor_id' => $l->comedor_id, 'mes' => $l->mes->format('Y-m'), 'platillos_entregados' => $l->platillos_entregados,
            'precio_por_platillo' => self::dinero($l->precio_por_platillo), 'platillos_minimos' => $l->platillos_minimos,
            'platillos_faltantes' => $l->platillos_faltantes, 'diferencia_minimo' => self::dinero($l->diferencia_minimo), 'monto' => self::dinero($l->monto),
            'estado' => $l->estado, 'pagada_en' => self::iso($l->pagada_en),
        ];
    }

    // ------------------------------------------------------------------ clientes individuales

    public static function paquete(Paquete $p): array
    {
        return [
            'id' => $p->id, 'nombre' => $p->nombre, 'dias' => $p->dias, 'descuento_porcentaje' => self::dinero($p->descuento_porcentaje),
            'detalle' => (string) $p->detalle, 'destacado' => $p->destacado,
        ];
    }

    public static function direccion(Address $a): array
    {
        return [
            'id' => $a->id, 'etiqueta' => $a->label, 'direccion' => $a->street_address, 'numero_interior' => $a->internal_number, 'codigo_postal' => $a->postal_code,
            'latitud' => $a->latitude, 'longitud' => $a->longitude, 'referencias' => $a->delivery_instructions, 'predeterminada' => $a->is_default,
            'zona' => $a->zona, 'sin_cobertura' => (bool) $a->sin_cobertura,
        ];
    }

    public static function pedido(Pedido $p): array
    {
        $dias = $p->relationLoaded('dias') ? $p->dias : collect();

        return [
            'id' => $p->id, 'folio' => $p->folio, 'cliente_id' => $p->cliente_id, 'cliente_nombre' => $p->cliente?->name, 'plan_id' => $p->paquete_id,
            'plan_nombre' => $p->paquete?->nombre, 'fecha_inicio' => $p->fecha_inicio->toDateString(), 'fecha_fin' => $p->fecha_fin->toDateString(),
            'zona' => $p->direccion?->zona, 'total' => self::dinero($p->total), 'estado' => $p->estado,
            'entregas_totales' => $dias->count(), 'entregas_completadas' => $dias->where('estado', 'entregada')->count(),
            'direccion_entrega' => $p->direccion?->street_address, 'referencias' => $p->direccion?->delivery_instructions,
            'metodo_pago' => $p->metodo_pago, 'ultimos_digitos' => $p->ultimos_digitos, 'tiene_evidencia' => $dias->contains(fn ($d) => $d->evidencia_foto_url !== null),
            'vence_pago_en' => self::iso($p->vence_pago_en), 'segundos_para_pagar' => $p->segundos_para_pagar,
            'dias' => $dias->map(fn ($d) => [
                'id' => $d->id, 'fecha' => $d->fecha->toDateString(), 'platillo_id' => $d->menu_id, 'platillo_nombre' => $d->menu?->name,
                'precio_congelado' => self::dinero($d->precio_congelado), 'estado' => $d->estado,
            ])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ soporte y bitácora

    public static function ticket(Ticket $t): array
    {
        return [
            'id' => $t->id, 'folio' => $t->folio, 'cliente_id' => $t->cliente_id, 'cliente_nombre' => $t->cliente?->name, 'asunto' => $t->asunto,
            'pedido_folio' => $t->pedido?->folio, 'estado' => $t->estado, 'mensajes_sin_leer' => $t->mensajes_sin_leer, 'actualizado_en' => self::iso($t->actualizado_en),
        ];
    }

    public static function mensajeTicket(MensajeTicket $m): array
    {
        return ['id' => $m->id, 'ticket_id' => $m->ticket_id, 'autor' => $m->autor_tipo, 'autor_id' => $m->autor_id, 'texto' => $m->texto, 'enviado_en' => self::iso($m->enviado_en)];
    }

    public static function registroAuditoria(RegistroAuditoria $r): array
    {
        return [
            'id' => $r->id, 'usuario_id' => $r->usuario_id, 'usuario_nombre' => $r->usuario?->name, 'usuario_rol' => self::rol($r->usuario?->role?->name),
            'accion' => $r->accion, 'entidad' => $r->entidad, 'entidad_id' => $r->entidad_id, 'detalle' => $r->detalle, 'creado_en' => self::iso($r->creado_en),
        ];
    }
}
