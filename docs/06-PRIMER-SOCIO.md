# 06 - El primer socio, de punta a punta

Este documento cuenta el camino completo de un socio nuevo hasta que aparece
en el panel como alguien que dejó de venir. Sirve para probar el sistema a
mano y para entender qué se guarda y por qué.

---

## 1. Crear el gimnasio

El sistema es multi-gimnasio desde el principio: una instalación sirve a
varios clientes, y ninguno ve los datos de los otros.

```
nombre:    Gimnasio Fuerza
slug:      fuerza
zona:      America/Santiago
```

El `slug` es lo que aparece en la URL. **Se puede enumerar sin autenticarse**,
así que no es un secreto: es el nombre público del gimnasio. Ver
[03](03-MULTITENANCY-Y-ROLES.md), sección 4.1.

## 2. Crear los planes

Antes de los socios, los planes. Es lo natural: un socio necesita un plan para
tener membresía.

| Plan | Precio | Días | Visitas |
|---|---|---|---|
| Mensual | $29.900 | 30 | ilimitadas |
| Trimestral | $74.900 | 90 | ilimitadas |
| Diario | $18.900 | 30 | 8 |

Los precios van en **enteros**, sin decimales. Un número decimal binario para
dinero acumula error de redondeo, y en un gimnasio chico la diferencia se nota
al cerrar el mes.

> Si el precio del plan sube mañana, los socios que ya pagando este mes siguen
> con lo que pagaron, porque el monto está en el **cobro**, no en el plan.

## 3. Crear un socio

Nombre, apellido, documento, teléfono.

**No se pide nada más.** En particular no se pide nada de salud. Un gimnasio
no es una clínica, y un dato de salud obliga a permisos más estrictos y a
base legal distinta para algo que el sistema no usa.

El documento es único **por gimnasio**. Dos gimnasios pueden tener socios con
el mismo número de documento sin problema.

## 4. Crear la membresía

Se elige el plan y la fecha de inicio.

La membresía **no guarda la fecha de pago**. La fecha de pago vive en los
cobros, porque un socio paga todos los meses y el dueño necesita la lista de
pagos, no un único campo que se sobreescribe cada vez.

## 5. Registrar el primer cobro

```
periodo_desde: 2026-11-01
periodo_hasta: 2026-12-01   (exclusivo)
monto:         74900
pagado_en:     2026-11-03
```

El periodo del 1 de noviembre al 1 de diciembre cubre el 1 de noviembre y el
30. El 1 de diciembre ya es del periodo siguiente.

## 6. El mes gratis de diciembre

Aquí es donde muchos sistemas se hacen un bollo.

El mes gratis **no es una casilla** que dice `gratis = true`. Es un **periodo
sin cobro**, con inicio y fin propios:

```
desde:  2026-12-01
hasta:  2027-01-01   (exclusivo)
motivo: mes_gratis
nota:   "Mes gratis de diciembre pactado en el contrato."
```

Ahora el socio puede entrar todo diciembre, y el sistema contesta las
preguntas que una casilla no puede:

- ¿Hasta cuándo dura? → 2027-01-01.
- ¿Cuándo vuelve el cobro? → 2027-01-01.
- ¿Tuvo mes gratis en diciembre o en julio? → En diciembre.
- ¿Por qué no se cobró? → Mes gratis pactado.

Y el cobro de enero se registra **`desde: 2027-01-01`**, el mismo día que
termina el periodo sin cobro. No hay día sin dueño, ni día cobrado dos veces.

> **Un mes gratis NO es un cobro de cero pesos.** No genera fila en `cobros`.
> Son dos hechos distintos, y el dueño los necesita separados en el arqueo: en
> diciembre no entra plata, pero el socio sí tiene derecho a entrar.

## 7. Las entradas

Cada vez que el socio entra, hay una fila nueva.

```
entrada_en:    2026-11-04 19:12
salida_en:     2026-11-04 20:03
canal:         dispositivo
dispositivo:   turno-principal
```

Al lado, la bitácora registra **entrada** y **salida** como eventos que no se
tocan nunca más.

**Un socio puede venir tres veces en un día.** No hay un contador ni un
estado "dentro": hay una fila por cada vez que estuvo adentro, y el gráfico
del día cuenta filas.

### El acceso manual

Cuando se corta la luz, o el turno deja de leer, alguien anota a mano:

```
entrada_en:      2026-11-04 19:30
canal:           manual
registrado_por:  <usuario de recepción>
motivo_manual:   "Corte de energía entre las 19:00 y las 20:30."
```

El motivo es **obligatorio**. Si se pudiera omitir, en seis meses no habría
forma de distinguir un corte de luz de un socio que se coló, y el registro de
asistencia valdría menos que el papel.

