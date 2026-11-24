package com.example.easylunch.ui.repartidor

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.Path
import android.graphics.Typeface
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.AndroidView
import com.example.easylunch.data.geo.Coordenada
import com.example.easylunch.data.geo.MapboxGeocoder
import com.example.easylunch.data.model.ParadaDto
import com.mapbox.common.MapboxOptions
import com.mapbox.geojson.Point
import com.mapbox.maps.CameraOptions
import com.mapbox.maps.MapView
import com.mapbox.maps.Style
import com.mapbox.maps.plugin.annotation.annotations
import com.mapbox.maps.plugin.annotation.generated.*
import com.mapbox.maps.plugin.locationcomponent.OnIndicatorPositionChangedListener
import com.mapbox.maps.plugin.locationcomponent.createDefault2DPuck
import com.mapbox.maps.plugin.locationcomponent.location
import com.mapbox.maps.plugin.scalebar.scalebar
import kotlin.math.ln
import kotlin.math.max

/** Botones flotantes del mapa (centrar en mí, acercar, alejar). */
class ControlMapa {
    internal var vista: MapView? = null
    var miUbicacion by mutableStateOf<Coordenada?>(null)
        internal set

    fun acercar(delta: Double) {
        val mv = vista ?: return
        mv.mapboxMap.setCamera(CameraOptions.Builder().zoom(mv.mapboxMap.cameraState.zoom + delta).build())
    }

    fun centrarEnMi(): Boolean {
        val p = miUbicacion ?: return false
        vista?.mapboxMap?.setCamera(CameraOptions.Builder().center(Point.fromLngLat(p.lng, p.lat)).zoom(16.0).build())
        return true
    }
}

private fun colorParada(estado: String, siguiente: Boolean): Int = when {
    estado == "entregado" -> 0xFF22A447.toInt()
    estado == "no_localizado" -> 0xFFC62828.toInt()
    siguiente || estado == "en_camino" -> 0xFF3B82F6.toInt()
    else -> 0xFF9E9E9E.toInt()
}

/** Pin en forma de gota con el número de la parada (✓ si ya se entregó), como en el diseño. */
private fun pinGota(texto: String, color: Int): Bitmap {
    val w = 84; val h = 112
    val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
    val c = Canvas(bmp)
    val p = Paint(Paint.ANTI_ALIAS_FLAG)
    val r = w / 2f - 4
    val cx = w / 2f; val cy = r + 4
    val gota = Path().apply {
        addCircle(cx, cy, r, Path.Direction.CW)
        moveTo(cx - r * 0.62f, cy + r * 0.72f); lineTo(cx, h - 18f); lineTo(cx + r * 0.62f, cy + r * 0.72f); close()
    }
    p.color = color; c.drawPath(gota, p)
    p.color = 0xFFFFFFFF.toInt(); c.drawCircle(cx, h - 12f, 9f, p)
    p.color = color; c.drawCircle(cx, h - 12f, 5f, p)
    p.color = 0xFFFFFFFF.toInt(); p.textSize = 36f; p.typeface = Typeface.DEFAULT_BOLD; p.textAlign = Paint.Align.CENTER
    c.drawText(texto, cx, cy - (p.descent() + p.ascent()) / 2, p)
    return bmp
}

private fun encuadre(puntos: List<Coordenada>): CameraOptions {
    val minLat = puntos.minOf { it.lat }; val maxLat = puntos.maxOf { it.lat }
    val minLng = puntos.minOf { it.lng }; val maxLng = puntos.maxOf { it.lng }
    val span = max(max(maxLat - minLat, maxLng - minLng), 0.002)
    val zoom = (ln(360.0 / span) / ln(2.0) - 1.6).coerceIn(10.0, 16.5)
    // Centro un poco más abajo para que la hoja inferior no tape las paradas.
    val centroLat = (minLat + maxLat) / 2 - (maxLat - minLat) * 0.35
    return CameraOptions.Builder().center(Point.fromLngLat((minLng + maxLng) / 2, centroLat)).zoom(zoom).build()
}

/**
 * Mapa de la ruta. Sin parada activa: todas las paradas y el trazo en orden.
 * Con parada `enfoque` (en camino): trazo desde mi ubicación hasta esa parada.
 */
