# Servidor de licencias AD-EL POS — Etapa 5: panel definitivo, constancias y despliegue

## Objetivo

Dejar el servidor de licencias listo para que el propietario lo despliegue en `licensing.sicrat.com` y lo use a diario:

- **contrato:** adoptar la versión 1.0 definitiva;
- **panel:** pantallas definitivas, más claras, funcionales y eficientes, partiendo del estilo actual;
- **constancias:** diseño final;
- **Nginx:** límites de intentos en el propio servidor web;
- **despliegue:** instrucciones completas para que el propietario lo instale clonando el repositorio;
- **integración:** una guía para la etapa de integración de AD-EL POS.

Esta etapa **no cambia el contrato ni el contenido firmado**.

## Documentos de referencia

| Documento | Uso |
| --- | --- |
| `CONTRATO_LICENCIAS_ADEL_POS_v3.md` | **Contrato V1.0 definitivo.** Se adopta en la Fase 0 y no se edita. |
| `DECISIONS.md` y `AVANCE.md` | Estado al cerrar la etapa 4: commit `315b8e5`, 466 pruebas |
| Esta especificación | Alcance, decisiones del propietario y pruebas |

**Prioridad:** primero las decisiones del propietario, luego el contrato y al final esta especificación.

## Decisiones del propietario para esta etapa (2026-11-17)

1. **El contrato definitivo es la V1.0 de `CONTRATO_LICENCIAS_ADEL_POS_v3.md`.**
   - Sustituye al borrador del 16 de noviembre e incorpora las 37 aclaraciones registradas como «propuestas V1.1».
   - Las n.º 36 y 37 quedan **aprobadas** como están implementadas.
   - El formato firmado no cambia: `schema_version: "1.0"`.
2. **Decisiones J a O de la etapa 4:** aprobadas.
3. **Panel:**
   - se conserva el estilo de base actual: Bootstrap, barra lateral oscura y marca de AD-EL POS;
   - Claude Code puede cambiar lo que haga falta para que el panel sea **más profesional, más funcional y más eficiente**;
   - toda decisión de diseño que no esté en esta especificación se registra en `DECISIONS.md`.
4. **Despliegue:**
   - lo hace el propietario, **clonando el repositorio** en el VPS;
   - sigue el mismo patrón de AIDEX: raíz en `public/` y nombres propios en Nginx (Fase 5);
   - Nginx 1.24.0, PHP 8.3.6 y MySQL 8.0.46 ya están instalados, y AIDEX convive en el mismo VPS;
   - Claude Code **no** accede al VPS: entrega instrucciones completas y herramientas de verificación.
5. **Versiones de PHP:** en cada fase la suite completa se corre solo en **PHP 8.3**. **PHP 8.5** se corre una sola vez, en el cierre. Playwright se corre en las fases que cambian el panel y en el cierre.

## Reglas generales

Se aplican las mismas reglas de la etapa 4:

- revisar secretos antes de cada commit;
- datos sintéticos que se borran al terminar;
- textos en español claro;
- código legible con tabuladores;
- migraciones nuevas a partir de la `009`, si hacen falta;
- un commit por fase, con todas las pruebas pasando;
- comprobar cada regla nueva quitándola una a la vez.

**Ambigüedades:**
- se resuelven con el criterio más conservador;
- se registran como **consultas al propietario** en el cierre. Desde esta etapa ya no se llaman «propuestas V1.1»;
- cualquier cambio al contrato espera a su versión 1.1.

---

## Fase 0 — Verificación y adopción del contrato definitivo

1. **Confirmar el estado de partida:**
   - commit `315b8e5` o posterior y árbol limpio;
   - 466 pruebas pasando en PHP 8.3;
   - verificador Go pasando.
