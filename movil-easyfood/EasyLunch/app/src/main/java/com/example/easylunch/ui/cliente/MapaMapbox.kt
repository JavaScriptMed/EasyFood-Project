package com.example.easylunch.ui.cliente

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.viewinterop.AndroidView
import com.example.easylunch.data.geo.Coordenada
import com.example.easylunch.data.geo.ZonaGeo
import com.mapbox.common.MapboxOptions
import com.mapbox.geojson.Point
import com.mapbox.maps.CameraOptions
import com.mapbox.maps.MapView
import com.mapbox.maps.Style
import com.mapbox.maps.plugin.annotation.Annotation
import com.mapbox.maps.plugin.annotation.annotations
import com.mapbox.maps.plugin.annotation.generated.*
import com.mapbox.maps.plugin.gestures.OnMapClickListener
import com.mapbox.maps.plugin.gestures.gestures

private fun Coordenada.punto(): Point = Point.fromLngLat(lng, lat)
private fun Point.coord(): Coordenada = Coordenada(lat = latitude(), lng = longitude())

private fun iconoPin(): Bitmap {
    val tam = 72
    val bmp = Bitmap.createBitmap(tam, tam, Bitmap.Config.ARGB_8888)
    val c = Canvas(bmp)
    val p = Paint(Paint.ANTI_ALIAS_FLAG)
    p.color = 0xFF1E1E1E.toInt(); c.drawCircle(tam / 2f, tam / 2f, tam / 2.2f, p)
    p.color = 0xFFFFB300.toInt(); c.drawCircle(tam / 2f, tam / 2f, tam / 3.2f, p)
    p.color = 0xFF1E1E1E.toInt(); c.drawCircle(tam / 2f, tam / 2f, tam / 9f, p)
    return bmp
}

/**
 * Mapa de Mapbox con un pin que se arrastra o se coloca tocando el mapa,
 * y las zonas de cobertura dibujadas encima. `centrarEn` mueve la cámara cuando cambia.
 */
@Composable
fun MapaMapbox(
    token: String,
    pin: Coordenada?,
    centrarEn: Coordenada,
    zonas: List<ZonaGeo>,
    onPin: (Coordenada) -> Unit,
    onError: (String) -> Unit,
    modifier: Modifier = Modifier
) {
    val onPinActual by rememberUpdatedState(onPin)
    val onErrorActual by rememberUpdatedState(onError)
    var vista by remember { mutableStateOf<MapView?>(null) }
    var pines by remember { mutableStateOf<PointAnnotationManager?>(null) }
    var poligonos by remember { mutableStateOf<PolygonAnnotationManager?>(null) }
    var bordes by remember { mutableStateOf<PolylineAnnotationManager?>(null) }
    val icono = remember { iconoPin() }

    AndroidView(
        modifier = modifier,
        factory = { ctx ->
            MapboxOptions.accessToken = token
            MapView(ctx).also { mv ->
                mv.mapboxMap.setCamera(CameraOptions.Builder().center(centrarEn.punto()).zoom(15.0).build())
                mv.mapboxMap.loadStyle(Style.MAPBOX_STREETS)
                mv.mapboxMap.subscribeMapLoadingError { onErrorActual("No se pudo cargar el mapa. Revisa tu conexión.") }
                poligonos = mv.annotations.createPolygonAnnotationManager()
                bordes = mv.annotations.createPolylineAnnotationManager()
                val pm = mv.annotations.createPointAnnotationManager()
                pm.addDragListener(object : OnPointAnnotationDragListener {
                    override fun onAnnotationDragStarted(annotation: Annotation<*>) {}
                    override fun onAnnotationDrag(annotation: Annotation<*>) {}
                    override fun onAnnotationDragFinished(annotation: Annotation<*>) {
                        (annotation as? PointAnnotation)?.let { onPinActual(it.point.coord()) }
                    }
                })
                pines = pm
                mv.gestures.addOnMapClickListener(OnMapClickListener { p -> onPinActual(p.coord()); true })
                vista = mv
            }
        }
    )

    // Pin
    LaunchedEffect(pin, pines) {
        val pm = pines ?: return@LaunchedEffect
        pm.deleteAll()
        if (pin != null) pm.create(PointAnnotationOptions().withPoint(pin.punto()).withIconImage(icono).withDraggable(true))
    }
    // Cámara
    LaunchedEffect(centrarEn, vista) {
        vista?.mapboxMap?.setCamera(CameraOptions.Builder().center(centrarEn.punto()).zoom(16.0).build())
    }
    // Zonas
    LaunchedEffect(zonas, poligonos, bordes) {
        val pg = poligonos ?: return@LaunchedEffect
        val bd = bordes ?: return@LaunchedEffect
        pg.deleteAll(); bd.deleteAll()
        zonas.forEach { z ->
            z.poligonos.forEach { poly ->
                val anillos = poly.map { anillo -> anillo.map { it.punto() } }
                pg.create(PolygonAnnotationOptions().withPoints(anillos).withFillColor("#FFB300").withFillOpacity(0.18))
                anillos.firstOrNull()?.let { bd.create(PolylineAnnotationOptions().withPoints(it).withLineColor("#8A6100").withLineWidth(2.0)) }
            }
        }
    }
}
