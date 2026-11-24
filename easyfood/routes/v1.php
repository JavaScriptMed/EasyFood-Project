<?php

/*
|--------------------------------------------------------------------------
| API v1 de EasyLunch  (prefijo /api/v1, ver routes/api.php)
|--------------------------------------------------------------------------
| Rutas y nombres en español. Todas son JSON y las respuestas de error tienen la forma { mensaje, codigo?, errores? }.
| Autenticación: token Bearer de Sanctum (POST /api/auth/login). Cada grupo exige su rol (middleware `role:`):
|   admin · support · provider (comedor) · company (empresa) · employee (empleado) · customer (cliente).
|
| Todos los endpoints están construidos: RutasDeLaApiTest comprueba que toda ruta apunte a un controlador y método que existen.
*/

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\Cliente;
use App\Http\Controllers\Api\V1\Comedor;
use App\Http\Controllers\Api\V1\Cuentas;
use App\Http\Controllers\Api\V1\CalificacionMenuController;
use App\Http\Controllers\Api\V1\ContactoController;
use App\Http\Controllers\Api\V1\ZonaDisponibleController;
use App\Http\Controllers\Api\V1\Empleado;
use App\Http\Controllers\Api\V1\Empresa;
use App\Http\Controllers\Api\V1\Repartidor;
use App\Http\Controllers\Api\V1\MenuSemanalController;
use App\Http\Controllers\Api\V1\Webhooks;
use Illuminate\Support\Facades\Route;

// Los ids de la API son numéricos.
Route::pattern('id', '[0-9]+');

// ---------------------------------------------------------------------------------------------- público
Route::post('contacto', [ContactoController::class, 'store'])->middleware('throttle:10,1')->name('v1.contacto.store');
Route::get('zonas', [ZonaDisponibleController::class, 'index'])->middleware('throttle:60,1')->name('v1.zonas.disponibles');

// Stripe avisa aquí cuando se cobra un pedido. Público: se confía en la firma del webhook, no en un token.
Route::post('webhooks/stripe', [Webhooks\StripeController::class, 'recibir'])->middleware('throttle:300,1')->name('v1.webhooks.stripe');

// Cuentas (públicas): aceptar una invitación y restablecer la contraseña. Los enlaces llegan por correo (en desarrollo, MAIL_MAILER=log
// y además storage/logs/enlaces.log).
Route::prefix('cuentas')->middleware('throttle:10,1')->name('v1.cuentas.')->group(function () {
    Route::post('restablecer/solicitar', [Cuentas\RestablecimientoController::class, 'solicitar'])->middleware('throttle:recuperar')->name('restablecer.solicitar');   // solicitarRestablecimiento
    Route::get('restablecer/{token}', [Cuentas\RestablecimientoController::class, 'validar'])->name('restablecer.validar');           // validarTokenRestablecimiento
    Route::post('restablecer/{token}', [Cuentas\RestablecimientoController::class, 'aplicar'])->name('restablecer.aplicar');          // restablecerContrasena
    Route::get('invitaciones/{token}', [Cuentas\InvitacionController::class, 'mostrar'])->name('invitaciones.mostrar');               // obtenerInvitacionPublica
    Route::post('invitaciones/{token}/aceptar', [Cuentas\InvitacionController::class, 'aceptar'])->name('invitaciones.aceptar');      // aceptarInvitacion
});

