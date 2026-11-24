package com.example.easylunch.data.network

import com.example.easylunch.data.model.*
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.*

interface ApiService {

    @GET("v1/salud")
    suspend fun checkSalud(): Response<SaludResponse>

    // ---------- Autenticación (formato de error {message, errors}) ----------
    @POST("auth/login")
    suspend fun login(@Body request: LoginRequest): Response<AuthResponse>

    @POST("auth/register")
    suspend fun register(@Body request: RegisterRequest): Response<AuthResponse>

    @GET("auth/profile")
    suspend fun getProfile(): Response<AuthResponse>

    /** PUT multipart vía POST + `_method=PUT` (probado en la referencia). */
    @Multipart
    @POST("auth/profile")
    suspend fun updateProfile(
        @Part("_method") method: RequestBody,
        @Part("name") name: RequestBody?,
        @Part("first_name") firstName: RequestBody?,
        @Part("last_name") lastName: RequestBody?,
        @Part("phone") phone: RequestBody?,
        @Part avatar: MultipartBody.Part?
    ): Response<AuthResponse>

    @POST("auth/logout")
    suspend fun logout(): Response<MensajeResponse>

    // ---------- Cuentas (públicas) ----------
    @POST("v1/cuentas/restablecer/solicitar")
    suspend fun solicitarRestablecer(@Body body: SolicitarRestablecerRequest): Response<SolicitarRestablecerResponse>

    @GET("v1/cuentas/restablecer/{token}")
    suspend fun validarRestablecer(@Path("token") token: String): Response<ValidarRestablecerResponse>

    @POST("v1/cuentas/restablecer/{token}")
    suspend fun restablecer(@Path("token") token: String, @Body body: RestablecerRequest): Response<MensajeResponse>

    // ---------- Compartidos ----------
    @GET("v1/configuracion/operacion")
    suspend fun getConfiguracion(): Response<ConfiguracionOperacion>

    @GET("v1/paquetes")
    suspend fun getPaquetes(): Response<List<PaqueteDto>>

    @GET("v1/calificaciones/menu-semanal")
    suspend fun getCalificacionesMenuSemanal(): Response<CalificacionesMenuSemanal>

    /** Pendiente en el backend: hoy responde 404. */
    @GET("v1/zonas")
    suspend fun getZonas(): Response<List<ZonaDto>>

    // ---------- Cliente ----------
    @GET("v1/cliente/direcciones")
    suspend fun getDirecciones(): Response<List<DireccionDto>>

    @POST("v1/cliente/direcciones")
    suspend fun crearDireccion(@Body request: DireccionRequest): Response<DireccionDto>

    @PUT("v1/cliente/direcciones/{id}")
    suspend fun actualizarDireccion(@Path("id") id: Int, @Body request: DireccionRequest): Response<DireccionDto>

    @DELETE("v1/cliente/direcciones/{id}")
    suspend fun eliminarDireccion(@Path("id") id: Int): Response<MensajeResponse>

    @GET("v1/cliente/proxima-entrega")
    suspend fun getProximaEntrega(): Response<ProximaEntregaResponse>

    @GET("v1/cliente/menu-semanal")
    suspend fun getMenuSemanal(@Query("fecha_inicio") fechaInicio: String): Response<List<MenuSemanalDiaDto>>

    @GET("v1/cliente/pedidos")
    suspend fun getPedidos(): Response<List<PedidoDto>>

    @GET("v1/cliente/pedidos/{id}")
    suspend fun getPedido(@Path("id") id: Int): Response<PedidoDto>

    @POST("v1/cliente/pedidos")
    suspend fun crearPedido(@Body request: CrearPedidoRequest): Response<PedidoDto>

    @POST("v1/cliente/pedidos/{id}/pago")
    suspend fun iniciarPago(@Path("id") id: Int): Response<PagoResponse>

    @POST("v1/cliente/pedidos/{id}/calificaciones/entrega")
    suspend fun calificarEntregaCliente(@Path("id") id: Int, @Body request: CalificarEntregaRequest): Response<CalificacionResponse>

    @POST("v1/cliente/pedidos/{id}/calificaciones/platillo")
    suspend fun calificarPlatilloCliente(@Path("id") id: Int, @Body request: CalificarPlatilloClienteRequest): Response<CalificacionResponse>

    // ---------- Empleado ----------
    @GET("v1/empleado/menu")
    suspend fun getMenuEmpleado(
        @Query("fecha") fecha: String?,
        @Query("tiempo_comida") tiempoComida: String?
    ): Response<EmpleadoMenuDto>

    @PUT("v1/empleado/elecciones")
    suspend fun elegir(@Body request: EleccionRequest): Response<EleccionResponse>

    @HTTP(method = "DELETE", path = "v1/empleado/elecciones", hasBody = true)
    suspend fun quitarEleccion(@Body request: QuitarEleccionRequest): Response<MensajeResponse>

    @GET("v1/empleado/elecciones")
    suspend fun getHistorialElecciones(@Query("limite") limite: Int): Response<List<HistorialEleccionDto>>

    @GET("v1/empleado/calificaciones/pendientes")
    suspend fun getPendientesEmpleado(): Response<List<PendienteCalificacionDto>>

    @POST("v1/empleado/calificaciones/entrega")
    suspend fun calificarEntregaEmpleado(@Body request: CalificarEntregaRequest): Response<CalificacionResponse>

    @POST("v1/empleado/calificaciones/platillo")
    suspend fun calificarPlatilloEmpleado(@Body request: CalificarPlatilloEmpleadoRequest): Response<CalificacionResponse>

    // ---------- Repartidor ----------
    @GET("v1/repartidor/disponibilidad")
    suspend fun getDisponibilidad(): Response<DisponibilidadDto>

    @PATCH("v1/repartidor/disponibilidad")
    suspend fun setDisponibilidad(@Body request: DisponibilidadRequest): Response<DisponibilidadDto>

    @GET("v1/repartidor/rutas")
    suspend fun getRutas(@Query("fecha") fecha: String?): Response<List<RutaDto>>

    @POST("v1/repartidor/paradas/{id}/iniciar")
    suspend fun iniciarParada(@Path("id") id: Int): Response<AccionParadaResponse>

    @Multipart
    @POST("v1/repartidor/paradas/{id}/entregar")
    suspend fun entregarParada(@Path("id") id: Int, @Part foto: MultipartBody.Part): Response<AccionParadaResponse>

    @POST("v1/repartidor/paradas/{id}/no-entregada")
    suspend fun noEntregada(@Path("id") id: Int, @Body request: NoEntregadaRequest): Response<AccionParadaResponse>
}
