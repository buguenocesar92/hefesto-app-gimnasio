# ---------------------------------------------------------------------------
# Imagen de la API.
#
# Versiones fijadas a propósito. Una imagen con `latest` cambia de versión
# sola, y un despliegue que un martes funcionó puede dejar de funcionar el
# miércoles sin que nadie haya tocado nada.
# ---------------------------------------------------------------------------

FROM php:8.3-fpm-bookworm

# Dependencias del sistema que Laravel necesita para hablar con PostgreSQL.
# pdo_pgsql es la extensión de base de datos; pcntl y posix las usa el worker
# de colas; opcache y zip acortan el arranque.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libpq-dev \
        libzip-dev \
        unzip \
        curl \
    && docker-php-ext-install pdo_pgsql pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer, desde la imagen oficial de Composer (también fijada).
COPY --from=composer:2.8.12 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Se copia primero el manifiesto para que la capa de dependencias se
# reutilice mientras no cambie el composer.json.
COPY api/composer.json api/composer.lock* ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --prefer-dist

COPY api/ ./

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/api/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]