Route::middleware('auth:sanctum')->group(function () {

    // De solo lectura y para cualquier persona con sesión: lo que el front necesita mostrar en todos los portales.
    Route::get('configuracion/operacion', [Admin\ConfiguracionController::class, 'show'])->name('v1.configuracion.operacion'); // hora de corte, días de entrega…
    Route::get('paquetes', [Cliente\PaqueteController::class, 'index'])->name('v1.paquetes.index');
    Route::get('calificaciones/menu-semanal', [CalificacionMenuController::class, 'show'])->name('v1.calificaciones.menu-semanal'); // promedio de estrellas del menú, en tiempo real

    // Invitar y reenviar: los usa el administrador, la empresa y el comedor, cada uno dentro de lo suyo.
    Route::middleware('role:admin,company,provider')->group(function () {
        Route::get('cuentas/usuarios-acceso', [Cuentas\UsuarioAccesoController::class, 'index'])->name('v1.cuentas.usuarios-acceso');              // listarUsuariosAcceso
        Route::put('cuentas/usuarios-acceso/{id}', [Cuentas\UsuarioAccesoController::class, 'update'])->name('v1.cuentas.usuarios-acceso.update');
        Route::patch('cuentas/usuarios-acceso/{id}/estado', [Cuentas\UsuarioAccesoController::class, 'estado'])->name('v1.cuentas.usuarios-acceso.estado');
        Route::post('cuentas/invitaciones/lote', [Cuentas\InvitacionController::class, 'lote'])->name('v1.cuentas.invitaciones.lote');                       // invitarVarios
        Route::post('cuentas/invitaciones', [Cuentas\InvitacionController::class, 'store'])->name('v1.cuentas.invitaciones.crear');               // invitarUsuario
        Route::post('cuentas/invitaciones/{id}/reenviar', [Cuentas\InvitacionController::class, 'reenviar'])->name('v1.cuentas.invitaciones.reenviar'); // reenviarInvitacion
    });

    // ------------------------------------------------------------------------------------------ administración
    Route::prefix('admin')->name('v1.admin.')->group(function () {

        Route::middleware('role:admin')->group(function () {
            Route::get('dashboard', [Admin\DashboardController::class, 'show'])->name('dashboard');
            Route::get('costos-operativos', [Admin\CostoOperativoController::class, 'index'])->name('costos-operativos.index');
            Route::post('costos-operativos', [Admin\CostoOperativoController::class, 'store'])->name('costos-operativos.store');
            Route::post('costos-operativos/{id}/anular', [Admin\CostoOperativoController::class, 'anular'])->name('costos-operativos.anular');

            Route::get('empresas', [Admin\EmpresaController::class, 'index'])->name('empresas.index');
            Route::get('empresas/{id}/empleados', [Admin\EmpresaController::class, 'empleados'])->name('empresas.empleados');
            Route::put('empresas/{id}', [Admin\EmpresaEdicionController::class, 'update'])->name('empresas.update');
            Route::patch('empresas/{id}/estado', [Admin\EmpresaEdicionController::class, 'estado'])->name('empresas.estado');
            Route::post('paquetes', [Admin\PaqueteController::class, 'store'])->name('paquetes.store');
            Route::put('paquetes/{id}', [Admin\PaqueteController::class, 'update'])->name('paquetes.update');
            Route::patch('paquetes/{id}/estado', [Admin\PaqueteController::class, 'estado'])->name('paquetes.estado');
            Route::get('empresas/{id}', [Admin\EmpresaController::class, 'show'])->name('empresas.show');
            Route::post('empresas', [Admin\EmpresaAltaController::class, 'store'])->name('empresas.store');
            Route::post('empresas/{id}/empleados', [Admin\EmpresaAltaController::class, 'empleados'])->name('empresas.empleados.store');
            Route::get('empresas/{id}/consumo', [Admin\EmpresaController::class, 'consumo'])->name('empresas.consumo');
            Route::post('empresas/{id}/renovar-contrato', [Admin\ContratoController::class, 'renovarEmpresa'])->name('empresas.renovar');
            Route::get('comedores', [Admin\ComedorController::class, 'index'])->name('comedores.index');
            Route::post('comedores', [Admin\ComedorAltaController::class, 'store'])->name('comedores.store');
            Route::put('comedores/{id}', [Admin\ComedorEdicionController::class, 'update'])->name('comedores.update');
            Route::patch('comedores/{id}/estado', [Admin\ComedorEdicionController::class, 'estado'])->name('comedores.estado');
            Route::get('comedores/{id}', [Admin\ComedorController::class, 'show'])->name('comedores.show');
            Route::post('comedores/{id}/renovar-contrato', [Admin\ContratoController::class, 'renovarComedor'])->name('comedores.renovar');
            Route::get('contratos/por-vencer', [Admin\ContratoController::class, 'porVencer'])->name('contratos.por-vencer');

            Route::get('platillos', [Admin\PlatilloController::class, 'index'])->name('platillos.index');
            Route::post('platillos', [Admin\PlatilloController::class, 'store'])->name('platillos.store');
            Route::put('platillos/{id}', [Admin\PlatilloController::class, 'update'])->name('platillos.update');
            Route::patch('platillos/{id}/estado', [Admin\PlatilloController::class, 'estado'])->name('platillos.estado');
            Route::post('platillos/{id}/revision', [Admin\PlatilloController::class, 'revision'])->name('platillos.revision');
            Route::get('platillos/{id}/historial-precios', [Admin\PlatilloExtrasController::class, 'historial'])->name('platillos.historial-precios');
            Route::post('platillos/{id}/precios', [Admin\PlatilloExtrasController::class, 'programarPrecio'])->name('platillos.programar-precio');
            Route::post('platillos/{id}/imagen', [Admin\PlatilloExtrasController::class, 'imagen'])->name('platillos.imagen');

            Route::get('configuracion', [Admin\ConfiguracionController::class, 'show'])->name('configuracion.show');
            Route::put('configuracion', [Admin\ConfiguracionController::class, 'update'])->name('configuracion.update');

            Route::get('calendario', [Admin\CalendarioController::class, 'show'])->name('calendario.show');
            Route::post('calendario/dias-sin-servicio', [Admin\CalendarioController::class, 'marcarDia'])->name('calendario.marcar-dia');
            Route::post('calendario/cierres-empresa', [Admin\CalendarioController::class, 'cerrarEmpresa'])->name('calendario.cerrar-empresa');
            Route::delete('calendario/dias-sin-servicio/{id}', [Admin\CalendarioController::class, 'quitarDia'])->name('calendario.quitar-dia');

            Route::get('cortes', [Admin\CorteController::class, 'index'])->name('cortes.index');
            Route::get('cortes/vista-previa', [Admin\CorteController::class, 'vistaPrevia'])->name('cortes.vista-previa');
            Route::post('cortes/ejecutar', [Admin\CorteController::class, 'ejecutar'])->name('cortes.ejecutar');
            Route::post('cortes/individuales/ejecutar', [Admin\CorteController::class, 'ejecutarIndividuales'])->name('cortes.individuales.ejecutar');

            Route::get('zonas', [Admin\ZonaController::class, 'index'])->name('zonas.index');
            Route::post('zonas', [Admin\ZonaController::class, 'store'])->name('zonas.store');
            Route::post('zonas/importar', [Admin\ZonaController::class, 'importar'])->name('zonas.importar');
            Route::get('zonas/{id}', [Admin\ZonaController::class, 'show'])->name('zonas.show');
            Route::put('zonas/{id}', [Admin\ZonaController::class, 'update'])->name('zonas.update');
            Route::patch('zonas/{id}', [Admin\ZonaController::class, 'renombrar'])->name('zonas.renombrar');
            Route::patch('zonas/{id}/estado', [Admin\ZonaController::class, 'estado'])->name('zonas.estado');

            Route::get('repartidores', [Admin\RepartidorController::class, 'index'])->name('repartidores.index');
            Route::post('repartidores', [Admin\RepartidorController::class, 'store'])->name('repartidores.store');
            Route::get('repartidores/capacidades', [Admin\RepartidorController::class, 'capacidades'])->name('repartidores.capacidades');
            Route::put('repartidores/capacidades', [Admin\RepartidorController::class, 'guardarCapacidades'])->name('repartidores.guardar-capacidades');
            Route::put('repartidores/{id}', [Admin\RepartidorController::class, 'update'])->name('repartidores.update');
            Route::patch('repartidores/{id}/estado', [Admin\RepartidorController::class, 'estado'])->name('repartidores.estado');
            Route::patch('repartidores/{id}/disponibilidad', [Admin\RepartidorController::class, 'disponibilidad'])->name('repartidores.disponibilidad');

            Route::get('rutas', [Admin\RutaController::class, 'index'])->name('rutas.index');
            Route::post('rutas/generar', [Admin\RutaController::class, 'generar'])->name('rutas.generar');
            Route::post('rutas/aprobar', [Admin\RutaController::class, 'aprobar'])->name('rutas.aprobar');
            Route::post('paradas/{id}/mover', [Admin\RutaController::class, 'moverParada'])->name('paradas.mover');
            Route::get('entregas', [Admin\EntregaController::class, 'index'])->name('entregas.index');
            Route::get('reportes/proveedor', [Admin\ReporteController::class, 'proveedor'])->name('reportes.proveedor');
            Route::get('menu-semanal', MenuSemanalController::class)->name('menu-semanal');

            Route::get('facturas', [Admin\FacturaController::class, 'index'])->name('facturas.index');
            Route::post('facturas/generar', [Admin\FacturaController::class, 'generar'])->name('facturas.generar');
            Route::get('facturas/{id}', [Admin\FacturaController::class, 'show'])->name('facturas.show');
            Route::post('facturas/{id}/emitir', [Admin\FacturaController::class, 'emitir'])->name('facturas.emitir');
            Route::post('facturas/{id}/pagar', [Admin\FacturaController::class, 'pagar'])->name('facturas.pagar');
            Route::get('liquidaciones', [Admin\LiquidacionController::class, 'index'])->name('liquidaciones.index');
            Route::post('liquidaciones/generar', [Admin\LiquidacionController::class, 'generar'])->name('liquidaciones.generar');
            Route::post('liquidaciones/{id}/pagar', [Admin\LiquidacionController::class, 'pagar'])->name('liquidaciones.pagar');

            Route::get('bitacora', [Admin\BitacoraController::class, 'index'])->name('bitacora.index');
            Route::get('bitacora/actores', [Admin\BitacoraController::class, 'actores'])->name('bitacora.actores');
            Route::get('auditoria/{entidad}/{entidadId}', [Admin\BitacoraController::class, 'entidad'])->name('auditoria.entidad');
        });

        // Soporte atiende clientes, pedidos, tickets e incidencias junto con el administrador.
        Route::middleware('role:admin,support')->group(function () {
            Route::get('incidencias', [Admin\IncidenciaController::class, 'index'])->name('incidencias.index');
            Route::post('incidencias/{id}/resolver', [Admin\IncidenciaController::class, 'resolver'])->name('incidencias.resolver');
            Route::get('incidencias/{id}/candidatos', [Admin\IncidenciaController::class, 'candidatos'])->name('incidencias.candidatos');
            Route::post('incidencias/{id}/reasignar', [Admin\IncidenciaController::class, 'reasignar'])->name('incidencias.reasignar');

            Route::get('clientes', [Admin\ClienteController::class, 'index'])->name('clientes.index');
            Route::get('clientes/{id}', [Admin\ClienteController::class, 'show'])->name('clientes.show');
            Route::get('pedidos', [Admin\PedidoController::class, 'index'])->name('pedidos.index');
            Route::post('pedidos/{id}/reembolso', [Admin\PedidoController::class, 'reembolsar'])->name('pedidos.reembolso');
            Route::get('tickets', [Admin\TicketController::class, 'index'])->name('tickets.index');
            Route::get('tickets/{id}', [Admin\TicketController::class, 'show'])->name('tickets.show');
            Route::post('tickets/{id}/mensajes', [Admin\TicketController::class, 'mensaje'])->name('tickets.mensaje');
            Route::patch('tickets/{id}/estado', [Admin\TicketController::class, 'estado'])->name('tickets.estado');
        });
    });

    // ------------------------------------------------------------------------------------------ portal de empresas
    Route::prefix('empresa')->name('v1.empresa.')->middleware('role:company')->group(function () {
        Route::get('perfil', [Empresa\PerfilController::class, 'show'])->name('perfil');
        Route::get('empleados', [Empresa\EmpleadoController::class, 'index'])->name('empleados.index');
        Route::post('empleados', [Empresa\EmpleadoAltaController::class, 'store'])->name('empleados.store');
        Route::put('empleados/{id}', [Empresa\EmpleadoController::class, 'update'])->name('empleados.update');
        Route::patch('empleados/{id}/estado', [Empresa\EmpleadoController::class, 'estado'])->name('empleados.estado');
        Route::get('pedidos-de-manana', [Empresa\PedidosMananaController::class, 'index'])->name('pedidos-de-manana');
        Route::get('indicadores', [Empresa\IndicadoresController::class, 'show'])->name('indicadores');
        Route::get('consumo', [Empresa\ConsumoController::class, 'index'])->name('consumo');
        Route::get('facturas', [Empresa\FacturaController::class, 'index'])->name('facturas.index');
        Route::get('facturas/vista-previa', [Empresa\FacturaController::class, 'vistaPrevia'])->name('facturas.vista-previa');
        Route::get('facturas/{id}', [Empresa\FacturaController::class, 'show'])->name('facturas.show');
    });

    // ------------------------------------------------------------------------------------------ portal de comedores
    Route::prefix('comedor')->name('v1.comedor.')->middleware('role:provider')->group(function () {
        Route::get('perfil', [Comedor\PerfilController::class, 'show'])->name('perfil');
        Route::get('platillos', [Comedor\PlatilloController::class, 'index'])->name('platillos.index');
        Route::post('platillos', [Comedor\PlatilloController::class, 'store'])->name('platillos.store');
        Route::put('platillos/{id}', [Comedor\PlatilloController::class, 'update'])->name('platillos.update');
        Route::patch('platillos/{id}/estado', [Comedor\PlatilloController::class, 'estado'])->name('platillos.estado');
        Route::post('platillos/{id}/imagen', [Comedor\PlatilloImagenController::class, 'store'])->name('platillos.imagen');
        Route::get('disponibilidad', [Comedor\DisponibilidadController::class, 'index'])->name('disponibilidad.index');
        Route::put('disponibilidad', [Comedor\DisponibilidadController::class, 'guardar'])->name('disponibilidad.guardar');
        Route::get('produccion', [Comedor\ProduccionController::class, 'index'])->name('produccion.index');
        Route::post('produccion/marcar-listas', [Comedor\ProduccionController::class, 'marcarListas'])->name('produccion.marcar-listas');
        Route::get('liquidaciones', [Comedor\LiquidacionController::class, 'index'])->name('liquidaciones.index');
    });

    // ------------------------------------------------------------------------------------------ API del repartidor (app móvil; solo el rol driver)
    Route::prefix('repartidor')->name('v1.repartidor.')->middleware('role:driver')->group(function () {
        Route::get('disponibilidad', [Repartidor\DisponibilidadController::class, 'show'])->name('disponibilidad.show');
        Route::patch('disponibilidad', [Repartidor\DisponibilidadController::class, 'update'])->name('disponibilidad.update');
        Route::get('rutas', [Repartidor\RutaController::class, 'index'])->name('rutas.index');
        Route::post('paradas/{id}/iniciar', [Repartidor\ParadaController::class, 'iniciar'])->name('paradas.iniciar');
        Route::post('paradas/{id}/entregar', [Repartidor\ParadaController::class, 'entregar'])->name('paradas.entregar');
        Route::post('paradas/{id}/no-entregada', [Repartidor\ParadaController::class, 'noEntregada'])->name('paradas.no-entregada');
    });

    // ------------------------------------------------------------------------------------------ portal de empleados
    Route::prefix('empleado')->name('v1.empleado.')->middleware('role:employee')->group(function () {
        Route::get('menu', [Empleado\MenuController::class, 'show'])->name('menu');
        Route::get('elecciones', [Empleado\EleccionController::class, 'index'])->name('elecciones.index');
        Route::put('elecciones', [Empleado\EleccionController::class, 'elegir'])->name('elecciones.elegir');
        Route::delete('elecciones', [Empleado\EleccionController::class, 'quitar'])->name('elecciones.quitar');
        Route::get('calificaciones/pendientes', [Empleado\CalificacionController::class, 'pendientes'])->name('calificaciones.pendientes');
        Route::post('calificaciones/entrega', [Empleado\CalificacionController::class, 'entrega'])->name('calificaciones.entrega');
        Route::post('calificaciones/platillo', [Empleado\CalificacionController::class, 'platillo'])->name('calificaciones.platillo');
    });

    // ------------------------------------------------------------------------------------------ app móvil (clientes)
    Route::prefix('cliente')->name('v1.cliente.')->middleware('role:customer')->group(function () {
        Route::get('paquetes', [Cliente\PaqueteController::class, 'index'])->name('paquetes.index');
        Route::get('menu-semanal', MenuSemanalController::class)->name('menu-semanal');
        Route::get('direcciones', [Cliente\DireccionController::class, 'index'])->name('direcciones.index');
        Route::post('direcciones', [Cliente\DireccionController::class, 'store'])->name('direcciones.store');
        Route::put('direcciones/{id}', [Cliente\DireccionController::class, 'update'])->name('direcciones.update');
        Route::delete('direcciones/{id}', [Cliente\DireccionController::class, 'destroy'])->name('direcciones.destroy');
        Route::get('proxima-entrega', [Cliente\PedidoController::class, 'proximaEntrega'])->name('proxima-entrega');
        Route::get('pedidos', [Cliente\PedidoController::class, 'index'])->name('pedidos.index');
        Route::post('pedidos', [Cliente\PedidoController::class, 'store'])->name('pedidos.store');
        Route::get('pedidos/{id}', [Cliente\PedidoController::class, 'show'])->name('pedidos.show');
        Route::post('pedidos/{id}/pago', [Cliente\PagoController::class, 'store'])->name('pedidos.pago');
        Route::post('pedidos/{id}/calificaciones/entrega', [Cliente\CalificacionController::class, 'entrega'])->name('pedidos.calificaciones.entrega');
        Route::post('pedidos/{id}/calificaciones/platillo', [Cliente\CalificacionController::class, 'platillo'])->name('pedidos.calificaciones.platillo');
    });
});