2. **Adoptar el contrato definitivo:**
   - reemplazar `docs/CONTRATO_LICENCIAS_ADEL_POS.md` por el contenido de `CONTRATO_LICENCIAS_ADEL_POS_v3.md`, conservando el nombre del archivo en el repositorio;
   - actualizar el SHA-256 en `DECISIONS.md` y en `StageClosureTest`.
3. **Actualizar `DECISIONS.md`:**
   - **Nota en la sección 9 y en la 15:** las «propuestas V1.1» n.º 1 a 37 están incorporadas en la V1.0 definitiva. El número 1.1 queda reservado para el primer cambio posterior.
   - Registrar las decisiones 1 a 5 de esta especificación.
4. **Revisar las referencias al contrato** en `README.md`, la documentación, `contracts/v1/README.md` y el OpenAPI. Deben decir «V1.0» o «versión 1.0 definitiva» y apuntar al documento vigente.
5. **Confirmar que nada cambió en lo técnico:**
   - los archivos de prueba y el verificador Go no cambian;
   - el JSON Schema y el OpenAPI no cambian, salvo textos descriptivos.
6. **Listar la marca `PENDIENTE etapa 5`** que queda y asignarla a su fase.

---

## Fase 1 — Revisión del panel y propuesta de diseño

Antes de rehacer pantallas:

1. **Inventario de todas las pantallas del panel**, con una captura de escritorio y una de teléfono de cada una (en `var/screenshots/`, que no se versiona). Por cada pantalla, anotar:
   - para qué la usa el propietario;
   - qué cuesta trabajo hoy: pasos de más, información dispersa, textos técnicos, acciones escondidas;
   - qué se propone cambiar.
2. **Escribir `docs/PANEL.md`** con el diseño acordado para las fases siguientes:
   - **navegación;**
   - **pantallas;**
   - **componentes reutilizables:** tarjetas, tablas, filtros, insignias de estado, confirmaciones, avisos y estados vacíos;
   - **colores de estado:**
     - vigente: verde;
     - tolerancia: ámbar;
     - vencida, revocada o transferida: rojo o gris;
     - prueba: azul;
   - **tipografía y espaciado;**
   - **reglas de texto.**
3. Esta fase **no cambia código**, salvo `docs/PANEL.md`. Sirve para que las fases 2 y 3 sean consistentes y para que el propietario revise el razonamiento al final.

---

## Fase 2 — Panel definitivo: estructura y pantallas principales

Lo que sigue es lo mínimo esperado. Claude Code puede ampliarlo según `docs/PANEL.md`.

### 2.1 Resumen

Es la pantalla de inicio, centrada en lo que el propietario tiene que hacer hoy:

- **Tarjetas de conteo:**
  - licencias activas por tipo (suscripción, perpetua, prueba);
  - en tolerancia;
  - vencidas en los últimos 30 días;
  - alertas sin revisar.
- **«Por vencer»:** suscripciones y pruebas que vencen en los próximos 7, 15 o 30 días, con filtro. Muestra el cliente, el plan, la fecha y un acceso directo a renovar o convertir. Sirve para cobrar a tiempo.
- **Cobros del periodo:**
  - total cobrado en el mes en curso y en el anterior, en MXN, a partir del registro de pagos;
  - número de operaciones por concepto.

  Son cifras informativas, no contables.
- **Alertas recientes y actividad reciente**, con etiquetas en español.

### 2.2 Licencias

- **Búsqueda por:** cliente, razón social, `license_id`, `server_id` y referencia comercial.
- **Filtros por:** tipo, estado (vigente, tolerancia, vencida, revocada, sin activar), plan, personalizada y «con transferencia pendiente».
- **Ordenamiento** por vencimiento y por fecha de alta.
- **Paginación.**
- **Columnas:** cliente, tipo y periodo, plan y cupos resumidos, estado con su color, vencimiento en la zona del cliente y computadora activa.

### 2.3 Detalle de la licencia

Se reorganiza en **pestañas o secciones navegables**. La cabecera muestra siempre:

