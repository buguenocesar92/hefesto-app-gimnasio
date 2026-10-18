#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Entrada del contenedor de la API.
#
# El punto de este script es hacer FALLAR el despliegue con un mensaje claro
# cuando algo quedó mal configurado, en vez de levantar un contenedor que
# responde con errores 500 a todas partes.
#
# Lo que comprueba:
#   1. que APP_KEY esté definida;
#   2. que la API pueda conectarse a PostgreSQL;
#   3. que la Row Level Security esté puesta.
#
# El paso 3 es un AVISO, no un bloqueo: si se hiciera en cada arranque, un
# contenedor que arranca con la base de datos todavía levantándose caería en
# bucle. El comando que sí bloquea el despliegue se corre aparte, antes de
# levantar el servicio. Ver docs/04 y docs/08.
#
# Las palabras clave del shell van sin traducir: `while`, `for`, `do`, `done`.
# ---------------------------------------------------------------------------

set -euo pipefail

cd /var/www/html

echo "[entrada] PHP $(php -v | head -n 1)"

# ---------------------------------------------------------------------------
# 1. Configuración mínima
# ---------------------------------------------------------------------------
if [ -z "${APP_KEY:-}" ]; then
    echo "[entrada] ERROR: APP_KEY no está definida."
    echo "[entrada] Genere una con: docker compose run --rm api php artisan key:generate --show"
    exit 1
fi

# ---------------------------------------------------------------------------
# 2. Espera a PostgreSQL
# ---------------------------------------------------------------------------
# El `true` final de set_config es alcance de TRANSACCIÓN. Con un `SET`
# normal el valor sobreviviría a la petición, y el worker de colas —que
# reutiliza la misma conexión para trabajos de gimnasios distintos — podría
# leer los datos de otro gimnasio. Ver docs/03.
intentos_maximos="${ESPERA_INTENTOS:-30}"

echo "[entrada] esperando a PostgreSQL (máximo ${intentos_maximos} intentos)..."

intentos=0
while [ "$intentos" -lt "$intentos_maximos" ]; do
    if php -r 'exit(@pg_connect("host=".(getenv("DB_HOST") ?: "127.0.0.1")." port=".(getenv("DB_PORT") ?: "5432")." dbname=".getenv("DB_DATABASE")." user=".getenv("DB_USERNAME")." connect_timeout=2")) ? 0 : 1);'; then
        echo "[entrada] PostgreSQL responde."
        break
    fi

    intentos=$((intentos + 1))
    echo "[entrada] todavía no responde (intento ${intentos} de ${intentos_maximos})"
    sleep 2
done

if [ "$intentos" -ge "$intentos_maximos" ]; then
    echo "[entrada] ERROR: PostgreSQL no respondió a tiempo."
    exit 1
fi

# Las migraciones NO se corren en cada arranque de forma automática: un
# despliegue que cambia el esquema sin que nadie lo decida es un despliegue
# que puede cortar el servicio. Ver docs/04, sección "Despliegue".
if [ "${GIMNASIO_CORRER_MIGRACIONES:-false}" = "true" ]; then
    echo "[entrada] aplicando migraciones..."
    php artisan migrate --force --no-interaction
    php artisan config:cache
    php artisan route:cache
fi

# ---------------------------------------------------------------------------
# 3. Aviso sobre el aislamiento (no bloqueante)
# ---------------------------------------------------------------------------
echo "[entrada] revisando el aislamiento entre gimnasios..."

if php artisan gimnasio:verificar-aislamiento; then
    echo "[entrada] aislamiento verificado."
else
    echo "[entrada] AVISO: el aislamiento entre gimnasios NO está bien puesto."
    echo "[entrada] El servicio puede arrancar, pero no debe recibir socios hasta corregirlo."
fi

exec "$@"