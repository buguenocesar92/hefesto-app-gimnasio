# 03 - Multi-tenancy y roles

Este es el documento más importante del proyecto.

El riesgo número uno no es que falle una pantalla: es que el **Gimnasio
Fuerza** vea los socios del **Gimnasio Aurora**. Todo lo demás es menos
importante que esto.

---

## 1. El patrón que se reutilizó

Este producto usa **el mismo patrón que ya está decidido y documentado** en
`apps/portal-autoservicio/docs/03-MULTITENANCY.md`:

> columna `tenant_id` más Row Level Security de PostgreSQL, con `WITH CHECK`,
> `FORCE ROW LEVEL SECURITY`, y el contexto viajando con
> `set_config(..., true)` (alcance de transacción).

No se inventó un cuarto enfoque. El problema es exactamente el mismo —una
base, varios clientes, datos que no se pueden cruzar— y el patrón ya está
probado y documentado. Las clases y el archivo de migración se adaptaron al
dominio del gimnasio:

| Portal de autoservicio | Gimnasio |
|---|---|
| `ContextoTenant` | `ContextoGimnasio` |
| `ResolverTenant` | `ResolverTenant` (mismo nombre, mismo middleware) |
| `app_tenant_id()` | `app_tenant_id()` (misma función) |
| `politica_aislamiento_*` | `politica_aislamiento_*` |
| `portal:verificar-aislamiento` | `gimnasio:verificar-aislamiento` |
| `vw_tenants_publicos` | `vw_gimnasios_publicos` |

Migración: `api/database/migrations/2026_01_20_000015_activar_row_level_security.php`.

---

## 2. Por qué RLS y no esquema por gimnasio

La alternativa clásica es darle a cada gimnasio su propio esquema
(`fuerza.socios`, `aurora.socios`). Se evaluó y se descartó. Los motivos
concretos están en el [documento del portal](../portal-autoservicio/docs/03-MULTITENANCY.md),
sección 2. El resumen:

1. **Las migraciones se multiplican**: con 30 gimnasios son 30 ejecuciones por
   cambio de esquema.
2. **El pool de conexiones se dimensiona por cliente** y queda corto en horas
   punta.
3. **Laravel obliga a duplicar la lógica de esquema** en cada consulta.
4. **El `search_path` es un parámetro de sesión**: si queda mal puesto en una
   conexión reutilizada, la consulta va al esquema equivocado **y no falla**.
   Devuelve datos del gimnasio equivocado sin error visible.

El punto 4 es el decisivo: con RLS, el gimnasio viaja **en la propia fila** y
la política compara fila por fila. No hay estado que pueda quedar mal.

---

## 3. El mecanismo, en detalle

### 3.1 La función de contexto

```sql
CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
LANGUAGE plpgsql STABLE AS $$
DECLARE valor text;
BEGIN
    valor := current_setting('app.tenant_id', true);

    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION USING
            ERRCODE = 'insufficient_privilege',
            MESSAGE = 'No hay contexto de gimnasio en la conexion...';
    END IF;

    RETURN valor::uuid;
END $$;
```

La función **lanza excepción** si no hay gimnasio. Podría devolver `NULL` y
filtrar todo en silencio, pero se eligió que falle de forma ruidosa:

- Si devuelve `NULL`, la consulta devuelve cero filas sin aviso, y alguien lo
  "arregla" devolviendo `NULL` cuando hace falta (un reporte, un comando de
  consola) y la puerta queda abierta sin que nada lo delate.
- Si lanza excepción, el error aparece en el log **en el momento**, y se sabe
  exactamente qué operación se escapó del middleware.

Es el criterio de un candado: importa que falle cerrado.

### 3.2 La política

