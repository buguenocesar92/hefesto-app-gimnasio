# 02 - Modelo de datos

Todas las tablas de negocio llevan `tenant_id`, que apunta a `tenants` (el
gimnasio). El aislamiento lo impone la Row Level Security; ver
[03](03-MULTITENANCY-Y-ROLES.md).

## 1. Mapa de tablas

| Tabla | Qué guarda | RLS |
|---|---|---|
| `tenants` | El gimnasio | **No** (excepción consciente) |
| `usuarios` | Personal y socios con cuenta | Sí |
| `planes` | Planes y precios | Sí |
| `socios` | Ficha de la persona | Sí |
| `membresias` | Vínculo socio-plan, con historial | Sí |
| `cobros` | Cobro por periodo | Sí |
| `periodos_sin_cobro` | Periodos con derecho y sin cobro | Sí |
| `visitas` | Una fila por estada | Sí |
| `eventos_acceso` | Bitácora que solo crece | Sí |
| `ingresos_invitados` | Entradas de invitados | Sí |
| `rutinas` | Lista de ejercicios | Sí |
| `registros_rutina` | Series, kilos y repeticiones | Sí |
| `avisos_abandono` | Socios en riesgo | Sí |

Convenciones que se usan en todas:

- La clave primaria es un `uuid` generado en la aplicación.
- Las marcas de tiempo son `timestamptz` y se nombran en español:
  `creado_en`, `actualizado_en`, `entrada_en`, `salida_en`.
- Las fechas sin hora son `date`: `nacimiento`, `iniciada_en`,
  `periodo_desde`.

## 2. `socios`

Datos personales bajo la Ley 19.628. Se guarda lo que el gimnasio necesita
para cobrar y para dejar entrar, y nada más:

| Columna | Para qué |
|---|---|
| `nombre`, `apellido` | Identificación en recepción |
| `documento` | Único **por gimnasio**, no global |
| `nacimiento` | La tarifa estudiante se aplica por edad |
| `correo`, `telefono` | Contacto |
| `telefono_emergencia`, `contacto_emergencia` | Accidente en la sala de máquinas |
| `suspendida_desde`, `suspension_hasta` | Suspensión **con fecha de vuelta** |
| `usuario_id` | Cuenta propia del socio, si tiene |

**No existe columna de salud.** Ni lesión, ni diagnóstico, ni aptitud, ni
peso, ni altura. Un gimnasio no es una clínica.

La suspensión es un periodo, no una bandera: `suspension_hasta` responde "¿cuándo
vuelve?", y una bandera no puede.

El documento es único por `(tenant_id, documento)` y no global: dos gimnasios
pueden tener socios con el mismo número de documento.

## 3. `visitas`

**Una fila por cada vez que una persona estuvo adentro.**

| Columna | Qué significa |
|---|---|
| `entrada_en` | Cuándo entró. Obligatoria |
| `salida_en` | Cuándo salió. Nula mientras está adentro |
| `canal` | `dispositivo`, `manual` o `aplicacion` |
| `dispositivo` | Qué turno la leyó. Nula si fue a mano |
| `registrado_por` | Quién lo anotó. Obligatorio si `canal` es `manual` |
| `motivo_manual` | Por qué se anotó a mano. Obligatorio si `canal` es `manual` |
| `cerrada_por_sistema` | Nadie la cerró: la cerró el sistema |
| `anulada` | Error de registro. La fila sigue, no cuenta |

### El canal es evidencia, no un detalle

La distinción entre `dispositivo` y `manual` existe por los cortes de energía
y los fallos del turno. Si se mezclaran, en seis meses no habría forma de
distinguir un corte de luz de un socio que se coló.

Por eso un acceso manual sin motivo es un error: `RegistroVisita` lo rechaza y
`RegistrarEntradaRequest` lo pide antes de llegar a la base. Está en los dos
lugares porque el servicio también se llama desde la consola y desde las
pruebas, y ninguno de esos caminos pasa por un `FormRequest`.

### Una visita abierta a la vez

```sql
CREATE UNIQUE INDEX visitas_una_abierta_por_socio
    ON public.visitas (socio_id)
    WHERE salida_en IS NULL;
```

Es un índice **parcial**: solo considera las filas abiertas, así que el socio
puede tener todas las visitas cerradas que quiera, pero nunca dos abiertas.