Un acceso manual sin motivo se rechaza con un error que se puede leer:

> Un acceso manual tiene que tener un motivo: por ejemplo, corte de energía o
> fallo del turno.

## 8. Lo que pasa a las 23:50

Un socio entra a las 23:50 y sale a las 00:10.

Eso es **una** visita, la del día de la entrada. No son dos visitas de dos
días.

La regla vive en la vista `vw_visitas`:

```sql
(v.entrada_en AT TIME ZONE tenants.zona_horaria)::date AS dia_local
```

Y usa la zona **del gimnasio**, no la del servidor. En un contenedor el
servidor está en UTC, y sin esto todas las visitas de la tarde quedarían
fechadas en el día siguiente: el gráfico del viernes aparecería vacío y el
dueño ampliaría el horario equivocado.

## 9. Entrada sin salida

Pasa siempre. Se corta la luz, el socio se queda adentro, y cuando vuelve la
luz el turno ya cerró.

Después de `horas_visita_abierta_maxima` (8 por defecto), el comando
`gimnasio:cerrar-visitas-abiertas` hace tres cosas, todas visibles:

1. Pone la salida en la hora máxima permitida.
2. Marca `cerrada_por_sistema = true`.
3. Deja un evento en la bitácora que dice que nadie la cerró.

La diferencia entre "el socio salió a las 21:30" y "el sistema cerró la visita
a las 23:00 porque nadie estaba en la casilla" queda escrita. Recepción puede
corregirla si hace falta.

## 10. El socio deja de venir

Pasan tres semanas. El socio tiene membresía activa y no aparece.

El comando `gimnasio:detectar-inactivos` lo encuentra y deja un aviso en
`avisos_abandono` con **las dos señales juntas**:

```
dias_sin_venir:    26
ultima_visita_en:  2026-10-21 18:30
ultimo_pago:       2026-12-02
pago_al_dia:       true
observacion:       "Sin venir hace 26 días. La membresía está al día."
```

Eso aparece arriba en el panel, con la etiqueta **"Al día, pero no viene"**,
que es el caso recuperable y el que hay que llamar hoy.

Si además estuviera atrasado en el pago, aparecería **"Atascado en pago"**, que
es otro problema con otra solución.

> El sistema **no manda correos ni mensajes**. Deja la lista y el dueño decide.
> Lo que se haga después se conversa con el cliente, no se impone desde el
> software.

### La diferencia entre "dejó de venir" y "nunca vino"

Son dos problemas distintos y aparecen en listas distintas:

- **Dejó de venir**: tenía una rutina y la cortó. Es recuperable.
- **Nunca vino**: se inscribió pero no arrancó. La solución es otra.

## 11. El socio vuelve

Cuando vuelve, al registrar la entrada se cierra el aviso:

```
cerrado_en: 2027-01-05 19:04
```

El aviso **no se borra**. Queda en la historia, y eso permite medir si sirvió
de algo, que es lo único que permite decidir si se sigue pagando el sistema.

## 12. Invitados

Alguien trae un acompañante:

```
nombre:                  "Invitado de Andrés"
acompana_a_socio_id:     <id de Andrés>
entrada_en:              2026-11-04 20:10
```

El invitado vive en `ingresos_invitados`, **no** en `visitas`. No aparece en
ningún conteo de socios, y no es que el sistema lo excluya: es que no está en
esa tabla.

Del invitado **solo se guarda el nombre**. No el documento, ni el teléfono.
El gimnasio necesita saber quién entró; no necesita saber quién es.

## 13. La rutina

Una rutina con ejercicios, y el socio registra sus series:

```
rutina:    "Fuerza, tres veces por semana"
ejercicio:  Press banca
fecha:     2026-12-01
serie:     1
peso_kg:   72.50
repeticiones: 8
```

La pantalla de progreso responde la pregunta que trae al socio de vuelta:

| Ejercicio | Mejor marca | Última marca |
|---|---|---|
| Press banca | 72,5 kg | 72,5 kg |
| Sentadilla trasera | 90,0 kg | 82,5 kg |

No es un gimnasio de alto rendimiento y no se complica: no hay
periodización, ni RPE, ni curvas de fuerza. El valor es que el socio vea su
progreso, y eso se consigue con fecha, kilos y repeticiones.

## 14. Lo que el sistema guardó de este socio

```
socios                 1 fila
membresias             1 fila
cobros                 5 filas (nov, dic, ene, feb, mar)
periodos_sin_cobro     1 fila (el mes gratis de diciembre)
visitas                87 filas
eventos_acceso         174 filas
rutinas                1 fila
registros_rutina       72 filas
avisos_abandono        1 fila (cerrada)
ingresos_invitados     1 fila
```

**Y ni una sola columna de salud.** No es que estén ocultas: no existen.