```sql
ALTER TABLE public.socios ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.socios FORCE ROW LEVEL SECURITY;

CREATE POLICY politica_aislamiento_socios ON public.socios
    FOR ALL
    TO PUBLIC
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

Tres detalles que importan:

- **`USING`** filtra lo que se lee.
- **`WITH CHECK`** impide escribir una fila con un `tenant_id` distinto al
  contexto. Sin esto, un `INSERT` con el gimnasio equivocado dejaría una fila
  invisible que nadie podría ver ni borrar.
- **`FORCE ROW LEVEL SECURITY`** hace que la política aplique **también al
  dueño de la tabla**. Sin `FORCE`, el dueño la saltaría, y en desarrollo el
  rol de la aplicación suele ser el mismo que creó las tablas: el aislamiento
  sería decorativo justo en el entorno donde se prueba.

Tablas protegidas: `usuarios`, `planes`, `socios`, `membresias`, `cobros`,
`periodos_sin_cobro`, `visitas`, `eventos_acceso`, `ingresos_invitados`,
`rutinas`, `registros_rutina`, `avisos_abandono`.

### 3.3 Cómo viaja el gimnasio

```php
DB::select('SELECT set_config(?, ?, true)', ['app.tenant_id', $gimnasioId]);
```

**El `true` final es lo más importante del archivo.** Significa alcance de
**transacción**: al confirmar o revertir, PostgreSQL descarta el valor solo.

Con un `SET` normal (alcance de sesión), el valor sobreviviría a la petición.
Y el **worker de colas reutiliza la misma conexión** para trabajos de
gimnasios distintos: un trabajo del Gimnasio Fuerza podría leer los datos del
Gimnasio Aurora.

Por eso `ContextoGimnasio::establecer()` **lanza excepción si no está dentro
de una transacción**:

```php
if (DB::transactionLevel() < 1) {
    throw new RuntimeException(
        'No se puede fijar el contexto de gimnasio fuera de una transaccion. ...'
    );
}
```

### 3.4 Quién fija el contexto

Solo una clase: `App\Services\Tenancy\ContextoGimnasio`, y solo a través del
middleware `App\Http\Middleware\ResolverTenant`, que envuelve la petición
autenticada en una transacción con el gimnasio ya fijado.

Peticiones sin sesión (salud, login) pasan sin transacción. Si esas rutas
tocaran una tabla protegida, `app_tenant_id()` lanzaría excepción y la
petición fallaría: el aislamiento falla cerrado, nunca abierto.

---

## 4. Los dos puntos ciegos

### 4.1 La tabla `tenants`

`tenants` **no lleva RLS**, a propósito: se consulta durante el inicio de
sesión, cuando todavía no hay `app.tenant_id` que poner.

Es una excepción consciente con dos compensaciones:

- **La vista `vw_gimnasios_publicos`**: existe y expone **tres** columnas
  (`slug`, `nombre`, `activo`) y solo los gimnasios activos.

  > **Corrección de 18-10-2026.** Este texto decía antes que «antes del login
  > la aplicación lee esta vista». **Es falso: el código la crea y nunca la
  > consulta.** Se detectó al auditar las 17 aplicaciones del estudio: solo
  > Notaría, Agencia de viajes, Estudio de abogados y Control de Obras usan
  > su vista de verdad. Queda pendiente decidir si se conecta o si se elimina;
  > no se hizo ninguna de las dos porque no había base de datos para probar
  > el cambio.
- **El rol de la aplicación no es superusuario.** Quien tenga acceso SQL
  directo puede leer `tenants` entera, así que `gimnasio:verificar-aislamiento`
  comprueba que el rol no sea superusuario ni tenga `BYPASSRLS`.

**Riesgo residual, dicho con todas las letras:** un `slug` se puede enumerar
sin autenticarse, porque aparece en la URL de entrada y en los enlaces que el
gimnasio reparte. Un atacante puede descubrir que el Gimnasio Fuerza usa la
plataforma. No puede ver más que el nombre.

Si incluso eso se considera inaceptable, la alternativa es dejar el campo en
blanco hasta el login y resolver siempre por dominio de correo. Queda
anotado como decisión pendiente del cliente, **no como algo resuelto**.

### 4.2 La vista `vw_visitas`

Una vista de PostgreSQL se ejecuta por defecto con los permisos de **quien la
definió**, no de quien consulta. Creada sin más, `vw_visitas` leería `visitas`
como su dueño y la RLS no se aplicaría.

Por eso se crea con `security_invoker = true`, y por eso la migración de RLS
**falla de forma explícita** si esa opción falta:

```php
if (! $segura) {
    throw new RuntimeException(
        'La vista public.vw_visitas NO tiene security_invoker=true. Sin esa opcion lee '
        . 'visitas con los permisos de su dueno y la Row Level Security no se aplica: '
        . 'un gimnasio podria ver las visitas de otro.'
    );
}
```

`security_invoker` está disponible desde PostgreSQL 15. El despliegue usa 18.

---

## 5. El segundo filtro: permisos dentro del gimnasio

La RLS garantiza que nada sale del gimnasio. Dentro del gimnasio hay un
segundo filtro, en las Policies.

| Acción | Socio | Coach | Recepción | Dueño |
|---|:---:|:---:|:---:|:---:|
| Ver la lista de socios | no | sí | sí | sí |
| Ver **su propia** ficha | sí | no | no | no |
| Ver otra ficha | no | sí | sí | sí |
| Crear y editar socios | no | no | sí | sí |
| **Suspender** a un socio | no | no | no | **sí** |
| Registrar entrada y salida | no | no | sí | sí |
| Registrar invitado | no | no | sí | sí |
| Ver el panel de riesgo | no | **no** | sí | sí |
| Ver cobros y montos | no | **no** | sí | sí |
| **Registrar periodo sin cobro** | no | no | **no** | **sí** |
| Crear planes y cambiar precios | no | no | **no** | **sí** |
| Dar de alta usuarios | no | no | **no** | **sí** |

Cuatro decisiones que merecen explicación:

**El coach no ve cobros.** No necesita saber cuánto pagó un socio para
descargarle una rutina, y el dato existe sin que él lo vea.

**Recepción no suspende.** La suspensión es una excepción comercial, y
aceptarla en recepción sin que el dueño lo sepa es cómo un gimnasio pierde
plata sin darse cuenta.

**Solo el dueño registra periodos sin cobro.** Un mes gratis abierto por
error es plata que no vuelve, y nadie se entera hasta el arqueo.

**El coach no ve el panel de riesgo.** La lista de socios que están a punto de
irse es información de negocio; no hay razón para que un empleado la vea
completa.

### El socio solo ve lo suyo

Un socio con cuenta propia ve **su** ficha, **su** historial y **sus**
rutinas. La comparación es por `usuario_id`, nunca por nombre, para que no
pueda ver la ficha de otro socio con una cuenta parecida.

Tampoco ve los montos que pagó: ve que su membresía está al día y hasta
cuándo, que es lo que necesita. El precio es información del contrato con el
gimnasio, no suya.

### Ningún socio puede registrarse su propia entrada

`VisitaPolicy::entrar()` devuelve `false` para el rol `socio`. El acceso lo
registra **quien controla la puerta**. Un socio que puede registrarse su
propia entrada puede inventarse las visitas que quiera, y con eso falsea el
panel.

### 404, no 403

Cuando una Policy rechaza, la respuesta es **404**. Un 403 confirmaría que el
recurso existe, y con eso ya se filtró información: el atacante sabe que ese
`id` corresponde a algo real.

Configurado en `bootstrap/app.php`:

```php
$exceptions->render(function (AuthorizationException $e, $request) {
    if ($request->is('api/v1/*')) {
        return response()->json(['mensaje' => 'No encontrado.'], 404);
    }
    return null;
});
```

El filtro por gimnasio, además, impide que el recurso exista a ojos del otro
gimnasio, así que 404 es también la respuesta correcta en el caso habitual.

---

## 6. El worker de colas

Es donde el aislamiento se juega en serio, porque el worker **reutiliza la
misma conexión** para trabajos de gimnasios distintos.

Cada trabajo abre su propia transacción:

```php
// App\Jobs\RefrescarContadoresAsistencia
public function handle(ContadoresAsistencia $contadores): void
{
    $contexto = new ContextoGimnasio();

    $contexto->ejecutarAislado(
        $this->gimnasioId,
        function () use ($contadores): void {
            $contadores->recalcular($this->gimnasioId);
        }
    );
}
```

`ejecutarAislado()` usa `set_config(..., true)`: al terminar la transacción,
el valor desaparece y no queda colgando para el trabajo siguiente.

Hay una prueba que lo comprueba:
`test_el_contexto_de_gimnasio_no_sobrevive_a_la_transaccion`.

---

## 7. Riesgo residual, con todas las letras

**Riesgo: fuga de datos entre gimnasios.**

Mitigaciones, en orden de fuerza:

1. **La base de datos lo impide**, no la aplicación.
2. La política tiene `WITH CHECK`: no se crean filas en nombre de otro.
3. El contexto tiene alcance de transacción: el worker no arrastra el
   gimnasio de otro trabajo.
4. `FORCE ROW LEVEL SECURITY`: el dueño de la tabla tampoco salta la
   política.
5. La función de contexto **falla cerrado**.
6. La vista de visitas tiene `security_invoker = true`, y la migración falla
   si falta.
7. Pruebas de aislamiento contra **PostgreSQL de verdad**.
8. `gimnasio:verificar-aislamiento`, que corre en el despliegue y falla si el
   rol tiene `BYPASSRLS`, si alguna tabla quedó sin política, si el índice de
   visita abierta no existe, o si la función dejó de fallar cerrada.

**Lo que NO se hizo y sigue siendo un riesgo:**

- **No hay revisión de seguridad externa.**
- **No hay pruebas de penetración.**
- **Las pruebas están escritas pero NO se ejecutaron**: en el entorno donde se
  escribió este código no hay PHP, ni Composer, ni PostgreSQL, ni Docker.
  Ver [10](10-PRUEBAS-Y-ESTADO.md).
- El slug se puede enumerar sin autenticarse (sección 4.1).
- Quien tenga acceso SQL directo a la base puede leer `tenants` entera.

---

## 8. Lo que hay que hacer al desplegar

```bash
# 1. Aplicar migraciones (incluye la de RLS).
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan migrate --force