Una visita **anulada** recibe `salida_en`, y con eso libera el índice: si la
entrada estaba mal leída, el socio no queda con la puerta bloqueada.

### Una entrada sin salida es un caso, no un olvido

El comando `gimnasio:cerrar-visitas-abiertas` cierra las que superaron las
horas configuradas (8 por defecto), y lo hace de tres maneras, todas
visibles:

1. pone la salida en la hora máxima permitida;
2. marca `cerrada_por_sistema = true`;
3. deja un evento `cierre_por_sistema` que dice que nadie la cerró.

La diferencia entre "el socio salió a las 21:30" y "el sistema cerró la visita
a las 23:00 porque nadie estaba en la casilla" queda escrita, y recepción
puede corregirla.

## 4. `eventos_acceso`

La bitácora que solo crece. Nunca se actualiza ni se borra.

| Columna | Qué significa |
|---|---|
| `tipo` | `entrada`, `salida`, `cierre_por_sistema`, `anulacion` |
| `ocurrido_en` | Cuándo **pasó** el hecho |
| `canal`, `registrado_por`, `motivo` | Quién y cómo |
| `creado_en` | Cuándo se **escribió** la fila |

`ocurrido_en` y `creado_en` son distintos a propósito. Si el turno se cayó y
los accesos se cargaron después, el hecho ocurrió a las 19:12 aunque la fila
se escribió a las 22:00.

La clave foránea hacia `visitas` es `RESTRICT`, no `CASCADE`: si se borrara
una visita, sus eventos quedarían sin dueño y la bitácora mentiría. Las
visitas no se borran, se anulan.

## 5. `membresias`, `cobros` y `periodos_sin_cobro`

### La membresía NO guarda la fecha de pago

El pago vive en `cobros`, porque un socio paga todos los meses y el dueño
necesita **la lista**, no un único campo que se sobreescribe cada vez que
llega el cobro siguiente.

Con un campo `ultimo_pago`, la pregunta "¿el socio que dejó de venir el mes
pasado, pagó?" deja de tener respuesta en cuanto llega el cobro nuevo.

### Los periodos: `desde` inclusivo, `hasta` exclusivo

Un cobro del 1 de noviembre al 1 de diciembre cubre el 1 de noviembre y el
30. El 1 de diciembre ya es del periodo siguiente.

Esa convención es la que hace que el mes gratis y los cobros se encajen sin
huecos ni solapamientos:

```
cobro                2026-11-01 ──────────────► 2026-12-01
mes gratis           (sin cobrar)               2026-12-01 ──────► 2027-01-01
cobro                                                        2027-01-01 ────► 2027-03-01
```

No hay día que quede sin dueño, ni día cobrado dos veces.

### EL MES GRATIS ES UN PERIODO, NO UNA CASILLA

Aquí está el punto que más se malentiende en estos sistemas.

Con una columna `gratis = true` en la membresía no se puede responder:

- ¿Hasta cuándo dura?
- ¿Cuándo vuelve a empezar el cobro?
- ¿Este socio tuvo mes gratis en diciembre o en julio?
- ¿Fue el mes gratis o fue una cortesía?

Con `periodos_sin_cobro` —que tiene `desde`, `hasta`, `motivo` y `nota`— las
cuatro tienen respuesta, y el derecho del socio queda cubierto de forma
continua porque el periodo sin cobro y el siguiente cobro se tocan.

**Un mes gratis no es un cobro de cero pesos.** No genera fila en `cobros`.
Son dos hechos distintos y el dueño los necesita separados en el arqueo:
en diciembre no entra plata, pero el socio sí tiene derecho a entrar.

`motivo` distingue `mes_gratis`, `promocion`, `cortesia` y `cierre_tecnico`.
El sistema los trata igual para el derecho a entrar, pero el dueño los
distingue cuando revisa la caja.

Un índice único `(membresia_id, desde, hasta)` impide cargar dos veces el
mismo periodo, y `CalculadoraEstadoMembresia` impide que dos periodos se
crucen.

## 6. `vw_visitas`

La vista que resuelve dos reglas de una vez.

