#!/usr/bin/env bash
# Base y servidor de la DEMO (datos de ejemplo), aparte de la de desarrollo (easylunch) y de la de pruebas (easylunch_pruebas).
#
#   scripts/demo.sh preparar   crea la base easylunch_demo si falta, la migra desde cero, carga los datos de ejemplo y crea un admin
#   scripts/demo.sh servir     levanta la API de la demo en http://localhost:8001 (contenedor easylunch-demo)
#   scripts/demo.sh detener    apaga la API de la demo
#
# Los correos de la demo van al log (storage/logs/enlaces.log muestra los enlaces); para mandarlos de verdad: DEMO_MAILER=smtp scripts/demo.sh servir
# Solo toca easylunch_demo. Cuentas de ejemplo: contraseña Prueba1234 (admin: demo@easylunch.test / Admin1234).
set -euo pipefail
cd "$(dirname "$0")/.."

# El nombre de la base va escrito tal cual en cada comando (no en una variable) para no equivocarse de base.
esperar_db() { docker compose up -d db >/dev/null; until docker compose exec -T db sh -c 'pg_isready -U "$POSTGRES_USER" -q'; do sleep 1; done; }

case "${1:-}" in
  preparar)
    esperar_db
    docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d postgres -tc "SELECT 1 FROM pg_database WHERE datname = '"'"'easylunch_demo'"'"'" | grep -q 1 || psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE easylunch_demo"'
    echo "Base: $(docker compose exec -T -e DB_DATABASE=easylunch_demo app php artisan tinker --execute='echo DB::connection()->getDatabaseName();' | tail -1)"
    docker compose exec -T -e DB_DATABASE=easylunch_demo app php artisan migrate:fresh --force
    docker compose exec -T -e DB_DATABASE=easylunch_demo -e SEED_PASSWORD=Prueba1234 app php artisan db:seed --class=DatosDeEjemploSeeder --force
    docker compose exec -T -e DB_DATABASE=easylunch_demo app php artisan easylunch:admin-demo
    echo "Listo. Levanta la API con: scripts/demo.sh servir"
    ;;
  servir)
    esperar_db
    docker rm -f easylunch-demo >/dev/null 2>&1 || true
    docker compose run -d --rm --name easylunch-demo -p 8001:8001 \
      -e DB_DATABASE=easylunch_demo -e APP_URL=http://localhost:8001 \
      -e MAIL_MAILER="${DEMO_MAILER:-log}" \
      -e CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000 -e FRONTEND_URL=http://localhost:3000 \
      app php -S 0.0.0.0:8001 -t public >/dev/null
    docker exec easylunch-demo php artisan storage:link >/dev/null 2>&1 || true
    echo "API de la demo: http://localhost:8001  (base easylunch_demo). Front: NEXT_PUBLIC_API_URL=http://localhost:8001"
    ;;
  detener)
    docker rm -f easylunch-demo >/dev/null 2>&1 || true
    echo "API de la demo apagada."
    ;;
  *)
    echo "Uso: scripts/demo.sh preparar | servir | detener" >&2
    exit 1
    ;;
esac
