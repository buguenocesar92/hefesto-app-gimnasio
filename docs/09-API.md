# 09 - API

Todas las rutas cuelgan de `/api/v1`. Salvo el login, el callback y la salud,
**todas exigen sesión**.

> **Estado: los endpoints están escritos, no probados.** En el entorno donde
> se construyó este proyecto no había PHP, ni Composer, ni PostgreSQL, ni
> Docker. Ver [10](10-PRUEBAS-Y-ESTADO.md).

---

## 1. Convenciones

| Aspecto | Decisión |
|---|---|
| Autenticación | Cookie de sesión de Laravel, emitida por Authentik |
| CSRF | Cabecera `X-XSRF-TOKEN` (mismo origen con Nuxt SSR) |
| Fechas | ISO 8601. Las **fechas sin hora** van como `YYYY-MM-DD` |
| Montos | Enteros, en pesos chilenos |
| Errores | `{ "mensaje": "..." }` |
| Rechazo de autorización | **404**, nunca 403 |
| Recurso de otro gimnasio | **404**: no existe para ese contexto |

### El 404 es una decisión, no una comodidad

Un 403 confirmaría que el recurso existe, y con eso ya se filtró información.
El filtro por gimnasio impide que el recurso exista a ojos del otro, así que
404 es la respuesta correcta en todos los casos.

## 2. Endpoints

### Sesión

| Método | Ruta | Permiso | Qué hace |
|---|---|---|---|
| `GET` | `/salud` | Público | Estado del servicio. No toca datos de socios |
| `GET` | `/sesion` | Con sesión | Quién es la persona y qué puede hacer |

```json
GET /api/v1/sesion
{
  "usuario": {
    "id": "…", "nombre": "Jorge Muñoz",
    "correo": "recepcion@gimnasiofuerza.cl",
    "rol": "encargado",
    "gimnasio_id": "…"
  },
  "permisos": {
    "registrar_acceso": true,
    "gestionar_socios": true,
    "administrar_gimnasio": false,
    "ver_rutinas": false
  }
}
```

### Panel

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/panel` | Dueño y recepción |

```json
GET /api/v1/panel
{
  "hoy": "2026-01-20",
  "contadores": {
    "visitas_hoy": 34, "visitas_ayer": 41, "visitas_mes": 812,
    "visitas_abiertas": 12, "socios_activos": 187, "invitados_hoy": 3
  },
  "serie_30_dias": { "2025-12-22": 18, "2025-12-23": 22, "…": 0 },
  "en_riesgo": [
    {
      "aviso_id": "…", "socio_id": "…", "nombre": "Patricia Silva",
      "dias_sin_venir": 26,
      "ultima_visita_en": "2025-12-25T21:30:00-03:00",
      "ultimo_pago": "2026-01-02T09:00:00-03:00",
      "pago_al_dia": true,
      "observacion": "Sin venir hace 26 días. La membresía está al día."
    }
  ],
  "nunca_vinieron": [ { "id": "…", "nombre": "Fernanda", "apellido": "Vera", "iniciada_en": "2026-01-08" } ]
}
```

`invitados_hoy` va ** aparte** de `visitas_hoy`. Un invitado no es un socio.

### Socios

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/socios` | Personal |
| `POST` | `/socios` | Recepción y dueño |
| `GET` | `/socios/{socio}` | Personal, o el socio mismo |
| `PATCH` | `/socios/{socio}` | Recepción y dueño |

Filtros de `GET /socios`: `buscar`, `solo_activos`, `por_pagina`, `page`.

```json
POST /api/v1/socios
{
  "nombre": "Patricia",
  "apellido": "Silva",
  "documento": "18.774.221-0",
  "nacimiento": "1988-07-02",
  "telefono": "+56 9 2211 8899"
}
```

**No hay ningún campo de salud.** Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

`GET /socios/{socio}` devuelve las dos señales juntas:

```json
{
  "socio": { "…": "…" },
  "estado": {
    "ultima_visita_en": "2025-12-25T21:30:00-03:00",
    "dias_sin_venir": 26,
    "visitas_totales": 214,
    "ultimo_pago": "2026-01-02T09:00:00-03:00",
    "cubre_hoy": true,
    "motivo_bloqueo": "ninguno"
  }
}
```

### Visitas

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/visitas` | Personal |
| `POST` | `/visitas/entrada` | Recepción y dueño |
| `GET` | `/visitas/{visita}` | Personal, o el socio |
| `POST` | `/visitas/{visita}/salida` | Recepción y dueño |
| `GET` | `/visitas/{visita}/eventos` | Personal |
| `POST` | `/visitas/invitados` | Recepción y dueño |

**Registrar una entrada:**

```json
POST /api/v1/visitas/entrada
{
  "socio_id": "…",
  "canal": "manual",
  "dispositivo": null,
  "motivo_manual": "Corte de energía entre las 19:00 y las 20:30."
}
```

Cuando `canal` es `manual`, `motivo_manual` es **obligatorio**:

```json
{
  "mensaje": "Un acceso manual necesita un motivo: por ejemplo, corte de energía o fallo del turno.",
  "errors": { "motivo_manual": ["Un acceso manual necesita un motivo…"] }
}
```

Cuando el socio ya tiene una visita abierta, la respuesta es **422** con un
mensaje que recepción puede leer y actuar:

```json
{
  "mensaje": "El socio ya tiene una visita abierta desde las 19:12. Registre la salida antes de una nueva entrada."
}
```

No es 500: es una situación prevista, con una respuesta pensada para la
persona que está en la casilla.

**Registrar una salida:**

```json
POST /api/v1/visitas/…/salida
{ "canal": "dispositivo" }
```

Registrar la salida **cierra el aviso de abandono** del socio, si tenía uno.

**Un invitado:**

```json
POST /api/v1/visitas/invitados
{ "nombre": "Invitado de Andrés", "acompana_a_socio_id": "…" }
```

Va a `ingresos_invitados`. No aparece en ningún conteo de socios.

### Membresías

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/socios/{socio}/membresias` | Personal, o el socio |
| `POST` | `/socios/{socio}/membresias` | Recepción y dueño |
| `GET` | `/membresias/{membresia}` | Personal, o el socio |
| `POST` | `/membresias/{membresia}/cobros` | Recepción y dueño |
| `POST` | `/membresias/{membresia}/periodos-sin-cobro` | **Solo el dueño** |
| `POST` | `/membresias/{membresia}/suspension` | **Solo el dueño** |

