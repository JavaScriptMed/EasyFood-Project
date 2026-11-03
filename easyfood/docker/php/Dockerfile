FROM php:8.4-fpm-alpine

# El UID/GID del usuario del contenedor se alinea con el tuyo para que los archivos que crea
# (storage, vendor, caché) no queden como "root" en tu carpeta.
ARG HOST_UID=1000
ARG HOST_GID=1000

RUN apk add --no-cache git unzip shadow icu-libs libzip postgresql-libs \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl pdo_pgsql pgsql zip \
    && apk del .build-deps \
    && groupmod -g "${HOST_GID}" www-data \
    && usermod -u "${HOST_UID}" -g "${HOST_GID}" www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-easylunch.ini

WORKDIR /var/www/html
USER www-data
