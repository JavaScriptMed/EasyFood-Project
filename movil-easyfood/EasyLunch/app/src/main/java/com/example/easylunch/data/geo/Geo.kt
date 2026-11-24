package com.example.easylunch.data.geo

import com.example.easylunch.BuildConfig
import com.example.easylunch.data.model.ZonaDto
import com.example.easylunch.data.network.RetrofitClient
import com.google.gson.JsonArray
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.Request

/** Punto geográfico simple (longitud, latitud). */
data class Coordenada(val lat: Double, val lng: Double)

/** Zona con sus polígonos; cada polígono es una lista de anillos (el primero es el borde, los demás huecos). */
data class ZonaGeo(val id: Int, val nombre: String, val poligonos: List<List<List<Coordenada>>>)

object Cobertura {
    /** Centro de Guadalajara y caja para limitar búsquedas. */
    val CENTRO_GDL = Coordenada(20.6597, -103.3496)
    const val BBOX_GDL = "-103.60,20.45,-103.15,20.85"

    fun leerZonas(zonas: List<ZonaDto>): List<ZonaGeo> = zonas.mapNotNull { z ->
        val geo = z.geojson ?: return@mapNotNull null
        val polys = try { poligonosDe(geo) } catch (_: Exception) { emptyList() }
        if (polys.isEmpty()) null else ZonaGeo(z.id, z.nombre, polys)
    }

    private fun poligonosDe(el: JsonElement): List<List<List<Coordenada>>> {
        val obj: JsonObject = when {
            el.isJsonPrimitive -> JsonParser.parseString(el.asString).asJsonObject
            el.isJsonObject -> el.asJsonObject
            else -> return emptyList()
        }
        return when (obj.get("type")?.asString) {
            "Feature" -> poligonosDe(obj.get("geometry"))
            "FeatureCollection" -> obj.getAsJsonArray("features").flatMap { poligonosDe(it) }
            "Polygon" -> listOf(anillos(obj.getAsJsonArray("coordinates")))
            "MultiPolygon" -> obj.getAsJsonArray("coordinates").map { anillos(it.asJsonArray) }
            else -> emptyList()
        }
    }

    private fun anillos(arr: JsonArray): List<List<Coordenada>> = arr.map { anillo ->
        anillo.asJsonArray.map { p -> val a = p.asJsonArray; Coordenada(lat = a[1].asDouble, lng = a[0].asDouble) }
    }

    private fun dentroDeAnillo(p: Coordenada, anillo: List<Coordenada>): Boolean {
        var dentro = false
        var j = anillo.size - 1
        for (i in anillo.indices) {
            val a = anillo[i]; val b = anillo[j]
            if ((a.lat > p.lat) != (b.lat > p.lat) &&
                p.lng < (b.lng - a.lng) * (p.lat - a.lat) / (b.lat - a.lat) + a.lng
            ) dentro = !dentro
            j = i
        }
        return dentro
    }

    /** Zona que contiene el punto, o null si está fuera de cobertura. */
    fun zonaDe(p: Coordenada, zonas: List<ZonaGeo>): ZonaGeo? = zonas.firstOrNull { z ->
        z.poligonos.any { poly -> poly.isNotEmpty() && dentroDeAnillo(p, poly[0]) && poly.drop(1).none { dentroDeAnillo(p, it) } }
    }
}

/** Resultado de búsqueda o de geocodificación inversa. */
data class LugarSugerido(
    val titulo: String,
    val detalle: String,
    val calleYNumero: String?,
    val colonia: String?,
    val codigoPostal: String?,
    val punto: Coordenada
)

/** Mapbox Geocoding v6 con el token público (pk.) de `MAPBOX_ACCESS_TOKEN`. */
object MapboxGeocoder {
    val token: String get() = BuildConfig.MAPBOX_ACCESS_TOKEN
    val disponible: Boolean get() = token.startsWith("pk.")

    private const val BASE = "https://api.mapbox.com/search/geocode/v6"

    suspend fun sugerencias(texto: String): Result<List<LugarSugerido>> = llamar(
        "$BASE/forward".toHttpUrl().newBuilder()
            .addQueryParameter("q", texto)
            .addQueryParameter("autocomplete", "true")
            .addQueryParameter("country", "mx")
            .addQueryParameter("language", "es")
            .addQueryParameter("limit", "6")
            .addQueryParameter("bbox", Cobertura.BBOX_GDL)
            .addQueryParameter("proximity", "${Cobertura.CENTRO_GDL.lng},${Cobertura.CENTRO_GDL.lat}")
            .addQueryParameter("access_token", token)
            .build().toString()
    )

