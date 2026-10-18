# 04 - Instalación

## 1. Requisitos

| Herramienta | Versión | Para qué |
|---|---|---|
| Docker | 24 o superior | Corre PostgreSQL, Redis, la API y la interfaz |
| Docker Compose | v2 | Orquesta los servicios |
| Git | Cualquiera | Código |

No hace falta PHP, Composer ni Node en el equipo: todo corre dentro de los
contenedores.

**Nota honesta:** en el entorno donde se escribió este código no había Docker,
ni PHP, ni Composer. Los pasos de esta guía **no se ejecutaron**. Ver
[10](10-PRUEBAS-Y-ESTADO.md).

## 2. Desarrollo local

### 2.1 Preparar el entorno

```bash
cd apps/gimnasio

cp .env.example .env
```

Después complete lo que no tiene valor por defecto:

```bash
# La clave de aplicación
docker compose -f deploy/docker-compose.dev.yml run --rm api \
    php artisan key:generate --show
```

Copie el resultado a `APP_KEY` en `.env`.

Los secretos de Authentik también necesitan valores reales:

```bash
openssl rand -hex 32
```

Use la salida en `AUTHENTIK_SECRET_KEY`, `AUTHENTIK_BOOTSTRAP__TOKEN` y
`AUTHENTIK_BOOTSTRAP__PASSWORD`.

### 2.2 Levantar

```bash
docker compose -f deploy/docker-compose.dev.yml up -d
```

Esto levanta:

| Servicio | Dirección |
|---|---|
| Interfaz | http://localhost:3000 |
| API | http://localhost:8080 |
| PostgreSQL | localhost:5432 |
| Redis | localhost:6379 |
| Authentik | http://localhost:9000 |

### 2.3 Migraciones y datos de ejemplo

En desarrollo, el contenedor de la API las corre solo (`GIMNASIO_CORRER_MIGRACIONES=true`).

Para cargar el gimnasio de ejemplo:

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
    php artisan migrate --force

docker compose -f deploy/docker-compose.dev.yml exec api \
    php artisan db:seed
```

La semilla crea el **Gimnasio Fuerza** con seis socios, tres planes, membresías
con cobros, un mes gratis de diciembre, visitas que cruzan la medianoche, una
rutina con ocho semanas de progreso y un invitado.

Tres socios están puestos a propósito para que el panel se entienda de una:

- **Andrés**: viene todas las semanas. El sistema no lo marca.
- **Patricia**: dejó de venir hace 26 días, pero **paga al día**. Es el caso
  recuperable, y el que sale arriba en el panel.
- **Fernanda**: nunca ha venido. Es otro problema y aparece aparte.

### 2.4 Comprobar

```bash
# El aislamiento entre gimnasios
docker compose -f deploy/docker-compose.dev.yml exec api \
    php artisan gimnasio:verificar-aislamiento

# Las pruebas
docker compose -f deploy/docker-compose.dev.yml exec api \
    php artisan test
```

## 3. Autentik

La API no tiene contraseñas: la identidad vive en Authentik.

### 3.1 Configurar el proveedor

En la consola de Authentik (**http://localhost:9000**):

1. Crear un proveedor **OpenID Connect** si no existe.
2. Crear una aplicación de tipo **Provider** apuntando al proveedor.
3. **Asignar el grupo del administrador** a la aplicación, para que el claim
   `groups` llegue en el token.

> **Pendiente de verificar.** Que el claim `groups` se entregue depende de la
> configuración de alcance en Authentik, y no se pudo probar contra una
> instancia real en este entorno. Si el grupo no llega,
> `VerificadorIdToken` devuelve un conjunto vacío y el usuario queda con el
> rol más bajo (`encargado`). Es el comportamiento seguro, pero **no es lo que
> el dueño quiere para su propia cuenta**. Ver
> [05](05-CONFIGURACION.md) y [10](10-PRUEBAS-Y-ESTADO.md).

### 3.2 Permisos del primer usuario

Los usuarios que crea la semilla tienen un `authentik_sub` **ficticio**:

```
⚠️  ADVERTENCIA
Las cuentas de ejemplo NO pueden iniciar sesión hasta que sus identidades
existan en Authentik con ese mismo `sub`, o hasta que se borre la semilla.