- el cliente;
- el estado con su color;
- tipo y periodo;
- plan y cupos;
- vencimiento y fin de actualizaciones;
- la computadora activa.

Las acciones principales van en un solo lugar: renovar, convertir, cambiar de plan, mantenimiento, editar periodo inicial, revocar y descargar. **Solo aparecen las que aplican** a esa licencia y al rol del usuario.

| Sección | Contenido |
| --- | --- |
| Resumen | Condiciones, plan, cupos, fechas, cambio programado, datos comerciales y nota |
| Pagos | Historial de pagos con concepto, monto, sugerido, bonificación y motivo |
| Computadoras y transferencias | Activación vigente, historial de activaciones, transferencias, pendiente con su liberación manual, alertas de la licencia |
| Revisiones firmadas | Cada revisión con su número, estado, motivo, fechas y descarga del `.lic` |
| Sin internet | Solicitudes de la licencia y sus decisiones |
| Constancias | Constancias generadas y su descarga |
| Historial | Cambios comerciales y auditoría de la licencia |

### 2.4 Clientes

- **Lista** con búsqueda.
- **Detalle del cliente** con:
  - sus datos y su zona horaria;
  - **todas sus licencias**, con su estado;
  - su historial de pagos.

---

## Fase 3 — Panel definitivo: operaciones, catálogo y acabado

1. **Operaciones comerciales:** emitir, renovar, convertir, cambiar de plan, mantenimiento y editar periodo inicial.
   - Se mantiene la **vista previa** antes de confirmar.
   - El resultado se presenta de forma clara: qué cambia, desde cuándo, monto sugerido y desglose de la bonificación.
   - Las operaciones sensibles piden confirmación explícita: revocar, liberar contador, transferencia forzada, revelar código.
2. **Planes:** catálogo en tabla con cupos y precios, formulario claro, y la opción de propagación explicada con un ejemplo de qué licencias cambiarían.
   - Si es razonable, se muestra cuántas licencias afectaría **antes** de guardar.
3. **Alertas:** pantalla de alertas y botón de la barra superior con el diseño nuevo.
4. **Sin internet:** importar, revisar y aprobar solicitudes, con la información que el propietario necesita para decidir.
5. **Seguridad y administradores:** se conservan sus reglas, con el diseño nuevo.
6. **Acabado general:**
   - **estados vacíos** con una indicación de qué hacer;
   - **mensajes de éxito y de error** en español claro;
   - **formularios** que conservan lo escrito cuando hay un error de validación;
   - **fechas** siempre en la zona del cliente y con su nombre;
   - **montos** con formato `$1,500.00 MXN`;
   - **accesibilidad:** etiquetas en todos los campos, contraste suficiente y navegación con teclado;
   - **teléfono:** diseño adaptable, sin desbordamiento horizontal a 390 px;
   - **rendimiento:** las listas usan paginación e índices. Si una consulta del resumen o de las listas lo necesita, se agrega un índice en una migración nueva y se justifica.
7. **Sin dependencias nuevas de producción.** Bootstrap se sigue sirviendo localmente, sin servicios externos ni CDN.

**Pruebas de las fases 2 y 3:**
- **Playwright** recorre todas las pantallas nuevas en escritorio y en teléfono:
  - búsqueda y filtros;
  - «Por vencer»;
  - cobros del periodo;
  - cada sección del detalle;
  - las operaciones con vista previa;
  - el detalle del cliente;
  - el catálogo con el conteo previo a propagar.
- **Pruebas PHPUnit** para:
  - las consultas nuevas: conteos, por vencer, cobros, búsqueda y filtros, con casos de límite (por ejemplo, el día exacto de vencimiento en la zona del cliente);
  - la visibilidad de las acciones según rol y tipo de licencia.
- **Sin regresiones:** se conservan todas las pruebas existentes del panel, con sus selectores actualizados.

---

