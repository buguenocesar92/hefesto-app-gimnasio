# 05 - Configuración

## 1. Variables de entorno

El archivo de ejemplo es `.env.example`. **No tiene valores reales**: todo lo
que parece un secreto dice `CAMBIAR`. Un archivo de ejemplo con un secreto de
verdad termina en un repositorio, y ese secreto queda expuesto.

Copie a `.env` (desarrollo) o `.env.prod` (producción), y complete los valores.

## 2. Ajustes que se cambian por gimnasio

No hay que tocar código para cambiar esto. Está en la tabla `tenants`:

| Columna | Por defecto | Qué hace |
|---|---|---|
| `zona_horaria` | `America/Santiago` | La zona con que se calcula el día de cada visita |
| `dias_aviso_inactividad` | 21 | Cuántos días sin venir marcan riesgo de abandono |
| `horas_visita_abierta_maxima` | 8 | Después de cuántas horas el sistema cierra una visita sin salida |

### Sobre la zona horaria

Se guarda el **nombre** de la zona IANA, no un desplazamiento en horas.

Chile tiene hora de verano (CLST, UTC-3) y el cambio ocurre en una fecha que
cambia cada año. Un desplazamiento fijo dejaría todas las visitas de invierno
con la hora corrida, y en invierno el socio que entra a las 23:50 aparecería
como visita del día siguiente, todos los días, durante meses.

> **Pendiente de verificar.** El identificador `America/Santiago` viene de la
> base de zonas horarias IANA y es el que usa el `AT TIME ZONE` de
> PostgreSQL. La lista de zonas válidas se puede comprobar en el despliegue
> con `SELECT name FROM pg_timezone_names;`. No se ejecutó aquí.

## 3. Authentik

| Variable | Qué es |
|---|---|
| `AUTHENTIK_ISSUER` | URL del emisor. **Debe terminar en barra** y coincidir con lo que declara el proveedor |
| `AUTHENTIK_CLIENT_ID` | Identificador de la aplicación |
| `AUTHENTIK_CLIENT_SECRET` | Secreto de la aplicación |
| `AUTHENTIK_ALGORITMO` | `RS256` (llaves asimétricas). No cambiar sin rehacer ambos lados |
| `AUTHENTIK_GRUPO_ADMINISTRADORES` | Grupo que marca al primer administrador |

### El issuer se compara carácter por carácter

`VerificadorIdToken::comprobarEmisor()` usa `hash_equals()` sobre el `iss` del
token contra el `AUTHENTIK_ISSUER` configurado.

La barra final importa: si el proveedor emite `https://id.ejemplo.cl/app/o/gimnasio/`
y la configuración tiene la misma cadena sin la barra final, la comparación
falla y **nadie puede entrar**, con un error poco claro. Por eso la
comprobación compara con `rtrim(..., '/')` en los dos lados y por eso
conviene dejarla exactamente como el proveedor la declara.

### El claim `groups`

> **Pendiente de verificar contra la documentación oficial de Authentik y
> contra una instancia real.**

Lo que hace el código:

1. Lee `groups` del token, si viene.
2. Si es un solo string, lo trata como una lista de uno.
3. Si no viene o no es una lista, devuelve un conjunto **vacío**.

Con el conjunto vacío, el rol inicial es `encargado`, que es el más bajo. Es
el comportamiento seguro: una cuenta sin grupo confirmado no recibe
privilegios.

Lo que **no** se pudo comprobar: si en una instalación típica de Authentik el
claim `groups` llega por defecto en el token, o si hay que mapearlo
explícitamente en el proveedor. En Authentik el alcance de los grupos es
configurable por proveedor y por aplicación, así que **es algo que hay que
revisar en la consola al instalar**, no algo que se pueda asumir.

## 4. Base de datos: el rol NO puede ser superusuario

Esta es la configuración más importante del despliegue.

```sql
SELECT rolname, rolsuper, rolbypassrls
  FROM pg_roles
 WHERE rolname = current_user;
```

Si `rolsuper` o `rolbypassrls` sale en `true`, PostgreSQL **ignora todas las
políticas de Row Level Security por completo**. Las consultas devolverán filas
de todos los gimnasios y la aplicación no tendrá cómo enterarse: no hay error,
no hay excepción, la interfaz se ve perfecta.

```sql
ALTER ROLE gimnasio NOSUPERUSER NOBYPASSRLS;
```

`gimnasio:verificar-aislamiento` comprueba esto en cada despliegue y falla si
no se cumple.

### Por qué importa en desarrollo

