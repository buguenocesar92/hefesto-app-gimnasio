# 08 - Respaldos

Un respaldo que nunca se probó restaurar **no es un respaldo**. Es un archivo
en un disco del que nadie está seguro.

Este documento explica qué se respalda, cómo, y cómo se comprueba que sirve.

---

## 1. Qué hay que respaldar

| Cosa | ¿Importante? | Por qué |
|---|---|---|
| **Base de datos** | **Sí, siempre** | Tiene todos los socios, sus visitas y sus pagos |
| Archivos de Authentik | Sí | Sin ellos nadie puede entrar |
| Configuración de Authentik (YAML) | Sí | Está en la base, pero el YAML es la fuente |
| Certificados TLS | Sí | Renovarlos desde cero rompe la confianza |
| Archivos de la aplicación | **No** | Se reconstruyen desde el repositorio |
| Volumen de Redis | **No** | Caché y colas. Se rehacen solos |
| Volumen de medios de Authentik | **No** | No se suben archivos en este producto |
| `.env.prod` | Sí, pero aparte | **Nunca dentro de un respaldo de la base** |

### El volumen de Redis no se respalda

Redis guarda el caché y las colas, y **nada que no se pueda recalcular desde la
base**. Si se borra, el sistema funciona igual: el siguiente cálculo de
contadores los vuelve a armar, y los trabajos en cola se pierden, que en este
producto son segundos.

### `.env.prod` va aparte

Contiene las claves y las contraseñas. Meterlo en el mismo archivo que los
datos de los socios significa que una filtración expone las dos cosas.

## 2. Cuánto pesa y cada cuánto

Un gimnasio chico son unos pocos cientos de socios, y cada socio tiene un
historial de visitas de años. La base completa es de **decenas de megabytes**.

| Datos | Frecuencia | Retención |
|---|---|---|
| **Completo** | Diario | 30 días |
| **Antes de migrar o desplegar** | Manual | Hasta que el despliegue termine bien |

Un respaldo diario con 30 días de retención ocupa 30 veces el tamaño de la base.
Con un volumen de respaldo cifrado, es barato; sin cifrar, es un riesgo.

## 3. Cómo se hace

```bash
# Un respaldo ahora.
deploy/scripts/respaldar.sh
```

El script:

1. **Comprueba que `pg_dump` existe** y que las variables están definidas.
2. **Hace el respaldo** en formato `custom` con compresión.
3. **Lo verifica**: `pg_restore --list` lee el índice del archivo. Si el
   respaldo se creó truncado, el listado falla.
4. **Borra los respaldos con más de `RETENCION_DIAS` días.**

### Por qué `pg_dump` y no copiar archivos

Copiar el directorio de datos de PostgreSQL con la base corriendo produce un
archivo que a veces no arranca. `pg_dump` habla con el motor y garantiza una
copia consistente.

### Por qué el paso 3

Un `pg_dump` que falla a mitad de camino puede dejar un archivo que existe,
tiene tamaño, y no sirve para nada. `pg_restore --list` no escribe nada en
ninguna base, así que se puede usar como comprobación barata de que el
archivo está íntegro.

## 4. Restaurar

```bash
# Sin esto, no hace nada: es una operación destructiva.
CONFIRMAR=si deploy/scripts/restaurar.sh /var/backups/gimnasio/gimnasio_2026-01-20_030000.dump
```

El script **pide confirmación explícita** con `CONFIRMAR=si`, y:

1. **Verifica el respaldo antes de tocar la base.** Si está roto, avisa y no
   hace nada.
2. Restaura con `--single-transaction`: si algo falla a mitad de camino, la
   base queda **como estaba**, no a medias.
3. **Comprueba que la base responde** y que tiene tablas.
4. **Recuerda revisar el aislamiento**, porque un respaldo restaurado sobre una
   base sin RLS dejaría el sistema andando y abierto entre gimnasios.

## 5. Probar la restauración

**Esto es lo que casi nadie hace, y es lo único que importa.**