## Fase 4 — Constancias PDF definitivas

1. **Constancia de licenciamiento:**
   - marca;
   - folio;
   - titular y RFC si existe;
   - tipo y periodo;
   - plan y cupos;
   - fechas en la zona del cliente;
   - fin de actualizaciones;
   - revisión;
   - código QR de verificación.
2. **Constancia de activación y entrega:** además de lo anterior,
   - método (en línea o sin internet);
   - computadora (`server_id`);
   - SHA-256 del `.lic` si aplica;
   - instrucciones breves para el restaurante.
3. **Las constancias no muestran montos ni pagos.** Son documentos que se entregan al cliente; el historial de pagos se consulta en el panel.
4. Se resuelve la marca `PENDIENTE etapa 5` del diseño de constancias.
5. **Se conserva:**
   - la verificación pública por folio y SHA-256;
   - el logo con su comprobación de integridad;
   - la generación en una página A4 cuando sea posible.

**Pruebas:**
- Texto de cada constancia leído con `pdftotext`, en una licencia de cada tipo y en una zona horaria con cambio de horario.
- Verificación pública.
- Revisión visual de las muestras.

---

## Fase 5 — Nginx: límites de intentos y configuración del sitio

**Referencia: la configuración de AIDEX en el mismo VPS.** Archivo `/etc/nginx/sites-available/aidex-licensing-https`, que muestra el propietario. El sitio de AD-EL POS sigue el mismo patrón:

- un `map` de `$request_id` para el identificador de correlación;
- `listen 443 ssl` con `server_name` propio;
- raíz en `public/`;
- `server_tokens off`;
- certificados de Let's Encrypt;
- `ssl_protocols TLSv1.2 TLSv1.3`;
- `client_max_body_size` acotado;
- `Strict-Transport-Security`, `X-Content-Type-Options nosniff` y `Cache-Control "no-store"` con `always`;
- el socket de un pool de PHP-FPM propio.

1. **Nombres propios en el contexto `http`.** Los dos sitios conviven en el mismo Nginx, y todo lo que se declara en el contexto `http` es global. Por eso usan nombres de AD-EL POS:
   - el `map` define `$adelpos_correlation_id`, nunca `$aidex_correlation_id`;
   - las zonas de `limit_req_zone` se llaman `adelpos_*`.

   Si un nombre chocara con uno de AIDEX, `nginx -t` fallaría y Nginx dejaría de recargar la configuración también para AIDEX.
2. **`limit_req`:**
   - se configura para la API (`/v1/`) y para el inicio de sesión del panel, con valores razonables y documentados;
   - la respuesta 429 usa el JSON del contrato (`RATE_LIMITED`), que ya existe en el ejemplo;
   - los límites de la aplicación se conservan. Nginx es una barrera adicional.
3. **Lo que se sirve:**
   - la raíz es `public/`, **igual que en AIDEX**, así que nada fuera de esa carpeta se puede servir. **No se agregan bloqueos carpeta por carpeta** para `tests/`, `contracts/`, `.git/`, `docs/` u otras: con la raíz en `public/` no hacen falta;
   - solo se comprueba que `public/` no contiene nada que no deba servirse;
   - los archivos ocultos (`/.*`) dentro de `public/` deben responder `404`.
4. **Compatibilidad con Nginx 1.24.0**, la versión del VPS:
   - no se usa ninguna directiva posterior;
   - se documenta cualquier diferencia con la versión local.
5. **Configuración completa del dominio de pruebas** (`deploy/nginx.conf.example`):
   - `licensing.sicrat.com`;
   - redirección de HTTP a HTTPS;
   - los certificados que emitirá Certbot;
   - todo lo de los puntos anteriores.

