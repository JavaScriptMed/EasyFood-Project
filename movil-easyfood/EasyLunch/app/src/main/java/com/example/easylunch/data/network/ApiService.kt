package com.example.easylunch.data.network

import com.example.easylunch.data.model.AddressDto
import com.example.easylunch.data.model.AddressRequestDto
import com.example.easylunch.data.model.AuthResponse
import com.example.easylunch.data.model.CalificarEntregaRequest
import com.example.easylunch.data.model.CalificarPlatilloRequest
import com.example.easylunch.data.model.CreateOrderRequest
import com.example.easylunch.data.model.LoginRequest
import com.example.easylunch.data.model.PaginatedCategoriesResponse
import com.example.easylunch.data.model.PaginatedMenusResponse
import com.example.easylunch.data.model.PaqueteDto
import com.example.easylunch.data.model.PayOrderRequest
import com.example.easylunch.data.model.PedidoDto
import com.example.easylunch.data.model.ProximaEntregaResponse
import com.example.easylunch.data.model.RegisterRequest
import com.example.easylunch.data.model.SaludResponse
import com.example.easylunch.data.model.StripePaymentIntentResponse
import com.example.easylunch.data.model.UpdateProfileRequest
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

interface ApiService {

    @GET("v1/salud")
    suspend fun checkSalud(): Response<SaludResponse>

    @POST("auth/login")
    suspend fun login(@Body request: LoginRequest): Response<AuthResponse>

    @POST("auth/register")
    suspend fun register(@Body request: RegisterRequest): Response<AuthResponse>

    @GET("auth/profile")
    suspend fun getProfile(): Response<AuthResponse>

    // Obtener categorías de catálogo activas
    @GET("catalog-categories")
    suspend fun getCategories(
        @Query("is_active") isActive: Boolean? = true
    ): Response<PaginatedCategoriesResponse>

    // Obtener listado de platillos/menús con filtros
    @GET("menus")
    suspend fun getMenus(
        @Query("category_id") categoryId: Int? = null,
        @Query("search") search: String? = null,
        @Query("is_available") isAvailable: Boolean? = true
    ): Response<PaginatedMenusResponse>

    // Rutas de Direcciones en API v1
    @GET("v1/cliente/direcciones")
    suspend fun getAddresses(): Response<List<AddressDto>>

    @POST("v1/cliente/direcciones")
    suspend fun createAddress(@Body request: AddressRequestDto): Response<AddressDto>

    @PUT("v1/cliente/direcciones/{id}")
    suspend fun updateAddress(
        @Path("id") id: Int,
        @Body request: AddressRequestDto
    ): Response<AddressDto>

    @DELETE("v1/cliente/direcciones/{id}")
    suspend fun deleteAddress(@Path("id") id: Int): Response<Unit>

    @Multipart
    @PUT("auth/profile")
    suspend fun updateProfile(
        @Part("name") name: RequestBody?,
        @Part("first_name") firstName: RequestBody?,
        @Part("last_name") lastName: RequestBody?,
        @Part("phone") phone: RequestBody?,
        @Part avatar: MultipartBody.Part?
    ): Response<AuthResponse>

    // Rutas de Paquetes, Calendario y Pedidos en API v1
    @GET("v1/cliente/paquetes")
    suspend fun getPaquetes(): Response<List<PaqueteDto>>

    @GET("v1/cliente/menu-semanal")
    suspend fun getMenuSemanal(
        @Query("fecha_inicio") fechaInicio: String? = null
    ): Response<Any>

    @GET("v1/cliente/proxima-entrega")
    suspend fun getProximaEntrega(): Response<ProximaEntregaResponse>

    @GET("v1/cliente/pedidos")
    suspend fun getPedidos(): Response<List<PedidoDto>>

    @GET("v1/cliente/pedidos/{id}")
    suspend fun getPedido(@Path("id") id: Int): Response<PedidoDto>

    @POST("v1/cliente/pedidos")
    suspend fun crearPedido(@Body request: CreateOrderRequest): Response<PedidoDto>

    // Inicia el PaymentIntent de Stripe (el webhook confirma y pasa el pedido a "recibido")
    @POST("v1/cliente/pedidos/{id}/pago")
    suspend fun iniciarPagoStripe(@Path("id") id: Int): Response<StripePaymentIntentResponse>

    // Mantenido por compatibilidad interna si en algún lugar se invoca con cuerpo
    @POST("v1/cliente/pedidos/{id}/pago")
    suspend fun pagarPedido(
        @Path("id") id: Int,
        @Body request: PayOrderRequest? = null
    ): Response<StripePaymentIntentResponse>

    // Calificaciones del cliente
    @POST("v1/cliente/pedidos/{id}/calificaciones/entrega")
    suspend fun calificarEntrega(
        @Path("id") id: Int,
        @Body request: CalificarEntregaRequest
    ): Response<Any>

    @POST("v1/cliente/pedidos/{id}/calificaciones/platillo")
    suspend fun calificarPlatillo(
        @Path("id") id: Int,
        @Body request: CalificarPlatilloRequest
    ): Response<Any>
}