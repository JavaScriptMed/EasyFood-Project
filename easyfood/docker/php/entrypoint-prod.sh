#!/bin/sh
# Arranque de cualquier contenedor de la app en producción (php-fpm, scheduler o worker).
set -e
cd /var/www/html

# El volumen «storage» llega vacío la primera vez: se arman las carpetas que Laravel necesita.
mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
  echo "Falta APP_KEY en .env.production (php artisan key:generate --show)." >&2
  exit 1
fi
if [ "${APP_ENV:-}" != "production" ] || [ "${APP_DEBUG:-false}" != "false" ]; then
  echo "En producción hace falta APP_ENV=production y APP_DEBUG=false." >&2
  exit 1
fi

# La configuración se lee una sola vez, de las variables del contenedor (no hay archivo .env dentro de la imagen).
php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan event:cache --no-interaction >/dev/null

exec "$@"
