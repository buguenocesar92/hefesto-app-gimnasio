# 07 - Seguridad y Ley 19.628

Este documento aplica a los datos de los socios el mismo criterio que el resto
de las verticales de Hefesto Studio: aislamiento, permisos por rol, y cuidado
con exponer lo que no hace falta exponer.

Los datos de un socio son **datos personales** bajo la Ley 19.628. El
gimnasio trata con nombres, documentos, teléfonos y un historial detallado de
cuándo vino y cuánto pagó.

---

## 1. Lo primero: no hay datos de salud

**Un gimnasio no es una clínica.**

En este sistema **no existe** ninguna columna de lesión, diagnóstico,
aptitud médica, peso, altura, perímetro, Groupe sanguíneo ni nada parecido.

No es que los datos estén ocultos ni protegidos por un permiso especial: no
existen. La tabla `socios` tiene nombre, apellido, documento, fecha de
nacimiento, contactos y teléfono de emergencia. Nada más.

Si un socio deja de asistir por una lesión, eso se conversó en recepción y no
se escribe en el sistema. El detector de abandono ve que no vino, y nada más.

### Por qué esto importa de verdad

La razón no es solo ética ni legal. Un dato de salud **cambia la calidad del
dato que sí se puede entregar**:

- Obliga a base legal distinta (datos sensibles), con consecuencias que un
  gimnasio chico no puede asumir.
- Obliga a permisos mucho más estrictos y a restricciones de acceso para un
  dato que **el sistema no usa para nada**.
- En una filtración, "no vino tres semanas" es un dato mucho menos grave que
  un diagnóstico.

Guardarlo sería lo más caro y lo menos útil.

## 2. Aislamiento entre gimnasios

La regla más importante: **un gimnasio no puede ver los datos de otro**, y eso
no depende de que el código esté bien escrito. Lo impone la Row Level Security
de PostgreSQL.

| Capa | Qué hace | Qué detecta |
|---|---|---|
| Aplicación (Policies y middleware) | Decide y filtra | Errores de lógica |
| Base de datos (RLS) | **Impide** devolver filas ajenas | Todo lo que se le escape a la capa de arriba |

La base es la última línea. Si la aplicación tiene un error, la base sigue sin
entregar datos de otro gimnasio.

Detalles completos en [03](03-MULTITENANCY-Y-ROLES.md).

## 3. Permisos por rol

| Dato | Socio | Coach | Recepción | Dueño |
|---|:---:|:---:|:---:|:---:|
| Su propia ficha | sí | no | no | no |
| Ficha de otro socio | no | sí | sí | sí |
| **Número de documento** | solo el suyo | **no** | sí | sí |
| Su historial de visitas | sí | sí | sí | sí |
| Historial de otro | no | sí | sí | sí |
| Cobros y montos | **no ve el monto** | **no** | sí | sí |
| Contacto de emergencia | sí | sí | sí | sí |
| Rutinas | las suyas | todas | **no** | todas |

Dos decisiones que vale la pena explicar:

### El documento no sale al coach

El coach necesita ver la ficha para trabajar con el socio. El número de
documento no lo necesita. Por eso `SocioResource` lo decide **dentro del
recurso**, según quién pregunta:

```php
$puedeVerDocumento = $usuario !== null && $usuario->puedeGestionarSocios();
```

Está en el recurso y no en el controlador a propósito: si se olvida en un
endpoint nuevo, el documento sigue sin salir. Un permiso comprobado en un solo
lugar es un permiso que se puede olvidar.

### El socio no ve cuánto pagó

Ve que su membresía está al día y hasta cuándo, que es lo que necesita. El
precio es información del contrato con el gimnasio, no suya.

## 4. Datos mínimos en el invitado

`ingresos_invitados` guarda **solo el nombre**.

No hay documento, ni teléfono, ni correo, ni fecha de nacimiento. Y hay una
prueba que lo comprueba, para que no se cuelgue una columna "por si acaso":

```php
#[Test]
public function del_invitado_no_se_guardan_mas_datos_que_el_nombre(): void
{
    foreach (['documento', 'rut', 'telefono', 'correo', 'nacimiento'] as $prohibida) {
        $this->assertNotContains($prohibida, $nombres, ...);
    }
}
```

El gimnasio necesita saber quién entró. No necesita saber quién es. Pedirle el
documento a alguien que viene una vez sería tomar un dato personal sin un
motivo que lo justifique.

## 5. Seguridad de la aplicación

### CSRF

La interfaz es una SPA servida por Nuxt en el **mismo origen** que la API, y
la cookie de sesión viaja sola. El token CSRF va en la cabecera
`X-XSRF-TOKEN`, como espera Laravel.

### Validación

Cada endpoint que escribe tiene su propio `FormRequest` con reglas y mensajes
en español. La validación **no confía en el cliente**: el `socio_id` del
cuerpo se vuelve a leer de la base dentro del contexto del gimnasio, así que
un `socio_id` de otro gimnasio no resuelve nada.

La regla del acceso manual está **en los dos lados** a propósito:

1. En `RegistrarEntradaRequest`, que da un error de validación claro.
2. En `RegistroVisita`, porque el servicio también se llama desde la consola,
   desde el comando por lotes y desde las pruebas, y ninguno de esos caminos
   pasa por un `FormRequest`.

