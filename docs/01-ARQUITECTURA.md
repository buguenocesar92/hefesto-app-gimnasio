# 01 - Arquitectura

> PostgreSQL corre con dos roles: uno de arranque y otro de aplicación. Solo el
> de aplicación conecta con el sistema, y es el que las políticas de
> aislamiento pueden alcanzar. Ver [03](03-MULTITENANCY-Y-ROLES.md).

## 1. El problema, en una frase

**La gente deja de venir y nadie se entera hasta que ya se fue.**

Un gimnasio vive de que el socio siga renovando. Si alguien no viene en tres
semanas, es la señal más temprana que existe y casi ningún gimnasio la
detecta a tiempo, porque nadie tiene un registro consolidado de visitas. Cada
pregunta al respecto termina en "¿y la vez pasada vino?".

**Ese es el producto: dejar de responder esa pregunta.** Un panel que muestra
la asistencia real por socio, y avisa cuando alguien lleva demasiado tiempo
sin aparecer.

Todo lo demás (la interfaz, la rapidez, el diseño) es menos importante que
eso.

## 2. Las dos señales que siempre van juntas

La fecha de pago importa más de lo que parece, porque el socio deja de
asistir antes de dejar de pagar, o al revés según el caso, y no hay forma de
saber de antemano cuál de las dos señales aparece primero.

| Situación | Qué significa | Qué se puede hacer |
|---|---|---|
| Dejó de venir, **sigue pagando** | Se aburrió o se cambió de lugar | **Es el mejor momento para llamarlo.** Recuperable |
| Sigue viniendo, **dejó de pagar** | No se va a quejar: se va a ir | Aviso temprano al cobro |
| Dejó de venir y dejó de pagar | Ya se fue | Cerrar la ficha sin drama |

Un sistema que muestra solo una de las dos miente por omisión, porque la que
falta es la que más cuesta recuperar. Por eso `CalculadoraEstadoMembresia`
devuelve las dos juntas y nunca una sola.

## 3. Los módulos

| Módulo | Qué resuelve |
|---|---|
| **Socios y planes** | Ficha, plan, estado. El pago NO es un campo: es historial |
| **Acceso y asistencia** | Entradas y salidas como serie de eventos, con canal y responsable |
| **Membresías y renovaciones** | Cobros con periodos que se encajan; el mes gratis es un periodo, no una casilla |
| **Rutinas y seguimiento** | Series, kilos y repeticiones, con progreso en el tiempo |
| **Administración** | Planes, precios, coaches y control de acceso |

## 4. La pila

| Pieza | Elección | Por qué |
|---|---|---|
| API | Laravel 13, PHP 8.3 | Validación, colas yORM maduros |
| Base | PostgreSQL 18 | **Row Level Security.** El aislamiento no puede depender solo del código |
| Interfaz | Nuxt 4 con SSR | Render en servidor y la cookie de sesión no llega al navegador |
| Caché y colas | Redis | Los conteos se calculan en memoria |
| Identidad | Authentik | Sin contraseñas propias |
| Despliegue | Docker Compose con versiones fijadas | Reproducible |

El aislamiento entre gimnasios usa el patrón ya decidido y documentado en
`apps/portal-autoservicio/docs/03-MULTITENANCY.md`: columna `tenant_id` más
Row Level Security, con `WITH CHECK`, `FORCE ROW LEVEL SECURITY` y el
contexto viajando con `set_config(..., true)`. Ver [03](03-MULTITENANCY-Y-ROLES.md).

## 5. La asistencia es una serie de eventos, no un estado

Es la decisión de modelo más importante del sistema, y conviene explicarla
antes de cualquier esquema.

**No existe una columna "dentro/fuera" en el socio.** Si existiera, habría que
decidir qué pasa con la segunda entrada del mismo día, y cualquier respuesta
que se elija pierde información.

Hay dos tablas:

- `visitas`: una fila por cada vez que una persona estuvo adentro, con su
  hora de entrada y su hora de salida.
- `eventos_acceso`: la bitácora que **solo crece**. Entrada, salida, cierre
  por sistema o anulación.

Un socio que viene tres veces en un día tiene tres visitas y seis eventos.

### Por qué dos tablas y no una

