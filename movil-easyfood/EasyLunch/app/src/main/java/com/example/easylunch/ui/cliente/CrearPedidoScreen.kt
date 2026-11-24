package com.example.easylunch.ui.cliente

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.easylunch.data.model.*
import com.example.easylunch.ui.common.*

private val TIEMPOS = listOf("desayuno", "comida", "cena")

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CrearPedidoScreen(
    vm: ClienteViewModel,
    horaCorte: String?,
    onBack: () -> Unit,
    onAgregarDireccion: () -> Unit,
    onCreado: (PedidoDto) -> Unit
) {
    LaunchedEffect(Unit) {
        vm.reiniciarPedido()
        vm.cargarSemana(); vm.cargarPaquetes(); vm.cargarDirecciones()
    }
    val cargas = listOf(vm.semana, vm.paquetes, vm.direcciones)
    val falla = cargas.filterIsInstance<Carga.Falla>().firstOrNull()

    Column(Modifier.fillMaxSize().background(FondoApp)) {
        BarraSuperior("Nuevo pedido", onBack = onBack)
        when {
            falla != null -> PantallaError(falla.error, onReintentar = { vm.cargarSemana(); vm.cargarPaquetes(); vm.cargarDirecciones() })
            cargas.any { it is Carga.Cargando } -> PantallaCargando()
            else -> FormularioPedido(
                vm,
                (vm.semana as Carga.Listo).datos,
                (vm.paquetes as Carga.Listo).datos,
                (vm.direcciones as Carga.Listo).datos,
                onAgregarDireccion, onCreado
            )
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun FormularioPedido(
    vm: ClienteViewModel,
    semana: SemanaCliente,
    paquetes: List<PaqueteDto>,
    direcciones: List<DireccionDto>,
    onAgregarDireccion: () -> Unit,
    onCreado: (PedidoDto) -> Unit
) {
    var paqueteId by remember { mutableStateOf(paquetes.firstOrNull { it.destacado == true }?.id ?: paquetes.firstOrNull()?.id) }
    var direccionId by remember(direcciones) {
        mutableStateOf(direcciones.firstOrNull { it.predeterminada == true && !it.fueraDeCobertura }?.id
            ?: direcciones.firstOrNull { !it.fueraDeCobertura }?.id)
    }
    var tiempos by remember { mutableStateOf(setOf<String>()) }
    var conExtra by remember { mutableStateOf(false) }
    var extras by remember { mutableStateOf(mapOf<String, Int>()) }

    val paquete = paquetes.firstOrNull { it.id == paqueteId }
    val todosDias = semana.entrega.dias_de_comida
    val dias = paquete?.let { todosDias.take(it.dias) } ?: emptyList()
    val rechazados = vm.rechazados

    fun celda(fecha: String, t: String): CeldaMenuDto? =
        semana.menu[fecha]?.celdas?.get(t)?.takeIf { rechazados[fecha]?.contains(it.platillo_id) != true }

    // Problemas que impiden confirmar (se explican en pantalla).
    val problemas = buildList {
        if (paquete == null) add("Elige un paquete.")
        if (paquete != null && todosDias.size < paquete.dias) add("Por ahora solo hay ${todosDias.size} días de comida disponibles para este paquete.")
        if (direccionId == null) add("Elige una dirección dentro de cobertura.")
        if (tiempos.isEmpty()) add("Elige de 1 a 3 comidas por día.")
        tiempos.forEach { t ->
            val faltan = dias.filter { celda(it, t) == null }
            if (faltan.isNotEmpty()) add("No hay ${Formato.tiempoComida(t).lowercase()} disponible el ${faltan.joinToString { Formato.diaCorto(it) }}: quita ese tiempo de comida.")
        }
        if (conExtra) {
            val sinExtra = dias.filter { f -> extras[f]?.let { id -> rechazados[f]?.contains(id) != true } != true }
            if (sinExtra.isNotEmpty()) add("Elige el extra de ${sinExtra.joinToString { Formato.diaCorto(it) }}.")
        }
    }

    Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp)) {
        EncabezadoEntrega(semana.entrega.fecha_entrega, semana.entrega.cierra_en)
        Spacer(Modifier.height(16.dp))

        Seccion("1. Paquete")
        if (paquetes.isEmpty()) AvisoInfo("No hay paquetes disponibles por ahora.")
        paquetes.forEach { p ->
            Opcion(seleccionado = p.id == paqueteId, onClick = { paqueteId = p.id }) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(p.nombre, fontWeight = FontWeight.Bold, color = TextoTitulo, modifier = Modifier.weight(1f))
                    p.descuento_porcentaje?.takeIf { it > 0 }?.let { Etiqueta("${if (it % 1.0 == 0.0) it.toInt() else it} % de descuento") }
                }
                p.detalle?.let { Text(it, color = TextoSuave, fontSize = 14.sp) }
            }
        }

        Spacer(Modifier.height(16.dp))
        Seccion("2. Dirección de entrega")
        if (direcciones.isEmpty()) AvisoInfo("Agrega una dirección para poder pedir.")
        direcciones.forEach { d ->
            Opcion(seleccionado = d.id == direccionId, habilitado = !d.fueraDeCobertura, onClick = { direccionId = d.id }) {
                Text(d.etiqueta ?: "Dirección", fontWeight = FontWeight.Bold, color = if (d.fueraDeCobertura) TextoSuave else TextoTitulo)
                Text(d.direccion, color = TextoSuave, fontSize = 14.sp)
                if (d.fueraDeCobertura) Etiqueta("Fuera de cobertura · no se puede usar para pedir", Color(0xFFFDECEA), RojoError)
                else d.zona?.let { Etiqueta("Zona: $it", Color(0xFFE8F5E9), VerdeOk) }
            }
        }
        TextButton(onClick = onAgregarDireccion) { Text("+ Agregar dirección", color = Color(0xFF8A6100)) }

        Spacer(Modifier.height(16.dp))
        Seccion("3. Comidas por día")
        Text("Elige 1, 2 o 3 comidas; son las mismas todos los días del paquete.", color = TextoSuave, fontSize = 14.sp)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            TIEMPOS.forEach { t ->
                FilterChip(selected = t in tiempos, onClick = { tiempos = if (t in tiempos) tiempos - t else tiempos + t },
                    label = { Text(Formato.tiempoComida(t)) })
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Agregar un extra por día", modifier = Modifier.weight(1f), color = TextoTitulo)
            Switch(checked = conExtra, onCheckedChange = { conExtra = it })
        }
        Text("El extra va en todos los días o en ninguno y puede ser de cualquier tiempo de comida.", color = TextoSuave, fontSize = 13.sp)

        Spacer(Modifier.height(12.dp))
        dias.forEach { fecha ->
            Tarjeta(Modifier.padding(vertical = 6.dp)) {
                Text(Formato.diaCorto(fecha), fontWeight = FontWeight.Bold, fontSize = 17.sp, color = TextoTitulo)
                if (tiempos.isEmpty()) Text("Elige tus comidas arriba.", color = TextoSuave, fontSize = 14.sp)
                TIEMPOS.filter { it in tiempos }.forEach { t ->
                    val c = celda(fecha, t)
                    val rechazado = semana.menu[fecha]?.celdas?.get(t)?.let { rechazados[fecha]?.contains(it.platillo_id) } == true
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(Formato.tiempoComida(t), color = TextoSuave, modifier = Modifier.width(84.dp), fontSize = 14.sp)
                        when {
                            c != null -> {
                                FotoPlatillo(c.foto_url, Modifier.size(40.dp))
                                Spacer(Modifier.width(8.dp))
                                Text(c.platillo_nombre, modifier = Modifier.weight(1f), color = TextoTitulo)
                                Text(Formato.dinero(c.precio_venta), fontSize = 13.sp, color = Color(0xFF8A6100))
                            }
                            rechazado -> Text("Sin porciones este día", color = RojoError, fontSize = 14.sp, modifier = Modifier.weight(1f))
                            else -> Text("No hay platillo este día", color = RojoError, fontSize = 14.sp, modifier = Modifier.weight(1f))
                        }
                    }
                }
                if (conExtra) {
                    Spacer(Modifier.height(6.dp))
                    Text("Extra", fontWeight = FontWeight.SemiBold, color = TextoTitulo)
                    val opciones = TIEMPOS.mapNotNull { t -> celda(fecha, t)?.let { t to it } }
                    if (opciones.isEmpty()) Text("No hay platillos para el extra este día.", color = RojoError, fontSize = 14.sp)
                    opciones.forEach { (t, c) ->
                        Row(Modifier.fillMaxWidth().clickable { extras = extras + (fecha to c.platillo_id) }, verticalAlignment = Alignment.CenterVertically) {
                            RadioButton(selected = extras[fecha] == c.platillo_id, onClick = { extras = extras + (fecha to c.platillo_id) })
                            Text("${c.platillo_nombre} (${Formato.tiempoComida(t).lowercase()})", modifier = Modifier.weight(1f), fontSize = 14.sp)
                            Text(Formato.dinero(c.precio_venta), fontSize = 13.sp, color = Color(0xFF8A6100))
                        }
                    }
                }
            }
        }

        Spacer(Modifier.height(12.dp))
        AvisoInfo("El total con el descuento del paquete lo calcula el servidor y lo verás al confirmar.")
        Spacer(Modifier.height(8.dp))
        if (problemas.isNotEmpty() && (tiempos.isNotEmpty() || conExtra)) problemas.forEach { Text("• $it", color = RojoError, fontSize = 14.sp) }
        AvisoError(vm.errorPedido?.mensaje)
        Spacer(Modifier.height(12.dp))
        BotonPrincipal(
            "Confirmar pedido",
            onClick = {
                val p = paquete ?: return@BotonPrincipal
                val dir = direccionId ?: return@BotonPrincipal
                val platillos = dias.map { f ->
                    PlatillosDiaRequest(
                        desayuno = if ("desayuno" in tiempos) celda(f, "desayuno")?.platillo_id else null,
                        comida = if ("comida" in tiempos) celda(f, "comida")?.platillo_id else null,
                        cena = if ("cena" in tiempos) celda(f, "cena")?.platillo_id else null,
                        extra = if (conExtra) extras[f] else null
                    )
                }
                val nombres = dias.associateWith { f ->
                    semana.menu[f]?.celdas?.values?.associate { it.platillo_id to it.platillo_nombre }.orEmpty()
                }
                vm.crearPedido(CrearPedidoRequest(p.id, dir, platillos), nombres, onCreado)
            },
            habilitado = problemas.isEmpty(),
            cargando = vm.creandoPedido
        )
        Spacer(Modifier.height(32.dp))
    }
}

@Composable
private fun Seccion(texto: String) {
    Text(texto, fontWeight = FontWeight.Bold, fontSize = 17.sp, color = TextoTitulo, modifier = Modifier.padding(bottom = 8.dp))
}

@Composable
private fun Opcion(seleccionado: Boolean, onClick: () -> Unit, habilitado: Boolean = true, contenido: @Composable ColumnScope.() -> Unit) {
    Card(
        onClick = onClick, enabled = habilitado,
        modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp),
        shape = RoundedCornerShape(14.dp),
        border = BorderStroke(if (seleccionado) 2.dp else 1.dp, if (seleccionado) Mostaza else Color(0xFFE0E0E0)),
        colors = CardDefaults.cardColors(containerColor = if (seleccionado) MostazaClaro else Color.White, disabledContainerColor = Color(0xFFF3F3F3))
    ) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RadioButton(selected = seleccionado, onClick = onClick, enabled = habilitado)
            Column(Modifier.weight(1f), content = contenido)
        }
    }
}
