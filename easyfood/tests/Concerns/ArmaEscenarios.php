<?php

namespace Tests\Concerns;

use App\Models\Configuracion;
use App\Models\ContratoEmpresa;
use App\Models\DisponibilidadDia;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Menu;
use App\Models\Provider;
use App\Models\Repartidor;
use Carbon\CarbonImmutable;

/** Datos mínimos válidos para probar las reglas de negocio con modelos. Requiere VerificaRestricciones (usuario()). */
trait ArmaEscenarios
{
    protected function ahoraEs(string $fechaHora): void
    {
        $this->travelTo(CarbonImmutable::parse($fechaHora, 'America/Mexico_City'));
    }

    protected function ajuste(string $clave, string $valor): void
    {
        Configuracion::findOrFail($clave)->update(['valor' => $valor]);
    }

    protected function comedorNuevo(): Provider
    {
        return Provider::create(['business_name' => 'Comedor '.uniqid(), 'phone' => '33 0000 0000', 'address' => 'Calle 1', 'zona' => 'Zapopan']);
    }

    protected function platillo(?Provider $comedor = null, string $tiempo = 'comida', array $extra = []): Menu
    {
        return Menu::create($extra + [
            'provider_id' => ($comedor ?? $this->comedorNuevo())->id, 'name' => 'Platillo '.uniqid(), 'price' => 49,
            'estado_revision' => 'publicado', 'tiempo_comida' => $tiempo, 'is_available' => true,
        ]);
    }

    protected function disponible(Menu $menu, string $fecha, int $maximo = 50, bool $delDia = false): DisponibilidadDia
    {
        return DisponibilidadDia::create(['menu_id' => $menu->id, 'fecha' => $fecha, 'porciones_maximas' => $maximo, 'es_platillo_del_dia' => $delDia]);
    }

    protected function empresaConContrato(string $zona = 'Zapopan', array $contrato = []): Empresa
    {
        $empresa = Empresa::create([
            'usuario_id' => $this->usuario(), 'nombre' => 'Empresa '.uniqid(), 'dominio' => 'ejemplo.mx', 'zona' => $zona, 'direccion_entrega' => 'Av. Industria 1450',
        ]);
        ContratoEmpresa::create($contrato + [
            'empresa_id' => $empresa->id, 'porcentaje_subsidio' => 60, 'compra_minima_mensual' => 10, 'precio_por_platillo' => 130,
            'vigencia_inicio' => '2026-01-01', 'vigencia_fin' => '2026-12-31', 'hora_entrega' => '12:40',
            'ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50',
        ]);

        return $empresa;
    }

    protected function empleadoDe(Empresa $empresa): Empleado
    {
        return Empleado::create(['usuario_id' => $this->usuario(), 'empresa_id' => $empresa->id]);
    }

    protected function repartidorEn(string $zona): Repartidor
    {
        return Repartidor::create(['usuario_id' => $this->usuario(), 'numero_empleado' => uniqid('R'), 'placas' => 'JAL-123-A', 'zona_base' => $zona]);
    }
}
