package com.example.easylunch.ui.common

import java.time.Instant
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.util.Locale

/** Zona horaria de la operación (Guadalajara). Todas las fechas se muestran en esta zona. */
val ZONA_GDL: ZoneId = ZoneId.of("America/Mexico_City")

private val DIAS_CORTOS = listOf("Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom")
private val DIAS_LARGOS = listOf("lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo")
private val MESES_CORTOS = listOf("ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic")
private val MESES_LARGOS = listOf("enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre")

object Formato {
    fun fecha(s: String?): LocalDate? = try { s?.let { LocalDate.parse(it.take(10)) } } catch (_: Exception) { null }

    fun momento(s: String?): ZonedDateTime? = try {
        s?.let { OffsetDateTime.parse(it).atZoneSameInstant(ZONA_GDL) }
    } catch (_: Exception) {
        try { s?.let { Instant.parse(it).atZone(ZONA_GDL) } } catch (_: Exception) { null }
    }

    /** "Lun 30 nov" */
    fun diaCorto(s: String?): String = fecha(s)?.let {
        "${DIAS_CORTOS[it.dayOfWeek.value - 1]} ${it.dayOfMonth} ${MESES_CORTOS[it.monthValue - 1]}"
    } ?: (s ?: "—")

    /** "sábado 28 de noviembre" */
    fun diaLargo(s: String?): String = fecha(s)?.let { diaLargo(it) } ?: (s ?: "—")

    fun diaLargo(d: LocalDate): String =
        "${DIAS_LARGOS[d.dayOfWeek.value - 1]} ${d.dayOfMonth} de ${MESES_LARGOS[d.monthValue - 1]}"

    /** "viernes 27 de noviembre a las 18:00" */
    fun momentoLargo(s: String?): String = momento(s)?.let {
        "${diaLargo(it.toLocalDate())} a las ${"%02d:%02d".format(it.hour, it.minute)}"
    } ?: "—"

    fun hora(s: String?): String = momento(s)?.let { "%02d:%02d".format(it.hour, it.minute) } ?: "—"

    fun dinero(v: Double?): String = if (v == null) "—" else "$" + String.format(Locale.US, "%,.2f", v) + " MXN"

    fun dinero(v: String?): String = dinero(v?.toDoubleOrNull())

    fun tiempoComida(t: String?): String = when (t) {
        "desayuno" -> "Desayuno"
        "comida" -> "Comida"
        "cena" -> "Cena"
        "extra" -> "Extra"
        null -> "—"
        else -> t.replaceFirstChar { it.uppercase() }
    }

    fun estadoPedido(e: String?): String = when (e) {
        "pendiente_pago" -> "Pendiente de pago"
        "recibido", "activo" -> "Pagado · por entregar"
        "completado" -> "Entregado"
        "cancelado" -> "Cancelado"
        else -> e ?: "—"
    }

    fun estadoEntrega(e: String?): String = when (e) {
        "programada" -> "Programada"
        "en_ruta" -> "En ruta"
        "entregada" -> "Entregada"
        "no_localizado" -> "No localizado"
        "cancelada" -> "Cancelada"
        else -> e ?: "—"
    }

    fun estadoParada(e: String?): String = when (e) {
        "pendiente" -> "Pendiente"
        "en_camino" -> "En camino"
        "entregado" -> "Entregada"
        "no_localizado" -> "No entregada"
        else -> e ?: "—"
    }

    fun estadoRuta(e: String?): String = when (e) {
        "aprobada" -> "Aprobada"
        "en_curso" -> "En curso"
        "completada" -> "Completada"
        else -> e ?: "—"
    }

    /** Segundos → "2 d 05:12:33" */
    fun cuentaRegresiva(segundos: Long): String {
        if (segundos <= 0) return "00:00:00"
        val d = segundos / 86400
        val h = (segundos % 86400) / 3600
        val m = (segundos % 3600) / 60
        val s = segundos % 60
        val hms = "%02d:%02d:%02d".format(h, m, s)
        return if (d > 0) "$d d $hms" else hms
    }
}
