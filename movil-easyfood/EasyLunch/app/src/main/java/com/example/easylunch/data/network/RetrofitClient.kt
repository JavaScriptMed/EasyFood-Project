package com.example.easylunch.data.network

import android.content.Context
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory

object RetrofitClient {
    private const val BASE_URL = "https://easylunch-api.yahirdev.dev/api/"
    private const val PREF_NAME = "easylunch_prefs"
    private const val TOKEN_KEY = "auth_token"

    // Variable global del token en memoria
    var authToken: String? = null
        private set

    // Método para inicializar el token leyendo las preferencias del celular al abrir la app
    fun init(context: Context) {
        val prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        authToken = prefs.getString(TOKEN_KEY, null)
    }

    // Método para guardar el token de forma permanente en el dispositivo
    fun saveToken(context: Context, token: String?) {
        authToken = token
        val prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE)
        prefs.edit().apply {
            if (token != null) {
                putString(TOKEN_KEY, token)
            } else {
                remove(TOKEN_KEY)
            }
            apply()
        }
    }

    private val okHttpClient = OkHttpClient.Builder()
        .addInterceptor { chain ->
            val originalRequest = chain.request()
            val requestBuilder = originalRequest.newBuilder()
                .header("Accept", "application/json")
                .header("Content-Type", "application/json")

            authToken?.let { token ->
                requestBuilder.header("Authorization", "Bearer $token")
            }

            chain.proceed(requestBuilder.build())
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
}