**Pruebas:**
- La prueba con Nginx real de la suite usa la configuración del ejemplo.
- Superar el límite responde `429` con el JSON del contrato.
- Un archivo oculto dentro de `public/` responde `404`.
- `nginx -t` acepta la configuración.
- **Los nombres del contexto `http` no chocan con los de AIDEX:** una prueba carga el ejemplo junto con un bloque que declara `$aidex_correlation_id` y una zona con nombre de AIDEX, y `nginx -t` pasa.

---

## Fase 6 — Instrucciones de despliegue

**Reescribir `DEPLOY_UBUNTU_24_04.md`** completo, para el caso real:

- VPS con Nginx 1.24.0, PHP 8.3.6 y MySQL 8.0.46 ya instalados;
- AIDEX conviviendo en el mismo VPS;
- el propietario clona el repositorio.

Cada paso lleva el **comando exacto**, lo que se espera ver y qué hacer si falla. Contenido:

1. **Requisitos previos:**
   - el DNS de `licensing.sicrat.com` apunta al VPS;
   - el repositorio está en un remoto privado;
   - una **llave de despliegue de solo lectura** para clonarlo;
   - extensiones de PHP requeridas, con un comando para comprobar las instaladas e instalar las que falten (por ejemplo, `php8.3-gd`).
2. **Usuario del sistema y carpetas:**
   - usuario propio `adelpos-license`;
   - código clonado en una carpeta propia;
   - configuración, llaves, `var/` y respaldos **fuera del clon**, con sus permisos.
3. **Clonar e instalar dependencias:** `composer install --no-dev --optimize-autoloader`. Ni las dependencias de desarrollo ni Node se instalan en el VPS.
4. **MySQL:**
   - base y usuarios **separados de AIDEX**: uno para migraciones y otro para la aplicación, con `bin/grants.php`;
   - contraseñas generadas en el momento, nunca escritas en el repositorio.
5. **Configuración** con `bin/configure.php`:
   - `APP_ENV=production`;
   - `LICENSE_ENVIRONMENT=test`;
   - dominio `licensing.sicrat.com`;
   - rutas de llaves y respaldos.
6. **Migraciones y permisos:** `bin/migrate.php` y luego `bin/grants.php`.
7. **Primer administrador** con `bin/bootstrap-admin.php` y su verificación en dos pasos.
8. **Llave de firma de prueba:**
   - generarla, activarla y exportar las llaves públicas con `bin/signing-key.php`;
   - indicar dónde guardar esa exportación para la integración de AD-EL POS.
9. **PHP-FPM:** un pool propio, separado del de AIDEX.
10. **Nginx:**
    - el bloque del sitio a partir del ejemplo de la Fase 5;
    - Certbot para el certificado;
    - `nginx -t` y recarga, **sin tocar la configuración de AIDEX**.
11. **Tarea diaria:** servicio y timer de systemd (limpieza, cambios programados, anclaje de auditoría y respaldo cifrado), con cómo comprobar su última ejecución.
12. **Respaldos:**
    - dónde quedan;
    - cómo copiarlos fuera del VPS;
    - dónde guardar la clave de recuperación por separado;
    - cómo hacer una restauración de prueba.
13. **Verificación final:** con `bin/preflight.php` y con el comando nuevo de la Fase 7.
14. **Actualizar a una versión nueva:**
    - `git pull` de una etiqueta;
    - `composer install`;
    - migraciones;
    - `preflight`;
    - recarga de PHP-FPM;
    - cómo volver a la versión anterior si algo falla.
15. **Convivencia con AIDEX:** qué recursos están separados y cómo comprobar que AIDEX sigue funcionando después de instalar.
16. **Problemas comunes y su solución.**

**Etiquetas de versión:** el cierre de esta etapa crea la etiqueta `v1.0.0` en el repositorio. La guía despliega etiquetas, no la rama `main`.

---

## Fase 7 — Verificación del despliegue e integración