En desarrollo el rol de la aplicación suele ser el mismo que creó las tablas,
o sea el dueño. Por eso las políticas usan `FORCE ROW LEVEL SECURITY`: sin
`FORCE`, el dueño las saltaría y el aislamiento sería decorativo justo en el
entorno donde se prueba.

## 5. Sesión y cookies

| Variable | Producción | Desarrollo |
|---|---|---|
| `SESSION_DRIVER` | `database` | `database` |
| `SESSION_SECURE_COOKIE` | `true` | `false` |
| `SESSION_SAME_SITE` | `lax` | `lax` |
| `SESSION_LIFETIME` | `120` | `120` |

`SAME_SITE` **no puede ser `none`** sin HTTPS: el navegador bloquea la cookie
y nadie puede iniciar sesión, con un error que no dice por qué.

En producción la cookie tiene que ir por HTTPS. Con `SESSION_SECURE_COOKIE`
en `false` y el sitio publicado, la cookie viaja en claro y cualquiera en la
red puede leerla.

## 6. Caché y colas

Redis guarda el caché y las colas. **No guarda nada que no se pueda volver a
calcular desde la base**: si Redis se borra, el sistema funciona igual y los
contadores se rehacen en la siguiente lectura.

| Variable | Efecto |
|---|---|
| `CACHE_STORE` | `redis` en producción. En pruebas, `array` |
| `QUEUE_CONNECTION` | `redis` en producción |
| `REDIS_PASSWORD` | Obligatorio en producción |

Las claves de caché **llevan el gimnasio adentro**:

```
gimnasio:{tenant_id}:contadores:hoy
```

Una clave sin gimnasio sería una fuga esperando: con `contadores:hoy` a secas,
dos gimnasios de la misma instalación leerían el mismo número.

## 7. La interfaz

| Variable | Qué es |
|---|---|
| `NUXT_API_BASE` | Dirección **interna** de la API. No es `public`: no se pública en el bundle del navegador |
| `NUXT_PUBLIC_BASE` | Dirección pública de la interfaz |

Nuxt corre con SSR y actúa de servidor intermediario: el navegador nunca ve
el token de sesión. `apiBase` está en `runtimeConfig`, fuera de `public`, a
propósito.

## 8. Ajustes por gimnasio desde la interfaz

Estos son los que el dueño cambia sin pedirle nada a nadie:

| Ajuste | Dónde | Efecto |
|---|---|---|
| Días sin venir para marcar riesgo | Ficha del gimnasio | Cuándo aparece un socio en la lista de riesgo |
| Horas máximas de una visita abierta | Ficha del gimnasio | Cuándo el sistema cierra una visita que quedó sin salida |
| Zona horaria | Ficha del gimnasio | Cómo se calcula el día de cada visita |
| Motivo de un periodo sin cobro | Ficha del socio, membresía | Distingue mes gratis de cortesía y de cierre técnico |

Los días de aviso y las horas de visita se pueden cambiar también por
comando, sin entrar a la base:

```bash
php artisan gimnasio:detectar-inactivos --dias=30
php artisan gimnasio:cerrar-visitas-abiertas --horas=6
```

## 9. Tareas programadas

| Tarea | Cuándo | Qué hace |
|---|---|---|
| `gimnasio:verificar-aislamiento` | Todos los días, 05:00 | Comprueba que la RLS siga puesta |
| `gimnasio:cerrar-visitas-abiertas` | Cada hora, minuto 7 | Cierra visitas que superaron el máximo de horas |
| `gimnasio:detectar-inactivos` | Todos los días, 08:00 | Genera los avisos de abandono |
| Recálculo de contadores | Todos los días, 00:10 | Rehace los números del panel |

Las tareas que tocan datos de socios se ejecutan **por gimnasio y siempre
dentro de `ContextoGimnasio::ejecutarAislado()`**, porque el proceso de cola
reutiliza la misma conexión para trabajos de gimnasios distintos. Ver
[03](03-MULTITENANCY-Y-ROLES.md), sección 6.

## 10. Lo que no se puede configurar, y por qué

| Cosa | Por qué no es configurable |
|---|---|
| Cuántas visitas abiertas puede tener un socio | Está en un índice único de la base. Es una invariante, no un parámetro |
| Si un invitado cuenta como visita de socio | Está en el modelo: viven en otra tabla. No es un interruptor |
| El día al que pertenece una visita | Está en `vw_visitas`. Que sea configurable sería cambiar la definición de "asistencia" |
| Si se guardan datos de salud | No hay campos. No es que estén ocultos: no existen. Ver [07](07-SEGURIDAD-Y-LEY-19628.md) |

Estas cosas no son opciones porque cada una protege una regla de negocio, y
un parámetro que se puede desactivar desde una pantalla es un parámetro que
alguien va a desactivar.