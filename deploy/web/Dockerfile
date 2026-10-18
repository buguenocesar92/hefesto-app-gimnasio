# ---------------------------------------------------------------------------
# Imagen de la interfaz (Nuxt con SSR).
# ---------------------------------------------------------------------------

FROM node:22.11.0-bookworm-slim AS compilacion

WORKDIR /app

# Primero el manifiesto, para que la capa de dependencias se reutilice
# mientras no cambien las versiones.
COPY web/package.json web/package-lock.json* ./

RUN npm ci --no-audit --no-fund

COPY web/ ./

RUN npm run build

# Se borgan los archivos de desarrollo de la imagen final.
RUN npm prune --omit=dev

# ---------------------------------------------------------------------------

FROM node:22.11.0-bookworm-slim AS ejecucion

ENV NODE_ENV=production
ENV NITRO_HOST=0.0.0.0
ENV NITRO_PORT=3000

WORKDIR /app

COPY --from=compilacion /app/node_modules ./node_modules
COPY --from=compilacion /app/.output ./.output

# El proceso corre como un usuario sin privilegios.
USER node

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]