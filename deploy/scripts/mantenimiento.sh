#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Cierre de visitas abiertas y detección de socios en riesgo.
#
# POR QUÉ CORRE POR CRON Y NO AL REGISTRAR CADA ENTRADA
# -----------------------------------------------------
# Registrar una entrada es la operación que hace recepción con la cola de
# gente esperando. Si cada entrada abriera un trabajo de cola, un corte de
# energía se convertiría en una avalancha de procesos compiten por la misma
# conexión.
#
# La limpieza se hace una vez por hora, que es suficiente: una visita abierta
# de más no cuesta nada durante una hora, y la regla de "una visita abierta
# por socio" está puesta en la base de datos, no acá.
#
# USO
# ---
#   deploy/scripts/mantenimiento.sh
#
# Las palabras clave del shell van sin traducir.
# ---------------------------------------------------------------------------

set -euo pipefail

log() {
    echo "[mantenimiento $(date '+%Y-%m-%d %H:%M:%S')] $*"
}

fallar() {
    echo "[mantenimiento $(date '+%Y-%m-%d %H:%M:%S')] ERROR: $*" >&2
    exit 1
}

compose="docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod"

command -v docker >/dev/null 2>&1 || fallar "No se encontró docker en el PATH."

[ -f .env.prod ] || fallar "No existe .env.prod en el directorio actual."

# --- Cierre de visitas abiertas --------------------------------------------
#
# Los cortes de energía y los fallos del turno dejan entradas sin salida. Se
# cierran solas, marcadas como cerradas por el sistema y con el evento en la
# bitácora: recepción puede ver que la salida no la escribió una persona.

log "Cerrando visitas abiertas que superaron el máximo de horas..."

if ! $compose exec -T api php artisan gimnasio:cerrar-visitas-abiertas; then
    fallar "Falló el cierre de visitas abiertas."
fi

# --- Detección de socios en riesgo -----------------------------------------
#
# Este es el producto: socios con membresía vigente que dejaron de venir.
# No manda correos ni mensajes; deja la lista para que el dueño decida.

log "Buscando socios que dejaron de venir..."

if ! $compose exec -T api php artisan gimnasio:detectar-inactivos; then
    fallar "Falló la detección de socios inactivos."
fi

# --- Contadores en memoria --------------------------------------------------
#
# Los conteos del panel se recalculan al cierre del día. Redis no guarda
# datos que no se puedan perder: si Redis se cae, el siguiente cálculo los
# vuelve a armar desde la base.

log "Refrescando los contadores del panel..."

if ! $compose exec -T api php artisan cache:clear; then
    log "AVISO: no se pudo limpiar la caché. Los contadores se recalcularán igual."
fi

log "Mantenimiento terminado."