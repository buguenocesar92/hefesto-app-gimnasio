# 10 - Pruebas y estado

## 1. Estado, en una línea

**Código escrito, sin ejecutar.**

En el entorno donde se construyó este proyecto **no había Docker, ni PHP, ni
Composer, ni PostgreSQL**. Por lo tanto:

- Las pruebas están **escritas**, y son reales, pero **no se ejecutaron**.
- Las migraciones están **escritas**, y son reales, pero **no se corrieron**.
- La semilla está escrita y **no se corrió**.
- Los contenedores **no se construyeron**.
- La API **no se levantó**.

Este documento separa lo que existe de lo que se comprobó, y lo que se
comprobó de lo que queda pendiente. Nada de lo que sigue está simulado.

## 2. Lo que SÍ se ejecutó de verdad

| Qué | Resultado |
|---|---|
| **`bash -n` en los 5 scripts `.sh`**, uno por uno | **Los 5 pasan** |
| **Balance de llaves, paréntesis y corchetes en los 92 archivos `.php`** | **0 desbalances** |
| **JSON válido** de `composer.json` y `package.json` | **Los 2 válidos** |
| **XML bien formado** de `phpunit.xml`, con sus 4 suites | **Correcto** |
| **Compose**: sin tabulaciones, servicios y volúmenes declarados | **Correcto** |
| **Imágenes con versión fijada** (sin `latest`) | **Todas fijadas** |
| Búsqueda de caracteres CJK y cirílicos en todo el árbol | **0 coincidencias** (dos pasadas) |
| Búsqueda de palabras clave de shell traducidas | **0 coincidencias en código ejecutable** |

### 2.1 Validación de los scripts de shell

Cada script se validó por separado con `bash -n`, que comprueba la sintaxis
sin ejecutar nada:

```
OK    api/docker/entrypoint.sh
OK    scripts/mantenimiento.sh
OK    scripts/respaldar.sh
OK    scripts/restaurar.sh
OK    scripts/verificar-aislamiento.sh
```

Los cinco usan las palabras clave sin traducir: `while`, `for`, `do`, `done`,
`until`, `case`, `esac`. Un `hasta` o un `hecho` rompería el contenedor, y la
búsqueda de términos traducidos en los cinco archivos no encontró ninguno.

> **Lo que esto NO comprueba:** que los scripts hagan lo que dicen.
> `bash -n` lee la sintaxis, no la lógica. Un script que respalda la base
> equivocada pasa `bash -n` sin problema.

### 2.2 Balance estructural del PHP

Como no hay PHP, no se pudo correr `php -l`. Lo que sí se hizo fue una
verificación estructural propia: se quitaron de cada archivo los comentarios,
las cadenas y los cuerpos de heredoc, y se contou que `{`/`}`, `(`/`)` y
`[`/`]` quedaran parejos.

**Los 92 archivos quedaron balanceados.**

> **Esto NO es un linter y no lo reemplaza.** Detecta una llave o un paréntesis
> que falte, y nada más. No detecta un nombre de clase mal escrito, un método
> que no existe, un tipo de retorno incompatible ni un argumento faltante.
> Para eso hace falta `php -l` y un análisis estático.

Esa comprobación sí encontró dos errores reales de referencias: un `use` que
apuntaba a `ContextoTenant` después de renombrar la clase a `ContextoGimnasio`,
y una llamada a un método inexistente en `MembresiaController`. Los dos están
corregidos.

### 2.2 Revisión de caracteres

Se recorrió todo el árbol buscando:

- Caracteres CJK (japonés, chino, coreano).
- Cirílico (ruso).

**Resultado: 0 coincidencias** en ambas pasadas.

## 3. Lo que NO se ejecutó, y por qué

| Qué | Por qué no |
|---|---|
| `composer install` | No hay Composer |
| `php artisan migrate` | No hay PHP ni PostgreSQL |
| `php artisan db:seed` | Ídem |
| `php artisan test` | Ídem |
| `docker compose build` | No hay Docker |
| `docker compose up` | Ídem |
| La API respondiendo una petición | Ídem |
| La interfaz compilando | No hay Node en el contenedor de pruebas |
| Un respaldo y su restauración | Ídem |