# 2. Comprobar que el aislamiento quedó puesto.
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
    run --rm api php artisan gimnasio:verificar-aislamiento

# 3. Segunda verificación, en SQL directo, desde el servidor.
deploy/scripts/verificar-aislamiento.sh
```

El paso 3 existe porque el comando de Artisan mira **lo que la aplicación
cree**, y esa revisión mira **lo que la base realmente tiene**. Son cosas
distintas: un script de despliegue anterior pudo haber creado una tabla sin
pasar por la migración, y esa tabla no va a estar en la lista del comando.

Si cualquiera de los dos falla, **el gimnasio no abre a socios**.

## 9. Resumen en una tabla

| | Esquema por gimnasio (descartado) | Columna + RLS (elegido) |
|---|---|---|
| Migraciones | Una por gimnasio × N clientes | Una sola |
| Pool de conexiones | Se dimensiona por cliente | Uno solo |
| Aislamiento | En la aplicación (`search_path`) | En la base de datos |
| Error de contexto | Falla en silencio | Falla ruidosamente |
| Riesgo de fuga | Alto (depende de cada consulta) | Bajo (la base lo impide) |
| Verificación | Manual | `gimnasio:verificar-aislamiento` |

---

## 10. Los dos roles de PostgreSQL

La base de esta aplicación corre con **dos** roles, no con uno. La separación es
deliberada: es lo que hace que el aislamiento entre gimnasios funcione en vez de
quedar como decoración.

### Qué es cada rol

| Rol | Nombre | De dónde sale | Para qué sirve | ¿Lo usa la aplicación? |
|---|---|---|---|---|
| **Arranque** | `gimnasio_admin` | `POSTGRES_USER` | Crear la base `gimnasio` y las tareas administrativas del despliegue | **Nunca** |
| **Aplicación** | `gimnasio` | `DB_USERNAME`, creado por `deploy/postgres/inicializar-rol.sh` | Todo el tráfico de la API | **Sí, este y solo este** |

Aquí el rol de aplicación **no** lleva sufijo: se llama `gimnasio`, igual que la
base. Es el mismo nombre que usan los documentos de instalación y configuración
cuando dicen `ALTER ROLE gimnasio NOSUPERUSER NOBYPASSRLS`. Ese `ALTER` ya no
hace falta en un despliegue nuevo: el rol lo crea el script con los atributos
escritos.

El rol de arranque no es una convención del estudio: la imagen oficial de
PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`, así que ese rol nace
**superusuario**.

