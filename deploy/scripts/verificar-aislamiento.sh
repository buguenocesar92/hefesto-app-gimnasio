#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Verificación del aislamiento entre gimnasios antes de recibir socios.
#
# POR QUÉ ESTE SCRIPT Y NO SOLO EL COMANDO DE ARTISAN
# ---------------------------------------------------
# Porque el comando vive dentro del contenedor y este script corre EN el
# servidor, antes de levantar el servicio. Si la verificación falla, el
# despliegue se detiene acá, con la base ya levantada pero sin tráfico.
#
# Con superusuario, o con una política sin `WITH CHECK`, el sistema funciona
# y muestra los datos de otro gimnasio sin dar ningún aviso. Esta es la
# última barrera antes de que eso llegue a un socio.
#
# USO
# ---
#   deploy/scripts/verificar-aislamiento.sh
#
# Las palabras clave del shell van sin traducir.
# ---------------------------------------------------------------------------

set -euo pipefail

log() {
    echo "[verificar $(date '+%Y-%m-%d %H:%M:%S')] $*"
}

fallar() {
    echo "[verificar $(date '+%Y-%m-%d %H:%M:%S')] ERROR: $*" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fallar "No se encontró docker en el PATH."

log "Revisando el aislamiento entre gimnasios..."

if ! docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
        run --rm api php artisan gimnasio:verificar-aislamiento; then
    fallar "El aislamiento NO está bien puesto. No abra el gimnasio a socios hasta corregirlo."
fi

# --- Segunda comprobación, en SQL directo ---------------------------------
#
# El comando de Artisan mira lo que la aplicación cree. Esta mira lo que la
# base realmente tiene. Son cosas distintas: un script de despliegue anterior
# pudo haber creado una tabla nueva sin pasar por la migración, y esa tabla
# no va a estar en la lista del comando.

log "Revisando desde SQL que no haya tablas de negocio sin RLS..."

: "${DB_DATABASE:?falta DB_DATABASE}"
: "${DB_USERNAME:?falta DB_USERNAME}"

consulta="
SELECT c.relname
  FROM pg_class c
  JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public'
   AND c.relkind = 'r'
   AND c.relname <> 'migrations'
   AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns col
         WHERE col.table_schema = 'public'
           AND col.table_name = c.relname
           AND col.column_name = 'tenant_id'
   )
 ORDER BY c.relname;"

sin_tenant="$(docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    exec -T postgres \
    psql --username="$DB_USERNAME" --dbname="$DB_DATABASE" --tuples-only --no-align --command="$consulta")"

# `tenants` es la excepción consciente: se consulta en el inicio de sesión,
# cuando todavía no hay contexto. Está compensada con la vista
# `vw_gimnasios_publicos`, que expone solo tres columnas.
esperadas="tenants"

for tabla in $sin_tenant; do
    if [ "$tabla" != "$esperadas" ]; then
        fallar "La tabla public.$tabla tiene datos y no tiene columna tenant_id.
No se puede proteger con Row Level Security. Revise la migración
database/migrations/2026_01_20_000015_activar_row_level_security.php"
    fi
done

log "Todas las tablas de negocio tienen columna tenant_id."

log "Aislamiento verificado. El gimnasio puede recibir socios."