Porque `visitas` se actualiza (le ponen la salida) y `eventos_acceso` no.
Cuando alguien pregunta "¿por qué este socio tiene una visita de 14 horas?",
con un estado mutable la pregunta no tiene respuesta: el estado anterior se
perdió. Con la bitácora, la respuesta está escrita, y además se ve quién la
escribió y a qué hora.

Una visita mal registrada **no se borra**: se anula, y el evento queda. El
conteo del dueño la excluye, pero la historia sigue entera.

## 6. Tres reglas que están en la base, no en PHP

Cada una de estas reglas podría haberse escrito como una comprobación en el
código. Las tres están en PostgreSQL, porque las tres se rompen el mismo día
que dos personas hacen lo mismo a la vez.

| Regla | Mecanismo | Por qué en la base |
|---|---|---|
| Un socio no puede tener dos visitas abiertas | `UNIQUE (socio_id) WHERE salida_en IS NULL` | Recepción a full, un viernes a las 19:00: dos personas leen "no hay visita abierta" y las dos escriben |
| No se puede guardar una fila de otro gimnasio | Política RLS con `WITH CHECK` | Un `where tenant_id` olvidado no filtra datos ajenos |
| La vista de visitas respeta el aislamiento | `CREATE VIEW ... WITH (security_invoker = true)` | Una vista se ejecuta por defecto con los permisos de **quien la definió**, y entonces la RLS no se aplica |

La tercera es la más fácil de olvidar y la más grave: sin `security_invoker`,
`vw_visitas` devolvería las visitas de todos los gimnasios y nada fallaría.

## 7. Conteos en memoria

El panel se abre varias veces al día y siempre pinta lo mismo: visitas de
hoy, serie de treinta días, socios en riesgo. Con los índices correctos
PostgreSQL contesta eso en milisegundos.

El problema no es la base: es que se repiten las mismas preguntas con los
mismos datos. Por eso los contadores viven en Redis con clave **por gimnasio**
y se recalculan al cierre del día y después de cada registro.

Si se necesita tiempo real al segundo, la respuesta es una consulta directa,
no un caché que miente. `ContadoresAsistencia::invalidar()` existe para que el
panel no muestre el número de ayer con una entrada que ya se registró.

## 8. Un invitado no es un socio

Los invitados viven en `ingresos_invitados`, **no** en `visitas` con un
`socio_id` nulo.

Si estuvieran en `visitas`, cada conteo tendría que acordarse de excluirlos, y
el día que uno se olvide el panel muestra más visitas de las que hubo, sin
dar ningún error. Con una tabla aparte, los conteos de socios no pueden
tocarlos ni por accidente.

Consecuencia que hay que aceptar: un invitado no tiene historial ni membresía,
y no cuenta para nada. Si el dueño quiere saber "cuántas personas
entraron", esa es otra métrica y se arma sumando las dos.

## 9. Zona horaria

Una visita pertenece al **día de la entrada**, y ese día se calcula con la
**zona horaria del gimnasio**.

Un socio que entra a las 23:50 y sale a las 00:10 hizo **una** visita, la del
día de la entrada. No se parte en dos y no se cuenta como dos visitas.

Esto vive en la vista `vw_visitas`, con
`(entrada_en AT TIME ZONE tenants.zona_horaria)::date`. No en PHP: si se
calculara con la zona del servidor (que en un contenedor es UTC), todas las
visitas de la tarde quedarían fechadas en el día siguiente, y el dueño
ampliaría el horario equivocado porque el gráfico del viernes aparece vacío.

## 10. Un gimnasio no es una clínica

**No hay datos de salud en este sistema.** No hay columnas de lesión,
diagnóstico, aptitud, peso ni altura.

Si un socio deja de asistir por una lesión, eso se conversó en recepción y no
se escribe acá. El detector de abandono ve que no vino, y nada más.

La razón no es solo ética. Un dato de salud cambia la calidad del dato que sí
se puede entregar, porque obliga a permisos más estrictos y a base legal
distinta para algo que el sistema no usa. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

## 11. Lo que este sistema NO hace

- No manda correos ni SMS. Deja la lista y el dueño decide.
- No prescribe rutinas ni evalúa estado físico.
- No controla el acceso por sí mismo: se integra con un turno existente.
- No guarda información médica, ni la pide.

Estas exclusiones son deliberadas. Ver [09](09-API.md) y
[10](10-PRUEBAS-Y-ESTADO.md).