**El mes gratis** es un periodo con inicio y fin, no una casilla:

```json
POST /api/v1/membresias/…/periodos-sin-cobro
{
  "desde": "2026-12-01",
  "hasta": "2027-01-01",
  "motivo": "mes_gratis",
  "nota": "Mes gratis de diciembre pactado en el contrato."
}
```

`hasta` es **exclusivo**: el periodo cubre el 1 de diciembre y el 31, y el 1
de enero ya es del cobro siguiente.

Un socio con cuenta propia **no ve los montos** de los cobros: ve que su
membresía está al día y hasta cuándo.

### Rutinas

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/socios/{socio}/rutinas` | Personal o el socio |
| `POST` | `/socios/{socio}/rutinas` | Socio, coach o dueño |
| `POST` | `/rutinas/{rutina}/registros` | Dueño de la rutina |
| `GET` | `/rutinas/{rutina}/progreso` | Dueño de la rutina |

```json
GET /api/v1/rutinas/…/progreso
{
  "progreso": [
    { "ejercicio": "Press banca", "mejor_marca_kg": 72.5,
      "ultima_marca_kg": 72.5, "ultima_fecha": "2026-01-18", "registros": 24 }
  ]
}
```

### Administración

| Método | Ruta | Permiso |
|---|---|---|
| `GET` | `/admin/planes` | Personal |
| `POST` | `/admin/planes` | **Solo el dueño** |
| `GET` | `/admin/usuarios` | **Solo el dueño** |
| `POST` | `/admin/usuarios` | **Solo el dueño** |

Dar de alta un usuario significa darle acceso a datos personales de socios.
Eso no lo puede hacer recepción.

## 3. Permisos por rol

| Endpoint | Socio | Coach | Recepción | Dueño |
|---|:---:|:---:|:---:|:---:|
| `GET /panel` | no | **no** | sí | sí |
| `GET /socios` | no | sí | sí | sí |
| `POST /socios` | no | no | sí | sí |
| `GET /socios/{id}` | el suyo | sí | sí | sí |
| `POST /visitas/entrada` | **no** | no | sí | sí |
| `POST /visitas/invitados` | no | no | sí | sí |
| `POST /membresias/…/cobros` | no | **no** | sí | sí |
| `POST /membresias/…/periodos-sin-cobro` | no | no | **no** | sí |
| `POST /admin/planes` | no | no | **no** | sí |
| `POST /admin/usuarios` | no | no | **no** | sí |

## 4. Comandos de consola

```bash
# Verificar el aislamiento. Devuelve código 1 si algo falla.
php artisan gimnasio:verificar-aislamiento

# Cerrar visitas abiertas que superaron el máximo de horas.
php artisan gimnasio:cerrar-visitas-abiertas
php artisan gimnasio:cerrar-visitas-abiertas --horas=6
php artisan gimnasio:cerrar-visitas-abiertas --gimnasio=<uuid>

# Detectar socios en riesgo.
php artisan gimnasio:detectar-inactivos
php artisan gimnasio:detectar-inactivos --dias=30
```

Los tres recorren los gimnasios activos y hacen **todo** dentro de
`ContextoGimnasio::ejecutarAislado()`, porque un proceso de consola reutiliza
la conexión igual que el worker de colas.

## 5. Errores frecuentes

| Código | Cuándo | Qué hacer |
|---|---|---|
| `401` | Sin sesión, o sesión vencida | Entrar de nuevo |
| `404` | No existe, o es de otro gimnasio | **No distingue entre los dos casos** |
| `422` | Validación, o socio con visita abierta | Leer el mensaje; suele ser accionable |
| `500` con `No hay contexto de gimnasio` | Consulta fuera del middleware | Es un bug. El aislamiento falló cerrado |

## 6. Lo que no existe en la API

- **No hay endpoint de "estado del socio"** (dentro/fuera). La asistencia es
  una serie de eventos, no un estado.
- **No hay endpoint de salud de socio.** No hay datos de salud.
- **No hay endpoint para borrar socios.** La eliminación de datos personales
  requiere una decisión de política que **no está tomada**. Ver
  [07](07-SEGURIDAD-Y-LEY-19628.md), sección 9.
- **No hay envío de correos ni notificaciones.** El sistema deja la lista de
  riesgo y el dueño decide.

Estas ausencias son deliberadas, no pendientes técnicos.