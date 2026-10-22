# ---------------------------------------------------------------------------
# Imagen de la API.
#
# Versiones fijadas a propósito. Una imagen con `latest` cambia de versión
# sola, y un despliegue que un martes funcionó puede dejar de funcionar el
# miércoles sin que nadie haya tocado nada.
# ---------------------------------------------------------------------------

# =============================================================================
# CVE-2026-109618 / CVE-2026-109619 (PHP2Shell, 2026-10-22)
# =============================================================================
# La imagen se construye con PHP 8.3.36, que es la version PARCHEADA
# de la serie 8.3. Las versiones parcheadas de PHP son
# 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12.
#
# Verificado el 2026-10-22 en el servidor de desarrollo, con el tag exacto:
#
#     $ docker run --rm php:8.3-fpm-bookworm php -v
#     PHP 8.3.36 (cli) (built: Oct 21 2026 16:00:25)
#
# El tag `php:8.3-fpm-bookworm` es FLOTANTE: no se cambia, se deja constancia.
# El tag resuelve hoy a PHP 8.3.36, que es la version parcheada de la serie
# 8.3. Se deja escrito porque un tag flotante puede mudar sin que nadie lo
# note: la comprobacion va en el candado (api/tests/Unit/), que falla si el
# tag vuelve a quedar por debajo de la version parcheada.
#
# OJO: LA IMAGEN DESPLIEGADA HAY QUE RECONSTRUIRLA. El parche de PHP entra en
# el contenedor por la IMAGEN, no por este repositorio: mientras no se
# reconstruya y se redespliegue, el contenedor sigue con el PHP viejo. El
# endurecimiento de mas abajo (el ini y el pool de php-fpm) es la red de seguridad
# para ese mientras, y lo que sobrevive a un redeploy.
FROM php:8.3-fpm-bookworm

# Dependencias del sistema que Laravel necesita para hablar con PostgreSQL.
# pdo_pgsql es la extensión de base de datos; pcntl y posix las usa el worker
# de colas; opcache y zip acortan el arranque.
RUN apt-get update \
    && apt-get upgrade -y \
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

# Endurecimiento PHP2Shell (CVE-2026-109618 / CVE-2026-109619):
# la via CONFIRMADA (session.upload_progress) apagada, y los
# trabajadores de php-fpm reciclados cada 100 peticiones.
COPY deploy/api/docker/99-php2shell-hardening.ini /usr/local/etc/php/conf.d/99-php2shell-hardening.ini
COPY deploy/api/docker/zz-php2shell-fpm.conf /usr/local/etc/php-fpm.d/zz-php2shell-hardening.conf
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]