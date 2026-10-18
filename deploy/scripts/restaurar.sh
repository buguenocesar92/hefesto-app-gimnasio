#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Restauración de la base de datos desde un respaldo.
#
# ADVERTENCIA
# -----------
# Esto REEMPLAZA los datos. Si se ejecuta sobre la base equivocada, no hay
# forma de volver atrás. Por eso el script pide el nombre del respaldo de
# forma explícita y nunca adivina cuál era el último.
#
# USO
# ---
#   deploy/scripts/restaurar.sh /var/backups/gimnasio/gimnasio_2026-01-20_030000.dump
#
# Las palabras clave del shell van sin traducir.
# ---------------------------------------------------------------------------

set -euo pipefail

log() {
    echo "[restaurar $(date '+%Y-%m-%d %H:%M:%S')] $*"
}

fallar() {
    echo "[restaurar $(date '+%Y-%m-%d %H:%M:%S')] ERROR: $*" >&2
    exit 1
}

# --- Argumentos -------------------------------------------------------------

if [ "$#" -lt 1 ]; then
    fallar "Falta el nombre del archivo de respaldo.
Uso: $0 /ruta/al/respaldo.dump"
fi

respaldo="$1"

[ -f "$respaldo" ] || fallar "No existe el archivo: $respaldo"
[ -s "$respaldo" ] || fallar "El archivo está vacío: $respaldo"

: "${DB_HOST:?falta DB_HOST}"
: "${DB_DATABASE:?falta DB_DATABASE}"
: "${DB_USERNAME:?falta DB_USERNAME}"
: "${PGPASSWORD:?falta PGPASSWORD}"

# --- Comprobación previa ---------------------------------------------------
#
# Se revisa el respaldo ANTES de tocar la base. Si el archivo está roto, es
# mejor enterarse ahora que halfway, con la base a medias.

log "Comprobando el respaldo $respaldo..."

if ! pg_restore --list "$respaldo" >/dev/null 2>&1; then
    fallar "El respaldo no se puede leer. No se tocó la base."
fi

log "El respaldo se puede leer. Destino: $DB_HOST / $DB_DATABASE."

if [ "${CONFIRMAR:-}" != "si" ]; then
    echo "Esta operación reemplaza los datos de $DB_DATABASE."
    echo "Para continuar, vuelva a ejecutarlo con: CONFIRMAR=si $0 $respaldo"
    exit 1
fi

# --- Restauración -----------------------------------------------------------
#
# --clean borra los objetos antes de recrearlos, y --if-exists evita que
# falle porque algo no estaba. --single-transaction evita dejar la base a
# medias si algo sale mal a mitad de camino.

log "Restaurando..."

if ! pg_restore \
        --host="$DB_HOST" \
        --port="${DB_PORT:-5432}" \
        --username="$DB_USERNAME" \
        --dbname="$DB_DATABASE" \
        --no-owner \
        --clean \
        --if-exists \
        --single-transaction \
        "$respaldo"; then
    fallar "Falló la restauración. Con --single-transaction la base quedó como estaba."
fi

log "Restauración terminada."

# --- Comprobación posterior -------------------------------------------------
#
# Después de restaurar hay que confirmar que la base responde Y que el
# aislamiento sigue puesto. Un respaldo restaurado sobre una base sin RLS
# dejaría el sistema andando y abierto entre gimnasios.

log "Comprobando que la base responde..."

total_tablas="$(psql \
    --host="$DB_HOST" \
    --port="${DB_PORT:-5432}" \
    --username="$DB_USERNAME" \
    --dbname="$DB_DATABASE" \
    --tuples-only \
    --no-align \
    --command="SELECT count(*) FROM pg_tables WHERE schemaname = 'public';")"

log "La base tiene ${total_tablas} tablas en el esquema public."

if [ "${total_tablas}" -lt 5 ]; then
    fallar "La base restaurada tiene pocas tablas. ¿El respaldo era el correcto?"
fi

log "Listo. Revise ahora php artisan gimnasio:verificar-aislamiento."