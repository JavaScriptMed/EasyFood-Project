<?php

/*
|--------------------------------------------------------------------------
| API v1 de EasyLunch  (prefijo /api/v1, ver routes/api.php)
|--------------------------------------------------------------------------
| Rutas y nombres en español. Todas son JSON y las respuestas de error tienen la forma { mensaje, codigo?, errores? }.
| Autenticación: token Bearer de Sanctum (POST /api/auth/login). Cada grupo exige su rol (middleware `role:`):
|   admin · support · provider (comedor) · company (empresa) · employee (empleado) · customer (cliente).
|
| Los endpoints marcados «PENDIENTE» ya tienen su ruta, su rol y su nombre, pero apuntan a NoImplementadoController
| (responden 501) porque todavía no se construyen: los más complejos, el dashboard y las imágenes.
*/

use App\Http\Controllers\Api\V1\Admin;
use App\Http\Controllers\Api\V1\Cliente;
use App\Http\Controllers\Api\V1\Comedor;
use App\Http\Controllers\Api\V1\ContactoController;
use App\Http\Controllers\Api\V1\Empleado;
use App\Http\Controllers\Api\V1\Empresa;
use App\Http\Controllers\Api\V1\NoImplementadoController as Pendiente;
use Illuminate\Support\Facades\Route;

// Los ids de la API son numéricos.
Route::pattern('id', '[0-9]+');

// ---------------------------------------------------------------------------------------------- público
Route::post('contacto', [ContactoController::class, 'store'])->middleware('throttle:10,1')->name('v1.contacto.store');

// Cuentas: invitaciones y restablecer contraseña (PENDIENTE: requieren correo y tokens).
Route::prefix('cuentas')->middleware('throttle:10,1')->name('v1.cuentas.')->group(function () {
    Route::post('restablecer/solicitar', Pendiente::class)->name('restablecer.solicitar');               // solicitarRestablecimiento
    Route::get('restablecer/{token}', Pendiente::class)->name('restablecer.validar');                     // validarTokenRestablecimiento
    Route::post('restablecer/{token}', Pendiente::class)->name('restablecer.aplicar');                    // restablecerContrasena
    Route::get('invitaciones/{token}', Pendiente::class)->name('invitaciones.mostrar');                   // obtenerInvitacionPublica
    Route::post('invitaciones/{token}/aceptar', Pendiente::class)->name('invitaciones.aceptar');          // aceptarInvitacion
});

