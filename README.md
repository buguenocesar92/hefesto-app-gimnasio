# Gimnasio

Software para gimnasios y centros de entrenamiento. **Aplicación propia de
Hefesto Studio**: el código es de Hefesto, no es un kit de despliegue de
ninguna herramienta de terceros.

---

## El problema, en una frase

**La gente deja de venir y nadie se entera hasta que ya se fue.**

Un gimnasio vive de que el socio siga renovando. Si alguien no viene en tres
semanas, es la señal más temprana que existe y casi ningún gimnasio la
detecta a tiempo, porque nadie tiene un registro consolidado de visitas. Cada
pregunta al respecto termina en "¿y la vez pasada vino?".

**Este producto deja de responder esa pregunta.** Un panel con la asistencia
real por socio, y un aviso cuando alguien lleva demasiado tiempo sin aparecer.

---

## Qué hay adentro

| Módulo | Qué resuelve |
|---|---|
| **Socios y planes** | Ficha, plan y estado. El pago es historial, no un campo |
| **Acceso y asistencia** | Entradas y salidas como **serie de eventos**, con canal y responsable |
| **Membresías y renovaciones** | Cobros con periodos que se encajan. El mes gratis es un periodo, no una casilla |
| **Rutinas y seguimiento** | Series, kilos y repeticiones, con progreso en el tiempo |
| **Administración** | Planes, precios, coaches y control de acceso |

## Tres decisiones que explican el resto

### 1. La asistencia es una serie de eventos, no un estado

Un socio puede venir tres veces en un día. No hay una columna "dentro/fuera":
hay **una fila por cada vez que estuvo adentro**, y al lado una bitácora que
solo crece.

Consecuencia práctica: una entrada sin salida es un **caso a tratar**, no un
olvido. El sistema la cierra después de unas horas, marcando que la cerró él y
dejando el rastro en la bitácora, para que recepción distinga "el socio salió a
las 21:30" de "nadie estaba en la casilla cuando pasó".

### 2. Las dos señales van siempre juntas

El socio deja de asistir antes de dejar de pagar, o al revés según el caso, y
no hay forma de saber de antemano cuál aparece primero.

Un socio que **dejó de venir pero sigue pagando** es el caso recuperable: hay
que llamarlo hoy. Un sistema que muestra solo la asistencia, o solo la
cobranza, esconde justo ese caso.

### 3. No hay datos de salud

**Un gimnasio no es una clínica.** No existe ninguna columna de lesión,
diagnóstico o aptitud: no es que estén ocultos, es que no existen.

Si un socio deja de asistir por una lesión, eso se conversó en recepción y no
se escribe. Un dato de salud obligaría a permisos mucho más estrictos y a base
legal distinta para algo que el sistema no usa.

---

## Seguridad entre gimnasios

Una instalación sirve a varios gimnasios, y **ninguno puede ver los datos de
otro**. Eso no depende de que el código esté bien escrito: lo impone la **Row
Level Security de PostgreSQL**.

El patrón es el mismo que ya está decidido y documentado en
[`apps/portal-autoservicio/docs/03-MULTITENANCY.md`](../portal-autoservicio/docs/03-MULTITENANCY.md):
columna `tenant_id` más políticas con `USING` y `WITH CHECK`,
`FORCE ROW LEVEL SECURITY`, y el contexto viajando con `set_config(..., true)`,
que es alcance de transacción para que un worker de colas no arrastre el
gimnasio de otro trabajo.

Además, dentro de cada gimnasio hay un segundo filtro por rol: el coach no ve
cobros, recepción no suspende socios, y solo el dueño registra periodos sin
cobro.

Detalle completo en [docs/03](docs/03-MULTITENANCY-Y-ROLES.md).

---

## Estado

> **Código escrito, sin ejecutar.**
>
> En el entorno donde se construyó no había Docker, ni PHP, ni Composer, ni
> PostgreSQL. Las pruebas están escritas y son reales, pero **no se ejecutaron**.
> Ninguna migración se aplicó. Ningún contenedor se construyó.
>
> Lo único que sí se ejecutó: `bash -n` en los cinco scripts de shell (los
> cinco pasan) y la revisión de que no queden caracteres CJK o cirílicos.

El detalle de qué está probado y qué no, en
[docs/10](docs/10-PRUEBAS-Y-ESTADO.md). Ese documento es la referencia
obligatoria antes de mostrar esto a un cliente.

---

## Estructura

```
apps/gimnasio/
├── README.md
├── .env.example
├── docs/           01 arquitectura · 02 modelo · 03 multi-tenancy
│                   04 instalación · 05 configuración · 06 primer socio
│                   07 seguridad y Ley 19.628 · 08 respaldos
│                   09 API · 10 pruebas y estado
├── api/            Laravel 13 · PostgreSQL 18 · RLS
├── web/            Nuxt 4 con SSR
└── deploy/         Docker Compose con versiones fijadas
```

## Documentación

| Documento | Qué responde |
|---|---|
| [01 Arquitectura](docs/01-ARQUITECTURA.md) | Por qué el producto es este |
| [02 Modelo de datos](docs/02-MODELO-DE-DATOS.md) | Cada tabla y cada regla de negocio |
| [03 Multi-tenancy y roles](docs/03-MULTITENANCY-Y-ROLES.md) | Cómo un gimnasio no ve los datos de otro |
| [04 Instalación](docs/04-INSTALACION.md) | Cómo se levanta, en desarrollo y en producción |
| [05 Configuración](docs/05-CONFIGURACION.md) | Variables y ajustes |
| [06 Primer socio](docs/06-PRIMER-SOCIO.md) | Un socio de punta a punta, con datos |
| [07 Seguridad y Ley 19.628](docs/07-SEGURIDAD-Y-LEY-19628.md) | Datos personales, permisos, lo que no se guarda |
| [08 Respaldos](docs/08-RESPALDOS.md) | Qué se respalda y cómo se comprueba |
| [09 API](docs/09-API.md) | Endpoints y códigos de error |
| [10 Pruebas y estado](docs/10-PRUEBAS-Y-ESTADO.md) | **Qué se probó y qué no** |

## Arranque rápido

```bash
cd apps/gimnasio
cp .env.example .env
# Complete APP_KEY y los secretos de Authentik (ver docs/04).

docker compose -f deploy/docker-compose.dev.yml up -d
docker compose -f deploy/docker-compose.dev.yml exec api php artisan db:seed

# Antes de abrir a socios, siempre:
docker compose -f deploy/docker-compose.dev.yml exec api \
    php artisan gimnasio:verificar-aislamiento
```

La semilla crea el **Gimnasio Fuerza** con seis socios. Tres están puestos a
propósito para que el panel se entienda de una:

- **Andrés** viene todas las semanas: el sistema no lo marca.
- **Patricia** dejó de venir hace 26 días pero **paga al día**: sale arriba en
  el panel, y es el caso que se puede recuperar.
- **Fernanda** nunca ha venido: es otro problema, y aparece aparte.

## Lo que este software NO hace

- No manda correos ni SMS. Deja la lista y el dueño llama.
- No guarda datos médicos, ni siquiera opcionales.
- No prescribe rutinas ni evalúa estado físico.
- No controla el acceso por sí mismo: se integra con un turno existente.

La primera es una decisión deliberada: el contacto con el socio es del
gimnasio, con su contenido y su momento.

---

© Hefesto Studio. Software propietario.