1. **Comando nuevo `bin/verify-deployment.php`.** El propietario lo ejecuta en el VPS después de instalar. Comprueba sin modificar nada:
   - versión de PHP y extensiones;
   - permisos de carpetas y archivos de configuración y llaves;
   - conexión a la base con la cuenta de la aplicación y sus permisos exactos (sin `DELETE` donde no corresponde);
   - migraciones aplicadas;
   - llave de firma activa del entorno correcto;
   - timer instalado y su última ejecución;
   - respuesta HTTPS del dominio:
     - certificado válido;
     - redirección de HTTP;
     - cabeceras;
     - `404` en los archivos ocultos;
     - el desafío de la API respondiendo con el formato del contrato.

   Muestra un resumen en español con **OK**, **Advertencia** o **Error** y qué hacer en cada caso. Tiene pruebas.
2. **Guía nueva `docs/INTEGRACION_ADEL_POS.md`** para la etapa de integración en AD-EL POS:
   - qué leer del contrato;
   - dónde están el OpenAPI, el esquema, los archivos de prueba y el verificador Go;
   - cómo obtener las llaves públicas del entorno de prueba;
   - el flujo de activación, consulta, transferencia y liberación;
   - el flujo sin internet;
   - los estados de la §6 y los cupos de la §7;
   - qué probar contra `licensing.sicrat.com`.

   Es un documento de orientación: **no** sustituye al contrato.

---

## Fase 8 — Cierre

1. **Verificación completa:**
   - suite en **PHP 8.3 y PHP 8.5**;
   - Playwright completo en escritorio y en teléfono;
   - verificador Go;
   - formato;
   - revisión de secretos;
   - paquete de despliegue.
2. **Documentación:** se actualizan `README.md`, `ARCHITECTURE.md`, `SCHEMA.md`, `SECURITY.md`, `OPERATIONS.md` (incluido cómo usar el panel nuevo para las tareas diarias) y `TEST_PLAN.md`.
3. **`AVANCE.md`:**
   - cifras por fase;
   - reglas comprobadas;
   - capturas revisadas;
   - consultas al propietario;
   - pendientes que quedan para después del despliegue.
4. **Etiqueta `v1.0.0`** en el commit de cierre.
5. **Resumen para el propietario:**
   - qué cambió en el panel, con las capturas principales;
   - los pasos del despliegue en orden;
   - qué datos necesitará tener a la mano: el DNS, el acceso al remoto y la llave de despliegue.

---

## Criterios de aceptación

- [ ] El contrato V1.0 definitivo está adoptado, con su SHA-256 registrado. Ni el contenido firmado ni el contrato cambiaron.
- [ ] Existe `docs/PANEL.md` y el panel lo sigue: resumen con «por vencer» y cobros; licencias con búsqueda y filtros; detalle por secciones; detalle del cliente; operaciones con vista previa; acabado y versión de teléfono.
- [ ] Las constancias tienen su diseño final, sin montos, con verificación pública.
- [ ] `limit_req` de Nginx funciona con el JSON del contrato. La configuración sigue el patrón de AIDEX, con nombres propios en el contexto `http`, y es compatible con 1.24.0.
- [ ] `DEPLOY_UBUNTU_24_04.md` permite desplegar desde un clon, junto a AIDEX, sin tocar nada de AIDEX.
- [ ] `bin/verify-deployment.php` existe y tiene pruebas.
- [ ] Existe `docs/INTEGRACION_ADEL_POS.md`.
- [ ] La suite pasa en PHP 8.3 y 8.5, y pasan Playwright, el verificador Go, el formato, la revisión de secretos y el paquete. Cero marcas `PENDIENTE`.
- [ ] La etiqueta `v1.0.0` está creada.

## Fuera de esta etapa

- Acceder al VPS o desplegar: lo hace el propietario.
- Llaves de producción y dominio definitivo.
- La integración en AD-EL POS.
- Avisos por correo, avisos a los clientes por cambios del catálogo, pasarela de pagos, facturación e impuestos.