Para trabajar en desarrollo sin Authentik, cree usuarios reales desde la
consola y desactive el grupo de administrador; el rol se administra en el
portal, no en Authentik.
```

## 4. Producción

### 4.1 Preparar el entorno

```bash
cp .env.example .env.prod
```

Cambie **todo** lo que diga `CAMBIAR`, y también:

| Variable | Por qué |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false`. Con `true`, un error muestra la consulta SQL con datos de socios |
| `SESSION_SECURE_COOKIE` | `true` |
| `AUTHENTIK_ISSUER` | Debe terminar en barra y coincidir con el proveedor |
| `APP_DOMAIN` | El dominio real, para el certificado de Caddy |

### 4.2 Construir las imágenes

```bash
docker build -f deploy/api/Dockerfile -t registro.ejemplo.cl/gimnasio-api:1.0.0 .
docker build -f deploy/web/Dockerfile -t registro.ejemplo.cl/gimnasio-web:1.0.0 .
```

Las versiones están **fijadas** en los Dockerfiles y en el Compose: `php:8.3-fpm-bookworm`,
`node:22.11.0-bookworm-slim`, `composer:2.8.12`. Una imagen con `latest` cambia
sola, y un despliegue que un martes funcionó puede dejar de funcionar el
miércoles sin que nadie haya tocado nada.

### 4.3 Desplegar

```bash
# 1. Migraciones (incluye la de Row Level Security).
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan migrate --force

# 2. Verificar el aislamiento. Si falla, NO siga.
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan gimnasio:verificar-aislamiento

# 3. Segunda verificación, en SQL directo, desde el servidor.
deploy/scripts/verificar-aislamiento.sh

# 4. Levantar.
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod up -d
```

**Las migraciones NO se corren en cada arranque.** Un despliegue que cambia
el esquema sin que nadie lo decida es un despliegue que puede cortar el
servicio. Se aplican una vez, a mano, en el paso 1.

### 4.4 Después de levantar

```bash
# Cargar el primer gimnasio y su administrador.
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    exec api php artisan gimnasio:crear --slug=fuerza --nombre="Gimnasio Fuerza"
```

> **Pendiente:** el comando `gimnasio:crear` **no está implementado** en esta
> versión. El alta del primer gimnasio se hace por consola de Artisan o
> diretamente en la base. Está anotado en
> [10](10-PRUEBAS-Y-ESTADO.md), sección 4.

## 5. Verificar que todo quedó bien

```bash
# La salud del servicio
curl -s http://localhost:8080/api/v1/salud

# El aislamiento
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    exec api php artisan gimnasio:verificar-aislamiento

# El rol de la base no puede ser superusuario
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    exec postgres psql -U "$DB_USERNAME" -d "$DB_DATABASE" \
    -c "SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user;"
```

Si `rolsuper` o `rolbypassrls` sale en `true`, **el aislamiento no existe**,
aunque todo lo demás esté bien. Corregirlo:

```sql
ALTER ROLE gimnasio NOSUPERUSER NOBYPASSRLS;
```

## 6. Problemas frecuentes

| Síntoma | Causa probable |
|---|---|
| `No hay contexto de gimnasio en la conexion` | Una consulta corrió fuera del middleware, o un worker de cola no abre su transacción |
| `visitas_una_abierta_por_socio` viola la restricción | Dos entradas simultáneas. Es el índice funcionando: se rechaza la segunda |
| El panel muestra cero visitas | Redis sin clave. `php artisan cache:clear` y se recalcula |
| `The view public.vw_visitas does not exist` | Faltó correr la migración 11 antes que la 15 |
| 401 en todas las peticiones | `SESSION_SECURE_COOKIE=true` sin HTTPS, o `APP_URL` no coincide con el dominio |
| Los usuarios de ejemplo no entran | Sus `authentik_sub` son ficticios. Ver sección 3.2 |

## 7. Desinstalar

```bash
docker compose -f deploy/docker-compose.dev.yml down -v
```

El `-v` **borra los volúmenes**: se pierde la base con todos los socios.
Haga un respaldo antes si quiere conservarlos. Ver [08](08-RESPALDOS.md).