**No se corrió nada de lo que está en la tabla.** Nada se dio por probado.

## 4. Lo que falta y hay que hacer primero

En este orden. Los tres primeros son bloqueantes.

### 4.1 Bloqueante: correr las pruebas

```bash
docker compose -f deploy/docker-compose.dev.yml up -d postgres redis
docker compose -f deploy/docker-compose.dev.yml exec api php artisan test
```

| Suite | Qué cubre |
|---|---|
| `Aislamiento` | Que un gimnasio no vea los datos de otro |
| `Visitas` | Una visita abierta, medianoche, acceso manual, invitados |
| `Feature` | Membresías, mes gratis, detector de abandono |
| `Unidad` | (vacía por ahora) |

### 4.2 Bloqueante: verificar el aislamiento

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
    php artisan gimnasio:verificar-aislamiento

deploy/scripts/verificar-aislamiento.sh
```

Ambos tienen que pasar **antes** de que entre un solo socio.

### 4.3 Bloqueante: aplicar las migraciones

```bash
docker compose -f deploy/docker-compose.dev.yml exec api \
    php artisan migrate --force
```

Hay un orden que importa: la migración 11 crea `vw_visitas`, y la 15 verifica
que tenga `security_invoker = true`. Sin la 11, la 15 falla a propósito.

### 4.4 Antes de producción

- [ ] Configurar Authentik y **comprobar que el claim `groups` llega** (ver
      [05](05-CONFIGURACION.md), sección 3). No se pudo verificar.
- [ ] Probar el flujo completo con un socio ficticio, desde la entrada hasta
      el aviso de abandono.
- [ ] Probar un respaldo y **su restauración**.
- [ ] Definir con el cliente la **política de retención de datos**, que no
      está decidida. Ver [07](07-SEGURIDAD-Y-LEY-19628.md), sección 9.

## 5. Lo que hay que revisar y no se pudo revisar

| Riesgo | Por qué |
|---|---|
| **Errores de tipeo en PHP** | Sin PHP no hay `php -l`. Un archivo con un error de sintaxis no se habría detectado |
| **Imports que no se usan o que faltan** | Sin PHP no hay análisis estático |
| **Las 16 migraciones** | No se ejecutó ninguna. El SQL puede tener un error de sintaxis |
| **La vista `vw_visitas`** | `security_invoker` requiere PostgreSQL 15 o superior. El despliegue usa 18, pero no se probó |
| **`array_agg` con `ORDER BY`** | Es de PostgreSQL. La sintaxis es válida, pero no se ejecutó |
| **La claim `groups` de Authentik** | No había instancia de Authentik |
| **La rotación de llaves de Authentik** | No había instancia de Authentik |
| **La interfaz Nuxt** | No se compiló. Puede haber errores de tipos |
| **Los Dockerfiles** | No se construyeron. Las versiones fijadas no se verificaron contra Docker Hub |

El punto más delicado es **PHP sin linter**. Todo el código fue revisado a ojo,
y un error de sintaxis en un archivo que nadie ejecutó es una posibilidad real.
Lo primero que hay que hacer es `php -l` sobre todo `app/`, y después las
pruebas.

## 6. Las pruebas que importan, y qué comprueban

### 6.1 Aislamiento entre gimnasios

`tests/Feature/Aislamiento/AislamientoDeDatosTest.php`

| Prueba | Qué demuestra |
|---|---|
| `sin_contexto_de_gimnasio_la_consulta_falla` | Sin contexto, la consulta **falla**. No devuelve cero filas en silencio |
| `cada_gimnasio_solo_ve_sus_propias_filas` | Cada gimnasio ve solo lo suyo |
| `el_socio_de_otro_gimnasio_no_existe` | Un socio ajeno es `null`, no un error |
| `no_se_puede_actualizar_una_fila_de_otro_gimnasio` | La RLS filtra antes de escribir |
| `no_se_puede_insertar_con_el_gimnasio_de_otro` | `WITH CHECK` impide la fila |
| `las_visitas_de_otro_gimnasio_tampoco_se_ven` | También las visitas |
| `el_contexto_no_sobrevive_a_la_transaccion` | **El worker de colas no arrastra el gimnasio de otro trabajo** |
| `la_funcion_de_contexto_falla_cerrada` | Falla cerrado |
| `la_vista_de_visitas_respeta_el_aislamiento` | **`security_invoker` funciona: la vista no se salta la RLS** |
| `la_politica_tiene_todas_las_tablas_de_negocio` | Ninguna tabla quedó sin RLS, y ninguna sin `FORCE` |

`tests/Feature/Aislamiento/AislamientoEnEndpointsTest.php`

| Prueba | Qué demuestra |
|---|---|
| `sin_sesion_los_endpoints_responden_401` | No hay endpoint de negocio abierto |
| `un_socio_de_otro_gimnasio_responde_404` | **404, no 403.** Un 403 confirmaría que existe |
| `una_visita_de_otro_gimnasio_responde_404` | Ídem |
| `la_lista_de_socios_no_trae_ajenos` | La paginación no filtra |
| `el_documento_no_sale_a_un_coach` | El dato personal no viaja a quien no lo necesita |
| `el_coach_no_ve_el_panel_de_socios_en_riesgo` | La información de negocio está restringida |
| `un_socio_no_puede_registrar_su_entrada` | El acceso lo registra quien controla la puerta |

### 6.2 Un socio no puede tener dos visitas abiertas

`tests/Feature/Visitas/VisitaUnicaAbiertaTest.php`

| Prueba | Qué demuestra |
|---|---|
| `el_servicio_rechaza_una_segunda_entrada` | El mensaje es legible para recepción |
| **`la_base_impide_dos_visitas_abiertas_aunque_el_servicio_no_mire`** | **La garantía no depende del código.** Escribe directo en la tabla y la base lo rechaza |
| `el_indice_solo_considera_las_visitas_abiertas` | Un socio puede tener todas las cerradas que quiera |
| `dos_socios_pueden_estar_adentro_al_mismo_tiempo` | La regla es por socio, no global |
| `al_registrar_la_salida_puede_volver_a_entrar` | Cerrar libera la entrada |
| `una_visita_anulada_libera_la_entrada` | Una entrada mal leída no bloquea la puerta |
| `el_sistema_puede_cerrar_una_visita_abierta` | El cierre automático queda marcado y en la bitácora |

La tercera prueba de esa tabla es la importante: escribe en `visitas` **saltándose
`RegistroVisita` a propósito**, para demostrar que la regla vive en la base.

### 6.3 El cruce de medianoche

`tests/Feature/Visitas/VisitaQueCruzaMedianocheTest.php`

| Prueba | Qué demuestra |
|---|---|
| `23:50_a_00:10_es_una_sola_visita_del_dia_de_la_entrada` | **Una visita, del día de la entrada, de 20 minutos** |
| `el_conteo_del_dia_suma_una_sola_vez` | No inventa una visita el día siguiente |
| `tres_visitas_el_mismo_dia_se_cuentan_tres` | La asistencia es una serie de eventos |
| `el_dia_local_usa_la_zona_del_gimnasio` | No la del servidor (que en un contenedor es UTC) |
| `una_visita_abierta_no_se_parte_en_dias` | Sigue abierta, pero es del día de la entrada |
| `una_visita_anulada_no_entra_en_los_conteos` | La fila sigue, el conteo no |

### 6.4 Otras

| Archivo | Qué cubre |
|---|---|
| `tests/Feature/Visitas/AccesoManualTest.php` | El motivo obligatorio, el canal como evidencia, la salida inválida, la bitácora completa |
| `tests/Feature/Visitas/VisitanteInvitadoNoEsSocioTest.php` | El invitado no entra en los conteos, y **la tabla no tiene documento ni teléfono** |
| `tests/Feature/Membresias/MesGratisTest.php` | El mes gratis como periodo, el encaste sin huecos, los periodos cruzados rechazados |
| `tests/Feature/Retencion/DetectorDeInactividadTest.php` | Detecta, no detecta al que viene, muestra las dos señales, el aviso se cierra al volver, no se apila |

## 7. Cómo se evita el falso verde

El riesgo más grave de una suite de pruebas de aislamiento es correr contra
SQLite: **SQLite no tiene Row Level Security ni índices únicos parciales**, y
todas esas pruebas pasarían sin comprobar absolutamente nada.

Por eso `tests/CreatesApplication.php` falla de forma ruidosa si la conexión no
es PostgreSQL, y las dos suites críticas usan:

```php
if (! $this->sePuedeProbarConEstaBase()) {
    $this->markTestSkipped('Requiere PostgreSQL: la Row Level Security no existe…');
}
```

Un salto es visible en el reporte. Un falso verde no lo es.

Lo mismo pasa con `phpunit.xml`, que fija `DB_CONNECTION=pgsql` y la base de
pruebas, de modo que no se pueda correr por accidente contra otra cosa.

## 8. Cobertura: qué NO tiene pruebas

| Área | Sin pruebas |
|---|---|
| Controllers de administración (`PlanController`, `UsuarioController`) | **Sí** |
| `RutinaController` y el cálculo de progreso | **Sí** |
| `AuthController` y el flujo de Authentik | **Sí** |
| `ContadoresAsistencia` con Redis real | **Sí** (las pruebas usan `CACHE_STORE=array`) |
| `VerificadorIdToken` | **Sí** |
| La interfaz Nuxt | **Sí** |
| La API completa de punta a punta | **Sí** |

Esto es real y hay que decirlo: las pruebas cubren a fondo lo que el producto
es (aislamiento, asistencia, membresías, abandono) y **casi nada de lo
periférico**.

La ausencia de pruebas de los controllers de administración es una brecha
concreta: dar de alta usuarios y cambiar precios es exactamente lo que un
compromiso de un socio intentaría abusar, y es la parte con menos protección
probada.

## 9. Qué falta para poder decir "probado"

En este orden:

1. `php -l` sobre todo `api/app/` y `api/database/`.
2. Levantar PostgreSQL y correr las 16 migraciones.
3. Correr `gimnasio:verificar-aislamiento` y que pase.
4. Correr `php artisan db:seed` y que el gimnasio de ejemplo aparezca.
5. Correr la suite completa y que pase.
6. Pruebas de los controllers de administración.
7. Probar a mano el flujo completo con un socio ficticio.
8. Revisión de seguridad externa.

Los puntos 1 a 5 son un día de trabajo. El 8 es una inversión de otra
proporción, y no se puede reemplazar por ninguna cantidad de pruebas propias.

## 10. Declaración de honestidad

- Ninguna prueba de este proyecto se ejecutó.
- Ninguna migración se aplicó.
- Ningún contenedor se construyó.
- La única verificación real fue la sintaxis de los scripts de shell con
  `bash -n`, y la revisión de caracteres CJK y cirílicos.
- El código se revisó a ojo y se buscaron referencias cruzadas, pero eso no
  sustituye a un linter ni a una suite.

El sistema **no está listo para recibir socios**. Está listo para que alguien
lo levante, corra las pruebas y descubra lo que falta.

---

## Los dos roles de PostgreSQL: escritos, no ejecutados

La separación entre el rol de arranque (`gimnasio_admin`) y el rol de la
aplicación (`gimnasio`) está escrita en el `compose`, en
`deploy/postgres/inicializar-rol.sh` y en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md). **No se ha ejecutado
ninguna vez.**

En el equipo donde se escribió este cambio no había PostgreSQL ni Docker. Queda
pendiente:

1. Levantar el `compose` con el volumen de PostgreSQL vacío y confirmar que el
   rol `gimnasio` se crea sin error.
2. Confirmar con `SELECT rolname, rolsuper, rolbypassrls FROM pg_roles` que el
   rol de la conexión no es superusuario ni salta la RLS.
3. Correr `php artisan gimnasio:verificar-aislamiento` y confirmar que sale con
   código 0.
4. Probar el escenario que motiva todo el cambio: un gimnasio intentando leer
   fichas de socios de otro.

Lo único verificado es estático: `bash -n` pasa sobre el script y el `compose`
es YAML válido, ambas cosas comprobadas por `tools/validar_apps.py`. El
razonamiento se apoya en la documentación oficial de PostgreSQL 18, no en una
ejecución.