### Por qué con un solo rol no servía

La documentación de PostgreSQL 18 lo dice de forma textual:

> *«Superusers and roles with the `BYPASSRLS` attribute always bypass the row
> security system when accessing a table. Table owners normally bypass row
> security as well, though a table owner can choose to be subject to row
> security with `ALTER TABLE ... FORCE ROW LEVEL SECURITY`.*

La segunda frase es la clave. `FORCE ROW LEVEL SECURITY` sujeta al **dueño** de
la tabla, pero **no al superusuario**. Mientras la aplicación conectara con el
rol de `POSTGRES_USER`, ninguna política de este documento se iba a aplicar: un
gimnasio habría visto las fichas de los socios, los cobros y las visitas de los
demás, sin error y sin aviso. El aislamiento entre gimnasios era decorativo.

### Los atributos van todos escritos

`deploy/postgres/inicializar-rol.sh`, montado en
`/docker-entrypoint-initdb.d/`, crea el rol con `NOSUPERUSER`, `NOCREATEDB`,
`NOCREATEROLE`, `NOINHERIT` y `NOBYPASSRLS` **explícitos todos**, aunque
`NOBYPASSRLS` ya sea el valor por omisión.

Se escriben igual a propósito: un rol verificado no debería depender de un valor
por omisión que una versión nueva puede cambiar. El script también falla
ruidosamente si falta `POSTGRES_DB`, `APP_DB_ROLE` o `APP_DB_PASSWORD`.