Route::middleware('auth:sanctum')->group(function () {

    // Invitar y reenviar (PENDIENTE): los usa el administrador, la empresa y el comedor con su propio alcance.
    Route::middleware('role:admin,company,provider')->group(function () {
        Route::get('cuentas/usuarios-acceso', Pendiente::class)->name('v1.cuentas.usuarios-acceso');      // listarUsuariosAcceso
        Route::post('cuentas/invitaciones', Pendiente::class)->name('v1.cuentas.invitaciones.crear');     // invitarUsuario
        Route::post('cuentas/invitaciones/{id}/reenviar', Pendiente::class)->name('v1.cuentas.invitaciones.reenviar'); // reenviarInvitacion
    });

    // ------------------------------------------------------------------------------------------ administración
    Route::prefix('admin')->name('v1.admin.')->group(function () {

        Route::middleware('role:admin')->group(function () {
            Route::get('dashboard', Pendiente::class)->name('dashboard');                                  // PENDIENTE · obtenerDashboard

            Route::get('empresas', [Admin\EmpresaController::class, 'index'])->name('empresas.index');
            Route::get('empresas/{id}', [Admin\EmpresaController::class, 'show'])->name('empresas.show');
            Route::post('empresas', Pendiente::class)->name('empresas.store');                             // PENDIENTE · crearEmpresa (usuario + contrato + invitación)
            Route::post('empresas/{id}/renovar-contrato', Pendiente::class)->name('empresas.renovar');     // PENDIENTE · renovarContratoEmpresa
            Route::get('comedores', [Admin\ComedorController::class, 'index'])->name('comedores.index');
            Route::get('comedores/{id}', [Admin\ComedorController::class, 'show'])->name('comedores.show');
            Route::post('comedores/{id}/renovar-contrato', Pendiente::class)->name('comedores.renovar');   // PENDIENTE · renovarContratoComedor
            Route::get('contratos/por-vencer', [Admin\ContratoController::class, 'porVencer'])->name('contratos.por-vencer');

            Route::get('platillos', [Admin\PlatilloController::class, 'index'])->name('platillos.index');
            Route::post('platillos', [Admin\PlatilloController::class, 'store'])->name('platillos.store');
            Route::put('platillos/{id}', [Admin\PlatilloController::class, 'update'])->name('platillos.update');
            Route::post('platillos/{id}/revision', [Admin\PlatilloController::class, 'revision'])->name('platillos.revision');
            Route::get('platillos/{id}/historial-precios', Pendiente::class)->name('platillos.historial-precios'); // PENDIENTE · no hay tabla de historial
            Route::post('platillos/{id}/imagen', Pendiente::class)->name('platillos.imagen');              // PENDIENTE · imágenes

            Route::get('configuracion', [Admin\ConfiguracionController::class, 'show'])->name('configuracion.show');
            Route::put('configuracion', [Admin\ConfiguracionController::class, 'update'])->name('configuracion.update');

            Route::get('calendario', [Admin\CalendarioController::class, 'show'])->name('calendario.show');
            Route::post('calendario/dias-sin-servicio', [Admin\CalendarioController::class, 'marcarDia'])->name('calendario.marcar-dia');
            Route::post('calendario/cierres-empresa', [Admin\CalendarioController::class, 'cerrarEmpresa'])->name('calendario.cerrar-empresa');
            Route::delete('calendario/dias-sin-servicio/{id}', [Admin\CalendarioController::class, 'quitarDia'])->name('calendario.quitar-dia');

            Route::get('cortes', [Admin\CorteController::class, 'index'])->name('cortes.index');
            Route::get('cortes/vista-previa', Pendiente::class)->name('cortes.vista-previa');              // PENDIENTE · obtenerVistaPreviaCorte
            Route::post('cortes/ejecutar', [Admin\CorteController::class, 'ejecutar'])->name('cortes.ejecutar');

            Route::get('repartidores', [Admin\RepartidorController::class, 'index'])->name('repartidores.index');
            Route::post('repartidores', Pendiente::class)->name('repartidores.store');                     // PENDIENTE · crearRepartidor (usuario + invitación)
            Route::get('repartidores/capacidades', [Admin\RepartidorController::class, 'capacidades'])->name('repartidores.capacidades');
            Route::patch('repartidores/{id}/disponibilidad', [Admin\RepartidorController::class, 'disponibilidad'])->name('repartidores.disponibilidad');

            Route::get('rutas', [Admin\RutaController::class, 'index'])->name('rutas.index');
            Route::post('rutas/generar', Pendiente::class)->name('rutas.generar');                         // PENDIENTE · generarRutas
            Route::post('rutas/aprobar', [Admin\RutaController::class, 'aprobar'])->name('rutas.aprobar');
            Route::get('entregas', [Admin\EntregaController::class, 'index'])->name('entregas.index');
            Route::get('reportes/proveedor', Pendiente::class)->name('reportes.proveedor');                // PENDIENTE · reporteProveedor

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
            Route::get('incidencias/{id}/candidatos', Pendiente::class)->name('incidencias.candidatos');   // PENDIENTE · listarCandidatosReasignacion
            Route::post('incidencias/{id}/reasignar', Pendiente::class)->name('incidencias.reasignar');     // PENDIENTE · reasignarIncidencia

            Route::get('clientes', [Admin\ClienteController::class, 'index'])->name('clientes.index');
            Route::get('clientes/{id}', [Admin\ClienteController::class, 'show'])->name('clientes.show');
            Route::get('pedidos', [Admin\PedidoController::class, 'index'])->name('pedidos.index');
            Route::get('tickets', [Admin\TicketController::class, 'index'])->name('tickets.index');
            Route::get('tickets/{id}', [Admin\TicketController::class, 'show'])->name('tickets.show');
            Route::post('tickets/{id}/mensajes', [Admin\TicketController::class, 'mensaje'])->name('tickets.mensaje');
        });
    });

    // ------------------------------------------------------------------------------------------ portal de empresas
    Route::prefix('empresa')->name('v1.empresa.')->middleware('role:company')->group(function () {
        Route::get('empleados', [Empresa\EmpleadoController::class, 'index'])->name('empleados.index');
        Route::post('empleados', Pendiente::class)->name('empleados.store');                               // PENDIENTE · agregarEmpleados (usuarios + invitaciones)
        Route::patch('empleados/{id}/estado', [Empresa\EmpleadoController::class, 'estado'])->name('empleados.estado');
        Route::get('pedidos-de-manana', Pendiente::class)->name('pedidos-de-manana');                      // PENDIENTE · listarPedidosManana
        Route::get('consumo', Pendiente::class)->name('consumo');                                          // PENDIENTE · consumoDiarioEmpresa
        Route::get('facturas', [Empresa\FacturaController::class, 'index'])->name('facturas.index');
        Route::get('facturas/{id}', [Empresa\FacturaController::class, 'show'])->name('facturas.show');
    });

    // ------------------------------------------------------------------------------------------ portal de comedores
    Route::prefix('comedor')->name('v1.comedor.')->middleware('role:provider')->group(function () {
        Route::get('perfil', [Comedor\PerfilController::class, 'show'])->name('perfil');
        Route::get('platillos', [Comedor\PlatilloController::class, 'index'])->name('platillos.index');
        Route::post('platillos', [Comedor\PlatilloController::class, 'store'])->name('platillos.store');
        Route::put('platillos/{id}', [Comedor\PlatilloController::class, 'update'])->name('platillos.update');
        Route::post('platillos/{id}/imagen', Pendiente::class)->name('platillos.imagen');                  // PENDIENTE · imágenes
        Route::get('disponibilidad', [Comedor\DisponibilidadController::class, 'index'])->name('disponibilidad.index');
        Route::put('disponibilidad', [Comedor\DisponibilidadController::class, 'guardar'])->name('disponibilidad.guardar');
        Route::get('produccion', [Comedor\ProduccionController::class, 'index'])->name('produccion.index');
        Route::post('produccion/marcar-listas', [Comedor\ProduccionController::class, 'marcarListas'])->name('produccion.marcar-listas');
        Route::get('liquidaciones', [Comedor\LiquidacionController::class, 'index'])->name('liquidaciones.index');
    });

    // ------------------------------------------------------------------------------------------ portal de empleados
    Route::prefix('empleado')->name('v1.empleado.')->middleware('role:employee')->group(function () {
        Route::get('menu', [Empleado\MenuController::class, 'show'])->name('menu');
        Route::get('elecciones', [Empleado\EleccionController::class, 'index'])->name('elecciones.index');
        Route::put('elecciones', [Empleado\EleccionController::class, 'elegir'])->name('elecciones.elegir');
        Route::delete('elecciones', [Empleado\EleccionController::class, 'quitar'])->name('elecciones.quitar');
    });

    // ------------------------------------------------------------------------------------------ app móvil (clientes)
    Route::prefix('cliente')->name('v1.cliente.')->middleware('role:customer')->group(function () {
        Route::get('paquetes', [Cliente\PaqueteController::class, 'index'])->name('paquetes.index');
        Route::get('menu-semanal', Pendiente::class)->name('menu-semanal');                                // PENDIENTE · obtenerMenuSemanal
        Route::get('direcciones', [Cliente\DireccionController::class, 'index'])->name('direcciones.index');
        Route::post('direcciones', [Cliente\DireccionController::class, 'store'])->name('direcciones.store');
        Route::put('direcciones/{id}', [Cliente\DireccionController::class, 'update'])->name('direcciones.update');
        Route::delete('direcciones/{id}', [Cliente\DireccionController::class, 'destroy'])->name('direcciones.destroy');
        Route::get('pedidos', [Cliente\PedidoController::class, 'index'])->name('pedidos.index');
        Route::post('pedidos', [Cliente\PedidoController::class, 'store'])->name('pedidos.store');
        Route::get('pedidos/{id}', [Cliente\PedidoController::class, 'show'])->name('pedidos.show');
        Route::post('pedidos/{id}/pagar', [Cliente\PedidoController::class, 'pagar'])->name('pedidos.pagar');
    });
});