```sql
CREATE VIEW public.vw_visitas
WITH (security_invoker = true)
AS
SELECT ...
    (v.entrada_en AT TIME ZONE t.zona_horaria)::date AS dia_local,
    CASE WHEN v.salida_en IS NULL
         THEN EXTRACT(EPOCH FROM (now() - v.entrada_en)) / 60
         ELSE EXTRACT(EPOCH FROM (v.salida_en - v.entrada_en)) / 60
    END::integer AS duracion_minutos,
    (v.salida_en IS NULL) AS abierta
FROM public.visitas v
JOIN public.tenants t ON t.id = v.tenant_id
WHERE v.anulada = false;
```

**`dia_local` es el día de la ENTRADA.** Un socio que entra a las 23:50 y sale
a las 00:10 cuenta como una visita del día de la entrada.

**`AT TIME ZONE` usa la zona del gimnasio**, no la del servidor. En un
contenedor el servidor está en UTC, y sin esto todas las visitas de la tarde
quedarían fechadas en el día siguiente.

**`security_invoker = true` es obligatorio.** Una vista de PostgreSQL se
ejecuta por defecto con los permisos de quien la definió, y entonces leería
`visitas` como su dueño: la RLS no se aplicaría y el panel de un gimnasio
devolvería visitas de otro sin que nada lo note. La migración de RLS falla
de forma explícita si esta opción falta.

Las visitas anuladas no aparecen en la vista: no cuentan para el gráfico.

## 7. `ingresos_invitados`

Solo `nombre`, la hora y quién lo anotó. **No hay documento, ni teléfono, ni
correo, ni fecha de nacimiento**, y hay una prueba que lo comprueba.

El gimnasio necesita saber quién entró. No necesita saber quién es. Pedirle
el documento a alguien que viene una vez sería tomar un dato personal sin un
motivo que lo justifique.

`acompana_a_socio_id` es "quién lo trajo", no "cuenta como visita de". Un
invitado con ese vínculo sigue sin aparecer en ningún conteo de socios.

## 8. `avisos_abandono`

La salida que el producto existe para producir.

Un aviso abierto por socio como máximo, con índice único parcial
`(socio_id) WHERE cerrado_en IS NULL`. Si no, el detector corriendo cada hora
apilaría veinte avisos del mismo socio.

Guarda las dos señales juntas:

- `dias_sin_venir` y `ultima_visita_en`: la asistencia.
- `ultimo_pago` y `pago_al_dia`: la cobranza.

Cuando el socio vuelve, el aviso se cierra con `cerrado_en` y **no se
borra**. Así se puede medir si el aviso sirvió de algo, que es lo único que
permite decidir si se sigue pagando el sistema.

## 9. Dinero: enteros, y pesos enteros

`precio_clp`, `monto_clp` son **enteros**. Un decimal binario para dinero
acumula error de redondeo, y en un gimnasio chico la diferencia se nota al
cerrar el mes.

`peso_kg` sí es `decimal(6,2)`, porque acá los pesos son fraccionarios (60,5
kg). No es la misma regla porque no es la misma cosa.

## 10. Índices que existen por una consulta concreta

| Índice | Consulta que lo necesita |
|---|---|
| `visitas (tenant_id, entrada_en)` | Serie diaria del gráfico |
| `visitas (tenant_id, socio_id, entrada_en)` | Historial de un socio |
| `visitas_una_abierta_por_socio` (parcial) | Regla de una visita abierta |
| `eventos_acceso (visita_id, ocurrido_en)` | Bitácora de una visita |
| `cobros (tenant_id, periodo_hasta)` | "¿quién está vencido?" |
| `avisos_abandono_uno_abierto_por_socio` (parcial) | Un aviso por socio |
| `registros_rutina (tenant_id, socio_id, ejercicio, fecha)` | Mejor marca por ejercicio |

## 11. El índice de la ficha del socio

`socios (tenant_id, apellido, nombre)` existe porque recepción busca por
apellido, no por nombre de pila. Buscar "Andrés" en una lista ordenada por
apellido no encuentra a nadie, y en recepción eso se traduce en "no está
inscrito".

## 12. El progreso se calcula en SQL

`RutinaController::progreso()` no trae los registros a memoria:

```sql
SELECT ejercicio,
       max(peso_kg) AS mejor_marca,
       (array_agg(peso_kg ORDER BY fecha DESC))[1] AS ultima_marca,
       count(*) AS registros
  FROM registros_rutina
 WHERE rutina_id = ?
 GROUP BY ejercicio;
```

Un recorrido en memoria de todos los registros de todas las rutinas de todos
los socios no termina nunca. La consulta usa el índice y responde.