    suspend fun inversa(p: Coordenada): Result<LugarSugerido?> = llamar(
        "$BASE/reverse".toHttpUrl().newBuilder()
            .addQueryParameter("longitude", p.lng.toString())
            .addQueryParameter("latitude", p.lat.toString())
            .addQueryParameter("language", "es")
            .addQueryParameter("types", "address")
            .addQueryParameter("access_token", token)
            .build().toString()
    ).map { it.firstOrNull() }

    private suspend fun llamar(url: String): Result<List<LugarSugerido>> = withContext(Dispatchers.IO) {
        if (!disponible) return@withContext Result.failure(IllegalStateException("Sin token de Mapbox"))
        try {
            RetrofitClient.httpExterno.newCall(Request.Builder().url(url).build()).execute().use { r ->
                if (!r.isSuccessful) return@withContext Result.failure(IllegalStateException("Mapbox respondió ${r.code}"))
                val json = JsonParser.parseString(r.body?.string() ?: "{}").asJsonObject
                Result.success(json.getAsJsonArray("features")?.mapNotNull { leer(it.asJsonObject) } ?: emptyList())
            }
        } catch (e: Exception) {
            Result.failure(e)
        }
    }

    /** Trazo por calles entre las paradas, en orden (Mapbox Directions). */
    suspend fun trazoRuta(puntos: List<Coordenada>): Result<List<Coordenada>> = rutaConResumen(puntos).map { it.linea }

    data class TrazoRuta(val linea: List<Coordenada>, val segundos: Double?, val metros: Double?)

    /** Trazo + duración y distancia. La ubicación del teléfono solo va a Mapbox para dibujar; nunca a la API de EasyLunch. */
    suspend fun rutaConResumen(puntos: List<Coordenada>): Result<TrazoRuta> = withContext(Dispatchers.IO) {
        if (!disponible) return@withContext Result.failure(IllegalStateException("Sin token de Mapbox"))
        if (puntos.size < 2) return@withContext Result.success(TrazoRuta(puntos, null, null))
        val coords = puntos.take(25).joinToString(";") { "${it.lng},${it.lat}" }
        val url = "https://api.mapbox.com/directions/v5/mapbox/driving/$coords".toHttpUrl().newBuilder()
            .addQueryParameter("geometries", "geojson")
            .addQueryParameter("overview", "full")
            .addQueryParameter("access_token", token)
            .build()
        try {
            RetrofitClient.httpExterno.newCall(Request.Builder().url(url).build()).execute().use { r ->
                if (!r.isSuccessful) return@withContext Result.failure(IllegalStateException("Mapbox respondió ${r.code}"))
                val json = JsonParser.parseString(r.body?.string() ?: "{}").asJsonObject
                val ruta = json.getAsJsonArray("routes")?.firstOrNull()?.asJsonObject
                val linea = ruta?.getAsJsonObject("geometry")?.getAsJsonArray("coordinates")
                    ?.map { val a = it.asJsonArray; Coordenada(lat = a[1].asDouble, lng = a[0].asDouble) }
                Result.success(TrazoRuta(linea ?: puntos, ruta?.get("duration")?.asDouble, ruta?.get("distance")?.asDouble))
            }
        } catch (e: Exception) { Result.failure(e) }
    }

    private fun JsonObject.texto(vararg ruta: String): String? {
        var el: JsonElement? = this
        for (k in ruta) el = el?.takeIf { it.isJsonObject }?.asJsonObject?.get(k)
        return el?.takeIf { it.isJsonPrimitive }?.asString?.takeIf { it.isNotBlank() }
    }

    private fun leer(f: JsonObject): LugarSugerido? {
        val coords = f.getAsJsonObject("geometry")?.getAsJsonArray("coordinates") ?: return null
        val props = f.getAsJsonObject("properties") ?: return null
        val ctx = props.getAsJsonObject("context")
        val calle = ctx?.texto("address", "name")
            ?: listOfNotNull(ctx?.texto("street", "name")).firstOrNull()
        return LugarSugerido(
            titulo = props.texto("name") ?: "",
            detalle = props.texto("place_formatted") ?: props.texto("full_address") ?: "",
            calleYNumero = calle,
            colonia = ctx?.texto("neighborhood", "name") ?: ctx?.texto("locality", "name"),
            codigoPostal = ctx?.texto("postcode", "name"),
            punto = Coordenada(lat = coords[1].asDouble, lng = coords[0].asDouble)
        )
    }
}
