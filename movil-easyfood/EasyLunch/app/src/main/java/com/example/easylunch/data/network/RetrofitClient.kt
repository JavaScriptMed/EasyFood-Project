package com.example.easylunch.data.network

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Cliente HTTP único de la app.
 * - Manda siempre `Accept: application/json` y el Bearer (menos en login/registro/cuentas públicas).
 * - No fuerza `Content-Type`: Retrofit pone el correcto (JSON o multipart).
 * - 401 con sesión → avisa a la UI (`sesionVencida`) para mandar al login.
 * - 429 → espera lo que diga `Retry-After` (máx. 20 s) y reintenta una vez.
 */
object RetrofitClient {
    private const val BASE_URL = "https://easylunch-api.yahirdev.dev/api/"
    private const val PREF_NAME = "easylunch_prefs"
    private const val TOKEN_KEY = "auth_token"
    private const val ROLE_KEY = "auth_role"

    var authToken: String? = null
        private set
    var role: String? = null
        private set

    /** Se incrementa cada vez que el servidor responde 401 a una petición con token. */
    var sesionVencida by mutableIntStateOf(0)
        private set

    private var appContext: Context? = null

    fun init(context: Context) {
        appContext = context.applicationContext
        val prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        authToken = prefs.getString(TOKEN_KEY, null)
        role = prefs.getString(ROLE_KEY, null)
    }

    fun guardarSesion(token: String?, rol: String?) {
        authToken = token
        role = rol
        appContext?.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)?.edit()?.apply {
            if (token != null) putString(TOKEN_KEY, token) else remove(TOKEN_KEY)
            if (rol != null) putString(ROLE_KEY, rol) else remove(ROLE_KEY)
            apply()
        }
    }

    private val RUTAS_CON_LOG = listOf("/v1/empleado/menu", "/v1/cliente/menu-semanal", "/v1/repartidor/rutas")

    private fun esPublica(path: String): Boolean =
        path.endsWith("/auth/login") || path.endsWith("/auth/register") || path.contains("/v1/cuentas/")

    private val okHttpClient = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .writeTimeout(60, TimeUnit.SECONDS)
        .addInterceptor { chain ->
            val original = chain.request()
            val path = original.url.encodedPath
            val builder = original.newBuilder().header("Accept", "application/json")
            val token = authToken
            val conToken = token != null && !esPublica(path)
            if (conToken) builder.header("Authorization", "Bearer $token")
            val request = builder.build()

            var response = chain.proceed(request)
            if (response.code == 429) {
                val espera = response.header("Retry-After")?.toLongOrNull()
                if (espera != null && espera in 1..20) {
                    response.close()
                    Thread.sleep(espera * 1000)
                    response = chain.proceed(request)
                }
            }
            if (response.code == 401 && conToken) {
                sesionVencida++
            }
            // Solo en debug: respuesta cruda de las pantallas que se están verificando (nunca cabeceras ni token).
            if (com.example.easylunch.BuildConfig.DEBUG && RUTAS_CON_LOG.any { path.contains(it) }) {
                val cuerpo = try { response.peekBody(256 * 1024).string() } catch (_: Exception) { "<sin cuerpo>" }
                android.util.Log.d("EasyLunchApi", "${request.method} ${request.url.encodedPath}?${request.url.encodedQuery ?: ""} -> ${response.code} $cuerpo")
            }
            response
        }
        .build()

    val apiService: ApiService by lazy {
        Retrofit.Builder()
            .baseUrl(BASE_URL)
            .client(okHttpClient)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(ApiService::class.java)
    }

    /** Cliente sin cabeceras de la API (para Mapbox). */
    val httpExterno: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(15, TimeUnit.SECONDS)
            .build()
    }
}