### Por qué el rol de aplicación es dueño de la base

Justo después de crearlo, el script corre:

```sql
ALTER DATABASE gimnasio OWNER TO gimnasio;
```

Las migraciones de Laravel se ejecutan con el rol de la aplicación y necesitan
**crear tablas**. La única forma de darles ese permiso sin convertirlos en
superusuario es que ese rol sea el dueño de la base. Desde PostgreSQL 15 el
esquema `public` pertenece al dueño de la base, así que con esto el rol ya
puede crear tablas ahí.

Y este es el punto exacto del diseño: al ser el dueño, **las tablas quedan a su
nombre**, y por eso el `FORCE ROW LEVEL SECURITY` que usan las tablas sí lo
sujeto. Ser dueño no es saltarse la política; es lo que hace que la política lo
alcance. Un superusuario la saltearía siempre.

### Cómo se comprueba

```
php artisan gimnasio:verificar-aislamiento
```

El comando consulta `pg_roles` sobre el rol de la conexión y falla con código 1
si ese rol es superusuario o tiene `BYPASSRLS`, y después revisa que la RLS, las
políticas y el contexto de gimnasio protejan los datos de cada gimnasio.

**Este comando no se ha ejecutado.** En el equipo donde se escribió este cambio
no había PostgreSQL ni Docker. El diseño es correcto y está sostenido en la
documentación oficial, pero nadie lo ha corrido. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).