```bash
# 1. Crear una base aparte, de prueba.
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    exec postgres createdb -U "$DB_USERNAME" gimnasio_restore

# 2. Restaurar sobre ella.
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    exec -e PGPASSWORD="$DB_PASSWORD" postgres \
    pg_restore -U "$DB_USERNAME" -d gimnasio_restore \
    /var/backups/gimnasio/gimnasio_2026-01-20_030000.dump

# 3. Contar socios.
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    exec postgres psql -U "$DB_USERNAME" -d gimnasio_restore \
    -c "SELECT count(*) FROM socios;"

# 4. Y lo más importante: que el aislamiento siga puesto.
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    exec postgres psql -U "$DB_USERNAME" -d gimnasio_restore \
    -c "SELECT count(*) FROM pg_policies WHERE schemaname = 'public';"
```

El paso 4 es el que casi todos se saltan. Un respaldo restaurado sobre una base
sin políticas deja el sistema funcionando y abierto entre gimnasios.

**Haga esta prueba una vez por mes.** Si no se puede restaurar, el respaldo no
sirve.

## 6. Automatizar

```cron
# A las 03:15, de lunes a sábado. No domingo: nadie mira los respaldos
# el domingo, y si algo falla, la corrección se demora dos días.
15 3 * * 1-6 cd /opt/gimnasio && deploy/scripts/respaldar.sh >> /var/log/gimnasio-respaldo.log 2>&1
```

Después del respaldo, **mire el log**. Un respaldo que falla en silencio durante
tres semanas es peor que no tener respaldo, porque genera confianza falsa.

## 7. Dónde guardarlos

El respaldo tiene los **datos personales de todos los socios de todos los
gimnasios**. Es tan sensible como la base misma.

| Regla | Por qué |
|---|---|
| **Nunca en el repositorio** | Es un archivo grande y con datos personales |
| **Nunca junto a la base** | Si alguien copia el directorio del servidor, se lleva las dos cosas |
| **Disco cifrado o volumen cifrado** | Un respaldo sin cifrar es un archivo con todos los socios |
| **Una copia fuera del servidor** | Si el servidor se incendia, el respaldo se va con él |
| **Acceso restringido** | Solo quien administre el servidor |

Si el respaldo sale del servidor, tiene que ir cifrado. Con `openssl`:

```bash
gzip -c respaldo.dump | openssl enc -aes-256-cbc -pbkdf2 -salt -out respaldo.dump.gz.enc
```

La clave **no** va junto al archivo.

## 8. Restaurar en otro servidor

1. Instalar Docker y Docker Compose.
2. Copiar el código desde el repositorio.
3. Copiar `.env.prod` (por canal seguro, no por el mismo respaldo).
4. Levantar solo PostgreSQL:
   ```bash
   docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
       up -d postgres
   ```
5. **Crear el rol** de la base, sin superusuario:
   ```sql
   CREATE ROLE gimnasio LOGIN PASSWORD '...' NOSUPERUSER NOBYPASSRLS;
   ```
6. Restaurar el respaldo.
7. **Aplicar las migraciones**, por si el respaldo es de una versión anterior:
   ```bash
   docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
       run --rm api php artisan migrate --force
   ```
8. **Verificar el aislamiento** antes de recibir socios:
   ```bash
   deploy/scripts/verificar-aislamiento.sh
   ```

## 9. Qué hay que comprobar después de restaurar

| Comprobación | Cómo |
|---|---|
| La base responde | `SELECT 1;` |
| Las tablas están | `SELECT count(*) FROM pg_tables WHERE schemaname = 'public';` |
| Hay socios | `SELECT count(*) FROM socios;` |
| **La RLS está puesta** | `SELECT count(*) FROM pg_policies WHERE schemaname = 'public';` |
| **El rol no es superusuario** | `SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user;` |
| **La vista tiene `security_invoker`** | Ver sección 5, paso 4 |
| Las zonas horarias son válidas | `SELECT DISTINCT zona_horaria FROM tenants;` contra `pg_timezone_names` |

## 10. Lo que no está resuelto

- **No hay cifrado de respaldos automático.** El script produce el archivo en
  claro y deja el cifrado a decisión del operador. Es una decisión pendiente,
  no un olvido.
- **No hay copia fuera del servidor configurada.** El `cron` respalda local, y
  si el servidor desaparece, el respaldo desaparece con él.
- **No hay prueba de restauración automática.** El proceso existe y está
  documentado, pero depende de que alguien lo haga a mano.

Estas tres cosas están anotadas acá a propósito. Un respaldo sin copia fuera
del servidor es una pérdida de datos esperando un incendio.