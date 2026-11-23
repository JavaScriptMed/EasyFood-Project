package com.example.easylunch.data.model

data class SaludResponse(
    val estado: String?,
    val api: ApiInfo?,
    val base_de_datos: DbInfo?
)

data class ApiInfo(
    val estado: String?,
    val entorno: String?,
    val hora: String?
)

data class DbInfo(
    val estado: String?,
    val motor: String?,
    val version: String?,
    val postgis: String?,
    val latencia_ms: Double?
)