#!/bin/sh
# Respaldo de la base de datos de EasyLunch: pg_dump comprimido, rotación de 7 días y verificación del archivo.
#
#   scripts/respaldo-db.sh              respalda la base
#   scripts/respaldo-db.sh --archivos   además respalda el volumen «storage» (fotos de platillos y adjuntos de tickets)
#
# Lee DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME y DB_PASSWORD de .env.production (o del archivo en ENV_FILE). La clave nunca va en la línea
# de comandos ni se imprime. Variables opcionales:
#   RESPALDOS_DIR     carpeta de destino                      (por defecto /var/backups/easylunch)
#   DIAS_A_GUARDAR    cuántos días se conservan               (por defecto 7)
#   PG_DUMP_CMD       comando de pg_dump                      (por defecto pg_dump)
#   VOLUMEN_STORAGE   volumen de Docker con las fotos         (por defecto easylunch-prod_storage)
#
# Cron diario a las 3:00 (crontab -e):
#   0 3 * * * cd /opt/easylunch/easyfood && scripts/respaldo-db.sh --archivos >> /var/log/easylunch-respaldo.log 2>&1
#
# Salida: 0 si todo salió bien; distinta de 0 si algo falló. Si el respaldo de hoy falla NO se borra ninguno de los anteriores.
set -eu
umask 077

cd "$(dirname "$0")/.."
ENV_FILE="${ENV_FILE:-.env.production}"
RESPALDOS_DIR="${RESPALDOS_DIR:-/var/backups/easylunch}"
DIAS_A_GUARDAR="${DIAS_A_GUARDAR:-7}"
PG_DUMP_CMD="${PG_DUMP_CMD:-pg_dump}"
VOLUMEN_STORAGE="${VOLUMEN_STORAGE:-easylunch-prod_storage}"
CON_ARCHIVOS=0
[ "${1:-}" = "--archivos" ] && CON_ARCHIVOS=1

registrar() { printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
fallar() { registrar "ERROR: $*" >&2; exit 1; }

case "$DIAS_A_GUARDAR" in ''|*[!0-9]*) fallar "DIAS_A_GUARDAR debe ser un número de días." ;; esac
[ "$DIAS_A_GUARDAR" -ge 1 ] || fallar "DIAS_A_GUARDAR debe ser al menos 1."
[ -f "$ENV_FILE" ] || fallar "No encuentro $ENV_FILE (indica otro con ENV_FILE=ruta)."

# Lee una variable del archivo sin ejecutarlo (el archivo puede traer caracteres que un shell interpretaría).
leer() {
  valor=$(grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- || true)
  valor=${valor%\"}; valor=${valor#\"}; valor=${valor%\'}; valor=${valor#\'}
  printf '%s' "$valor"
}
DB_HOST=$(leer DB_HOST); DB_PORT=$(leer DB_PORT); DB_DATABASE=$(leer DB_DATABASE); DB_USERNAME=$(leer DB_USERNAME); DB_PASSWORD=$(leer DB_PASSWORD)
[ -n "$DB_DATABASE" ] && [ -n "$DB_USERNAME" ] || fallar "Faltan DB_DATABASE o DB_USERNAME en $ENV_FILE."
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-5432}"
# Desde el servidor (fuera de Docker) host.docker.internal no existe: es el propio servidor.
[ "$DB_HOST" = "host.docker.internal" ] && DB_HOST=127.0.0.1

mkdir -p "$RESPALDOS_DIR"
chmod 700 "$RESPALDOS_DIR"

# Una sola copia a la vez (el bloqueo es una carpeta: funciona en cualquier sistema).
BLOQUEO="$RESPALDOS_DIR/.en-curso"
if ! mkdir "$BLOQUEO" 2>/dev/null; then
  if [ -n "$(find "$BLOQUEO" -maxdepth 0 -mmin +360 2>/dev/null)" ]; then rmdir "$BLOQUEO" 2>/dev/null && mkdir "$BLOQUEO"; else fallar "Ya hay un respaldo en curso ($BLOQUEO)."; fi
fi
SELLO=$(date '+%Y%m%d-%H%M%S')
DESTINO="$RESPALDOS_DIR/${DB_DATABASE}-${SELLO}.dump"
TEMPORAL="$DESTINO.parcial"
limpiar() { rm -f "$TEMPORAL" "$RESPALDOS_DIR/archivos-${SELLO}.tar.gz.parcial"; rmdir "$BLOQUEO" 2>/dev/null || true; }
trap limpiar EXIT INT TERM

registrar "Respaldando la base «$DB_DATABASE» en $DB_HOST:$DB_PORT…"
# Formato personalizado de pg_dump: ya va comprimido (nivel 9), se restaura con pg_restore y permite restaurar solo una parte.
# La clave viaja en la variable PGPASSWORD (no en la línea de comandos, donde cualquiera la vería con «ps»).
if ! PGPASSWORD="$DB_PASSWORD" $PG_DUMP_CMD --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
      --format=custom --compress=9 --no-owner --no-privileges > "$TEMPORAL"; then
  fallar "pg_dump falló: no se guardó nada nuevo y los respaldos anteriores siguen intactos."
fi
[ -s "$TEMPORAL" ] || fallar "El respaldo salió vacío: se descarta y los anteriores siguen intactos."
mv "$TEMPORAL" "$DESTINO"
registrar "Listo: $DESTINO ($(du -h "$DESTINO" | cut -f1))"

if [ "$CON_ARCHIVOS" = 1 ]; then
  ARCHIVOS="$RESPALDOS_DIR/archivos-${SELLO}.tar.gz"
  registrar "Respaldando el volumen $VOLUMEN_STORAGE (fotos y adjuntos)…"
  docker run --rm -v "$VOLUMEN_STORAGE":/datos:ro -v "$RESPALDOS_DIR":/destino alpine:3.20 \
    tar -czf "/destino/archivos-${SELLO}.tar.gz.parcial" -C /datos app/public \
    || fallar "No pude respaldar el volumen $VOLUMEN_STORAGE (¿existe? docker volume ls)."
  mv "$RESPALDOS_DIR/archivos-${SELLO}.tar.gz.parcial" "$ARCHIVOS"
  chmod 600 "$ARCHIVOS"
  registrar "Listo: $ARCHIVOS ($(du -h "$ARCHIVOS" | cut -f1))"
fi

# Rotación: solo después de que el respaldo de hoy quedó bien guardado.
BORRADOS=0
for patron in "${DB_DATABASE}-*.dump" "archivos-*.tar.gz"; do
  for viejo in $(find "$RESPALDOS_DIR" -maxdepth 1 -type f -name "$patron" -mtime +"$((DIAS_A_GUARDAR - 1))" 2>/dev/null); do
    rm -f "$viejo" && BORRADOS=$((BORRADOS + 1))
  done
done
registrar "Rotación: $BORRADOS respaldo(s) con más de $DIAS_A_GUARDAR días borrado(s). Quedan $(find "$RESPALDOS_DIR" -maxdepth 1 -type f -name "${DB_DATABASE}-*.dump" | wc -l | tr -d ' ') de la base."
