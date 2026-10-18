#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Respaldo de la base de datos.
#
# QUÉ RESPALDA
# ------------
# Una sola base con todos los gimnasios. Los datos personales van dentro, así
# que el archivo resultante es TAN SENSIBLE como la base misma: vive en el
# volumen de respaldos, nunca en el repositorio, y el respaldo se cifra o el
# disco del servidor está cifrado.
#
# POR QUÉ `pg_dump` Y NO UNA COPIA DE ARCHIVOS
# -------------------------------------------
# Copiar el directorio de datos de PostgreSQL con la base corriendo produce un
# archivo que a veces no arranca. `pg_dump` habla con el motor y garantiza una
# copia consistente.
#
# USO
# ---
#   deploy/scripts/respaldar.sh
#
# LAS PALABRAS CLAVE DEL SHELL VAN SIN TRADUCIR
# ---------------------------------------------
# `while`, `for`, `do`, `done`, `until`, `case`, `esac`. Traducirlas rompe el
# contenedor, porque `bash` no entiende "hasta" ni "hecho".
# ---------------------------------------------------------------------------

set -euo pipefail

# --- Configuración ----------------------------------------------------------

directorio_respaldos="${DIRECTORIO_RESPALDOS:-/var/backups/gimnasio}"
archivo_base="$(date +%Y-%m-%d_%H%M%S)"
retencion_dias="${RETENCION_DIAS:-30}"

log() {
    echo "[respaldar $(date '+%Y-%m-%d %H:%M:%S')] $*"
}

fallar() {
    echo "[respaldar $(date '+%Y-%m-%d %H:%M:%S')] ERROR: $*" >&2
    exit 1
}

# --- Comprobaciones previas -------------------------------------------------

command -v pg_dump >/dev/null 2>&1 || fallar "No se encontró pg_dump en el PATH."

: "${DB_HOST:?falta DB_HOST}"
: "${DB_DATABASE:?falta DB_DATABASE}"
: "${DB_USERNAME:?falta DB_USERNAME}"
: "${PGPASSWORD:?falta PGPASSWORD}"

mkdir -p "$directorio_respaldos" || fallar "No se pudo crear $directorio_respaldos."

destino="$directorio_respaldos/gimnasio_${archivo_base}.dump"

# --- Respaldo ---------------------------------------------------------------

log "Respaldando la base $DB_DATABASE desde $DB_HOST..."

# --clean y --if-exists para que el respaldo se pueda restaurar sobre una base
# que ya tiene algo. --no-owner: el dueño de la base en el servidor puede no
# ser el mismo nombre que tenía cuando se creó.
if ! pg_dump \
        --host="$DB_HOST" \
        --port="${DB_PORT:-5432}" \
        --username="$DB_USERNAME" \
        --dbname="$DB_DATABASE" \
        --format=custom \
        --compress=9 \
        --no-owner \
        --clean \
        --if-exists \
        --file="$destino"; then
    fallar "Falló pg_dump. No se creó un respaldo usable."
fi

[ -s "$destino" ] || fallar "El archivo de respaldo quedó vacío: $destino."

tamano="$(du -h "$destino" | cut -f1)"
log "Respaldo terminado: $destino ($tamano)"

# --- Verificación -----------------------------------------------------------
#
# Un respaldo que nunca se probó restaurar no es un respaldo. `pg_restore`
# comprobando el índice de la tabla no escribe nada en ninguna base, pero
# obligaría a hacerlo. Lo más barato que detecta un archivo corrupto es
# listarlo: si el archivo está truncado, el listado falla.

log "Comprobando que el respaldo se puede leer..."

if ! pg_restore --list "$destino" >/dev/null 2>&1; then
    fallar "El respaldo se creó pero no se puede leer. Revise el disco."
fi

log "El respaldo se verificó correctamente."

# --- Limpieza ---------------------------------------------------------------
#
# Se borran los respaldos con más de RETENCION_DIAS días. Con `--if-exists`
# para que el `-delete` no rompa si el archivo ya no está.

log "Borrando respaldos con más de ${retencion_dias} días..."

anteriores="$(find "$directorio_respaldos" -maxdepth 1 -type f -name 'gimnasio_*.dump' -mtime "+${retencion_dias}" -print)"

conteo=0

while IFS= read -r archivo; do
    if [ -n "$archivo" ]; then
        log "  borrando $(basename "$archivo")"
        rm -- "$archivo"
        conteo=$((conteo + 1))
    fi
done <<< "$anteriores"

log "Se borraron ${conteo} respaldo(s) antiguo(s)."
log "Listo."