@Composable
fun MapaRuta(
    paradas: List<ParadaDto>,
    siguienteId: Int?,
    enfoque: ParadaDto?,
    control: ControlMapa,
    ubicacionPermitida: Boolean,
    onResumen: (MapboxGeocoder.TrazoRuta?) -> Unit,
    onError: (String) -> Unit,
    modifier: Modifier = Modifier
) {
    val onErrorActual by rememberUpdatedState(onError)
    val onResumenActual by rememberUpdatedState(onResumen)
    var vista by remember { mutableStateOf<MapView?>(null) }
    var pines by remember { mutableStateOf<PointAnnotationManager?>(null) }
    var lineas by remember { mutableStateOf<PolylineAnnotationManager?>(null) }
    val conPunto = remember(paradas) { paradas.sortedBy { it.orden }.filter { it.ubicacion != null } }
    val puntos = remember(conPunto) { conPunto.map { Coordenada(it.ubicacion!!.latitud, it.ubicacion.longitud) } }
    var trazo by remember { mutableStateOf<List<Coordenada>>(emptyList()) }

    // Mi ubicación redondeada para no pedir la ruta en cada movimiento mínimo.
    val miPos = control.miUbicacion
    val miPosAprox = miPos?.let { Coordenada("%.3f".format(java.util.Locale.US, it.lat).toDouble(), "%.3f".format(java.util.Locale.US, it.lng).toDouble()) }

    LaunchedEffect(puntos, enfoque?.id, miPosAprox) {
        val destino = enfoque?.ubicacion?.let { Coordenada(it.latitud, it.longitud) }
        if (destino != null) {
            val origen = miPos
            if (origen == null) { trazo = emptyList(); onResumenActual(null); return@LaunchedEffect }
            MapboxGeocoder.rutaConResumen(listOf(origen, destino))
                .onSuccess { trazo = it.linea; onResumenActual(it) }
                .onFailure { trazo = listOf(origen, destino); onResumenActual(null) }
        } else {
            onResumenActual(null)
            trazo = puntos
            if (puntos.size >= 2) MapboxGeocoder.trazoRuta(puntos).onSuccess { trazo = it }
        }
    }

    AndroidView(modifier = modifier, factory = { ctx ->
        MapboxOptions.accessToken = MapboxGeocoder.token
        MapView(ctx).also { mv ->
            mv.mapboxMap.loadStyle(Style.MAPBOX_STREETS)
            mv.mapboxMap.subscribeMapLoadingError { onErrorActual("No se pudo cargar el mapa. Revisa tu conexión.") }
            mv.scalebar.enabled = false
            lineas = mv.annotations.createPolylineAnnotationManager()
            pines = mv.annotations.createPointAnnotationManager()
            mv.location.addOnIndicatorPositionChangedListener(OnIndicatorPositionChangedListener { p ->
                control.miUbicacion = Coordenada(p.latitude(), p.longitude())
            })
            control.vista = mv
            vista = mv
        }
    })

    // Flecha azul con mi ubicación (solo se dibuja en el teléfono).
    LaunchedEffect(ubicacionPermitida, vista) {
        val mv = vista ?: return@LaunchedEffect
        mv.location.updateSettings {
            enabled = ubicacionPermitida
            locationPuck = createDefault2DPuck(withBearing = true)
            puckBearingEnabled = true
        }
    }
    LaunchedEffect(trazo, lineas) {
        val l = lineas ?: return@LaunchedEffect
        l.deleteAll()
        if (trazo.size >= 2) l.create(
            PolylineAnnotationOptions().withPoints(trazo.map { Point.fromLngLat(it.lng, it.lat) })
                .withLineColor("#3B82F6").withLineWidth(6.0).withLineJoin(com.mapbox.maps.extension.style.layers.properties.generated.LineJoin.ROUND)
        )
    }
    LaunchedEffect(conPunto, siguienteId, enfoque?.id, pines) {
        val pm = pines ?: return@LaunchedEffect
        pm.deleteAll()
        val mostrar = if (enfoque != null) conPunto.filter { it.id == enfoque.id } else conPunto
        mostrar.forEach { p ->
            val texto = when (p.estado) { "entregado" -> "✓"; "no_localizado" -> "✕"; else -> p.orden.toString() }
            pm.create(
                PointAnnotationOptions().withPoint(Point.fromLngLat(p.ubicacion!!.longitud, p.ubicacion.latitud))
                    .withIconImage(pinGota(texto, colorParada(p.estado, p.id == siguienteId)))
                    .withIconAnchor(com.mapbox.maps.extension.style.layers.properties.generated.IconAnchor.BOTTOM)
            )
        }
    }
    LaunchedEffect(enfoque?.id, puntos, vista, trazo.size) {
        val mv = vista ?: return@LaunchedEffect
        val u = enfoque?.ubicacion
        when {
            u != null && trazo.size >= 2 -> mv.mapboxMap.setCamera(encuadre(trazo))
            u != null -> mv.mapboxMap.setCamera(CameraOptions.Builder().center(Point.fromLngLat(u.longitud, u.latitud)).zoom(15.5).build())
            puntos.isNotEmpty() -> mv.mapboxMap.setCamera(encuadre(puntos))
            else -> mv.mapboxMap.setCamera(CameraOptions.Builder().center(Point.fromLngLat(-103.3496, 20.6597)).zoom(11.5).build())
        }
    }
}