### Autorización

Cada controller llama a `$this->authorize(...)`. El rechazo devuelve **404**,
no 403, porque un 403 confirmaría que el recurso existe.

### El rol de la base de datos

No puede ser superusuario ni tener `BYPASSRLS`. Con cualquiera de las dos
cosas, PostgreSQL ignora todas las políticas RLS y el aislamiento desaparece
sin dar ningún aviso.

`gimnasio:verificar-aislamiento` lo comprueba en cada despliegue.

## 6. Sesiones y secretos

| Medida | Por qué |
|---|---|
| Sin contraseñas propias | Una contraseña robada no se recupera, y se pierde la confianza del socio |
| `SESSION_SECURE_COOKIE=true` en producción | La cookie no viaja en claro |
| `SESSION_SAME_SITE=lax` | La cookie no viaja en peticiones de terceros |
| `APP_DEBUG=false` en producción | Un error no muestra la consulta SQL con datos de socios |
| `.env.example` sin valores reales | Un archivo de ejemplo con un secreto termina en el repositorio |

El navegador nunca ve el token: Nuxt corre con SSR y actúa de servidor
intermediario.

## 7. Lo que se registra y lo que no

El sistema **no** registra:

- Direcciones IP de las entradas.
- Geolocalización.
- Qué máquina usaste ni desde dónde te conectaste.
- Pestañas ni actividad dentro de la aplicación.
- Contenido de conversaciones con coaches.

**Sí** se registra la bitácora de acceso (`eventos_acceso`), porque responde a
una pregunta legítima del dueño: "¿por qué este socio tiene una visita de 14
horas?". No incluye nada más que canal, momento y responsable.

## 8. El aviso de abandono y su oportunidad

El detector produce una lista de socios con membresía vigente que dejaron de
venir. Es información comercial, y está restringida al dueño y a recepción.

**El sistema no manda correos, ni SMS, ni notificaciones.** Deja la lista y
el dueño decide. Esto no es una limitación técnica pendiente de resolver: es
una decisión deliberada. El contacto con el socio es una decisión del
gimnasio, con su contenido y su Timing, y no corresponde que el software la
tome por su cuenta.

Cada aviso queda registrado con `detectado_en` y `cerrado_en`. Eso permite
medir si sirvió de algo, que es lo único que permite decidir si se sigue
pagando el sistema.

## 9. Conservación

> **Pendiente de definir con el cliente.** No se fijó un período de
> conservación, y no se inventó uno.

Lo que sí está resuelto:

- Las visitas y sus eventos **no se borran**. Se anulan. La historia de una
  corrección queda.
- Los avisos de abandono quedan en la historia aunque se cierren.
- Los respaldos tienen una retención configurable (`RETENCION_DIAS`, 30 por
  defecto).

Lo que hay que definir antes de un despliegue real:

| Dato | Pregunta pendiente |
|---|---|
| Historial de visitas | ¿Cuántos años se conserva? |
| Socio que se va | ¿Se anonimiza o se elimina? |
| Socio que pide copia | ¿Qué se entrega exactamente? |
| Socio que pide eliminación | ¿Qué se borra y qué se conserva por obligación legal? |

**Nadie debería implementar el borrado de socios antes de tener esta
respuesta escrita.** Es la parte de la Ley 19.628 que más se deja para el
final y más caro sale.

## 10. Riesgos que siguen abiertos

Dichos con todas las letras:

- **No hay revisión de seguridad externa.**
- **No hay pruebas de penetración.**
- **Las pruebas están escritas pero no se ejecutaron**: sin PHP, ni Composer,
  ni PostgreSQL, ni Docker en este entorno. Ver
  [10](10-PRUEBAS-Y-ESTADO.md).
- **El `slug` del gimnasio se puede enumerar sin autenticarse.** Se puede
  descubrir qué gimnasios usan la plataforma. No se puede ver más que el
  nombre. Decisión pendiente del cliente.
- **Quien tenga acceso SQL directo puede leer `tenants` entera**, que incluye
  razón social, RUT y contactos. Está compensado con la vista pública y con
  que el rol no sea superusuario, pero no es lo mismo que no tenerlo.
- **No hay política de retención acordada** (sección 9).
- **No hay cifrado de la base en reposo.** Si el servidor lo tiene, bien; si
  no, los datos personales están en claro en el disco.

## 11. Lista de verificación antes de abrir a socios

- [ ] `gimnasio:verificar-aislamiento` pasa.
- [ ] `deploy/scripts/verificar-aislamiento.sh` pasa.
- [ ] El rol de la base no es superusuario ni tiene `BYPASSRLS`.
- [ ] `APP_DEBUG=false`.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [ ] HTTPS funcionando y el certificado renovándose.
- [ ] Authentik con HTTPS y sin el grupo de administrador abierto.
- [ ] `.env.prod` fuera del repositorio, con permisos `600`.
- [ ] Respaldos automáticos funcionando y **una restauración probada**.
- [ ] Política de retención escrita y aprobada por el cliente.
- [ ] Los avisos de recuperación probados con un socio ficticio.