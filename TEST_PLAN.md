# Plan de pruebas del servidor de licencias de AD-EL POS

Qué se prueba, cómo se ejecuta y qué falta probar en las etapas siguientes. Los conteos actuales de pruebas y aserciones, y la comparación con la línea base, están en [AVANCE.md](AVANCE.md).

> **Herencia temporal.** Varias pruebas verifican todavía el contenido y el protocolo heredados: `LIC-V1`/`lic+jws`, huella del equipo, una instalación por licencia y límites heredados. Esas pruebas cambian cuando las etapas 2 a 4 implementen el contrato ([docs/CONTRATO_LICENCIAS_ADEL_POS.md](docs/CONTRATO_LICENCIAS_ADEL_POS.md)); ver la sección 7.

## 1. Requisitos

| Requisito | Uso |
| --- | --- |
| PHP 8.3+ con las extensiones de `composer.json`, y `composer install` | Todas las pruebas |
| **MySQL 8.0 aislado**, dedicado a pruebas | Integración. Nunca una base de producción ni de otro proyecto. MariaDB no sirve |
| `TEST_MYSQL_DSN` (por ejemplo, `mysql:unix_socket=/tmp/mysql.sock;charset=utf8mb4`) | Servidor de pruebas. Sin él, PHPUnit **omite** la integración: una corrida con omisiones no valida MySQL |
| `TEST_MYSQL_USER`, `TEST_MYSQL_PASSWORD` | Cuenta que puede crear y borrar sus propias bases y usuarios (por omisión `root` sin contraseña) |
| `TEST_MYSQL_BIN`, `TEST_MYSQLDUMP_BIN` | `mysql` y `mysqldump` de MySQL 8, si no están en `PATH` (respaldos) |
| `TEST_FPM_BIN`, `TEST_NGINX_BIN`, `TEST_OPENSSL_BIN` | Pruebas sobre Nginx + PHP-FPM + TLS reales, si no están en `PATH` |
| `TEST_PDFTOTEXT_BIN` | `pdftotext` (Poppler) para leer el texto de las constancias |
| `git` | Revisión de secretos, paquete y equivalencia del esquema (lee el historial) |
| Go | Verificador independiente del contrato heredado |
| Node, Google Chrome (`CHROME_PATH` si no está en la ruta de macOS) | Pruebas del panel con Playwright |

## 2. Comandos

```sh
composer test:unit                       # pruebas unitarias
composer test:integration                # integración (requiere TEST_MYSQL_DSN)
composer test:contract                   # recalcula y compara contracts/v1/fixtures sin sobrescribir
env GOTOOLCHAIN=local GOPROXY=off GOSUMDB=off \
  go run contracts/v1/verify-vectors.go contracts/v1/fixtures/vectors.json
npm run format:check                     # formato del código (npm run format lo aplica)
php bin/check-secrets.php                # antes de cada commit
```

Suite completa, con todas las utilidades:

```sh
env TEST_MYSQL_DSN='mysql:unix_socket=/tmp/mysql.sock;charset=utf8mb4' \
    TEST_MYSQL_USER=root TEST_MYSQL_PASSWORD='<contraseña de pruebas>' \
    TEST_MYSQL_BIN=/ruta/mysql TEST_MYSQLDUMP_BIN=/ruta/mysqldump \
    TEST_FPM_BIN=/ruta/php-fpm TEST_NGINX_BIN=/ruta/nginx TEST_OPENSSL_BIN=/ruta/openssl \
    TEST_PDFTOTEXT_BIN=/ruta/pdftotext \
    php vendor/bin/phpunit
```

Pruebas del panel en un navegador real. Playwright está fijado en `package-lock.json` y se instala sin descargar navegadores:

```sh
npm ci --ignore-scripts --no-audit --no-fund
env TEST_MYSQL_DSN=… TEST_MYSQL_USER=… TEST_MYSQL_PASSWORD=… php tests/run-panel-tests.php [panel|trusted|all]
```

`run-panel-tests.php` hace lo siguiente:
- **`panel`:** prepara datos sintéticos con `tests/prepare-panel.php` y levanta el servidor PHP local en `127.0.0.1:8088` con `ADELPOS_LICENSE_CONFIG`. Luego ejecuta `tests/panel.cjs`, que también corre `tests/offline-panel.cjs`.
- **`trusted`:** levanta Nginx, PHP-FPM y TLS con `tests/prepare-trusted-panel.php` y ejecuta `tests/trusted-devices-panel.cjs`.
- **Al terminar**, siempre borra la base, el usuario de prueba, las llaves y la configuración temporal.
- **Capturas:** quedan en `var/screenshots/`, que git ignora.

## 3. Datos de prueba y limpieza

- `tests/MySqlFixture.php` crea por prueba una base y un usuario aleatorios `adelpos_license_test_<10 hex>`. Aplica `001_esquema_inicial.sql` y los GRANT reales de la cuenta de aplicación, y usa esa cuenta limitada para los servicios.
- **Catálogo:** el esquema inicial solo trae `adel_pos`, sin módulos. El contrato fija ese producto, así que la fixture le agrega cinco **módulos sintéticos** (`prueba_a` a `prueba_e`) y una dependencia (`prueba_e` requiere `prueba_a`), para conservar las pruebas de dependencias y ciclos.
- **Limpieza:** cada prueba borra su base, su usuario y sus llaves. Los directorios temporales usan el prefijo `adelpos-license-` y también se borran. Después de una corrida no deben quedar bases ni usuarios `adelpos_license_test_*`.
- **Semillas públicas:** las llaves de `contracts/v1/fixtures/` se derivan de `ADELPOS LICENSE PUBLIC TEST ONLY V1 <nombre>`. Se rechazan para licencias de producción y en servidores con `APP_ENV=production`.

## 4. Pruebas unitarias (`tests/Unit`)

| Archivo | Qué cubre |
| --- | --- |
| `ActivationCodeTest` | Códigos 3-4-4-4: alfabeto, aleatoriedad, normalización y rechazo estricto de formatos inválidos |
| `CalendarPeriodTest` | Motor de fechas del contrato, con resultados calculados a mano: día ancla y último día del mes, años bisiestos y 29 de febrero, cambio de año, las cuatro duraciones de suscripción, pruebas de 15 días, 1 mes y 3 meses, perpetua con 0, 6 y 12 meses, día local cerca de la medianoche UTC en `America/Monterrey`, y hora inexistente y hora repetida en `America/Tijuana`, con sus límites al microsegundo. Además compara cada hora local alrededor de un cambio de horario, en cinco zonas, contra una definición independiente de la regla |
| `AuditLabelsTest` | Toda operación de auditoría de `src/` y `bin/` tiene etiqueta en español en el panel |
| `CodeStyleTest` | Regla de código legible: indentación con tabuladores, sin espacios al final, máximo de 140 columnas, una sentencia por etiqueta PHP en las plantillas, y el formateador (`npm run format:check`) sin diferencias. La tercera prueba se omite si no están instaladas las dependencias de npm |
| `EncryptedArchiveTest` | Archivo cifrado de respaldos: ida y vuelta por bloques, permisos privados, llave incorrecta, truncado o alterado, rutas peligrosas y destino existente |
| `OfflineRequestTest` | Solicitud `.licreq` del contrato: campos exactos de cada acción, bytes firmados exactos, proyección sin el código comercial, formato anterior rechazado (huella, identidad anterior y prefijo `LICREQ-V1`), 20 contenidos inválidos, firma alterada, claves duplicadas y tamaño máximo |
| `ProductionConfigTest` | Configuración: orígenes inseguros, separación entre producción y desarrollo, entorno desconocido, claves reales obligatorias y emisor «AD-EL POS Licencias» del código de verificación. Entorno de licencias: obligatorio, solo `test` o `production`, independiente de `APP_ENV`, y `production` solo con `APP_ENV=production`; el ejemplo y `bin/configure.php` lo piden de forma explícita |
| `ProtectedRecordTest` | Registros cifrados ligados a su propósito y contexto; un propósito no puede usar el contexto de la evidencia sin internet |
| `LicenseContentTest` | Contenido firmado del contrato (sección 4): el ejemplo del contrato y los tres tipos son válidos; los campos y su orden son exactos; cada regla rota (70 casos) se rechaza nombrando el campo y nunca su valor |
| `ProtocolTest` | JSON estricto, base64url canónico, esquema sin conversiones ni campos desconocidos, encabezado exacto del JWS (`adelpos-lic+jws`) con firma verificable, mensaje exacto de la prueba de posesión (`ADELPOS-LIC-V1`), campos exactos de cada petición de la API (27 peticiones inválidas, entre ellas el protocolo anterior y `transfer`), y códigos, HTTP y formato de los errores del contrato |
| `ReleasePackageTest` | Paquete de despliegue: contenido exacto con archivos locales sembrados, manifiesto, `.sha256`, y aborto si se versiona una llave |
| `RulesTest` | UUID, fechas inválidas, dependencias y ciclos de módulos, vector TOTP RFC y repetición, cifrado del secreto de verificación, redes IPv4/IPv6 y HTTP de desarrollo. Del contrato: enteros del formulario dentro de su rango, condiciones de licencia (tipos, periodos, duraciones, meses, cupos y tolerancia) y zonas horarias válidas |
| `SecretsCheckTest` | `bin/check-secrets.php` en repositorios temporales: cada tipo de hallazgo sin imprimir el valor, contenido del índice, semillas públicas aceptadas y el propio repositorio limpio |

## 5. Pruebas de integración (`tests/Integration`, MySQL 8)

| Archivo | Qué cubre |
| --- | --- |
| `ActivationsTest` | Activación en línea con el protocolo del contrato: la misma computadora que vuelve a activar recibe su revisión vigente, otra recibe `ACTIVATION_LIMIT` con `transfer_available`, desactivación con su revisión de cierre, activaciones terminadas, `REQUEST_CONFLICT`, identidad solo en minúsculas, rechazo del protocolo anterior, idempotencia, códigos viejos o mal formados, intentos limitados, desafíos (vencimiento exacto, un solo uso, acción ligada), contenido firmado exacto de cada tipo de licencia (campo por campo y en orden), primer periodo calculado al activar en la zona del cliente, refresco, desactivación y revocación con su motivo de cierre, contador de revisiones global por licencia, contenido inválido que no se firma ni deja cambios, una instalación activa, rotación de llaves, y fallos de firma o auditoría con reversión completa |
| `AdministrationTest` | Esquema inicial (`adel_pos` sin módulos), creación única del primer administrador, emisión y conflictos, emisión de los tres tipos con sus condiciones y los cupos de su plan, licencia personalizada, validaciones de la emisión, zona horaria del cliente, verificación en dos pasos y recuperación, rechazo de renovación y mantenimiento, dependencias inválidas, permisos de consulta, último superadministrador, historia no editable, unicidad de credenciales y obligatoriedad de su copia protegida, búsqueda sin inyección y concurrencia entre dos procesos |
| `BackupTest` | Respaldo cifrado por CLI con `mysqldump` 8.0, extracción y restauración solo en base vacía, conservando historia, llaves, evidencia, constancias y el entorno de licencias |
| `ContractDataModelTest` | Reglas de la base para el modelo del contrato, probadas con escrituras directas: condiciones según el tipo de licencia, cupos de 0 a 999, plan existente, periodo completo o vacío y coherente con el tipo, `server_id`, motivo y entorno de firma de las revisiones, permisos de la cuenta de aplicación sobre planes, activaciones y revisiones, y que el primer periodo calculado por el motor de fechas cumple esas reglas en cada tipo de licencia |
| `CommercialDeliveryTest` | Constancias PDF: idempotencia, historial de revisiones, códigos recuperables solo con permiso y verificación en dos pasos, archivo firmado inmutable, y formato de código desconocido no recuperable |
| `HttpApiTest` | Ciclo en línea por HTTP real sin sesiones del panel; validación, TLS y límites con errores JSON estables; respuesta 503, sin detalles, cuando la llave activa es de otro entorno de licencias o falta `LICENSE_ENVIRONMENT` |
| `InitialSchemaTest` | Equivalencia de `001_esquema_inicial.sql`, sola, con la cadena original 001-007, leída del primer commit. Solo difieren los dos endurecimientos registrados. También: base vacía con todas las migraciones y el catálogo de planes, repetición sin efecto, entornos de firma `test` y `production` en llaves y ámbitos, migrador (pendientes, modificadas e incompletas) y permisos por tabla |
| `SigningEnvironmentsTest` | Entornos de firma del contrato: registro, archivo y carpeta por entorno; identificador sugerido y etiqueta del entorno; el servidor firma solo con la llave activa de su entorno, aunque se cambie la etiqueta en el registro o se copie el archivo; arranque y `bin/preflight.php` detenidos si la llave activa o la carpeta son de otro entorno; semillas públicas rechazadas; exportación de llaves públicas sin nada privado; y entorno visible en cada operación de `bin/signing-key.php` |
| `OfflineTest` | Flujo sin internet con el formato del contrato: importación cifrada, bytes alterados, aprobación idempotente, rechazo, `renew` que entrega la revisión vigente sin firmar otra (registrar una renovación al aprobar se rechaza), primer periodo al aprobar, la misma computadora que vuelve a activar, activación terminada y licencia revocada que reciben su cierre, formato anterior rechazado, desactivación firmada, transferencia forzada atómica, evidencia alterada, roles, espacio de `request_id` compartido, recuperación por equipo averiado y concurrencia |
| `ProductionHttpTest` | Pila real Nginx + PHP-FPM + TLS: cookies `__Host-`, encabezados de seguridad, marca AD-EL POS (logos servidos con su SHA-256), control de acceso, permisos de los controladores, privacidad de la verificación pública y página de mantenimiento sin secretos |
| `TrustedDevicesHttpTest` | Dispositivos de confianza sobre HTTPS real: autorización, cierre de sesión, revocación, CSRF y origen ajeno |
| `TrustedDevicesTest` | Reglas de confianza: verificación en dos pasos, vencimiento a 30 días sin extensión, ligada a la cuenta, revocación, cambio de contraseña, cuenta deshabilitada, fallas de consulta y nueva autenticación |

Los procesos auxiliares `tests/activation-worker.php`, `tests/offline-worker.php` y `tests/race-worker.php` prueban la concurrencia real. Leen `performance_schema.data_locks` para comprobar que dos procesos esperan el mismo bloqueo, en lugar de confiar en pausas.

## 6. Pruebas del panel (Playwright)

| Script | Qué recorre |
| --- | --- |
| `tests/panel.cjs` | Acceso, verificación en dos pasos, roles, CSRF y origen, emisión de los tres tipos de licencia con su plan, rechazo de renovación y mantenimiento, navegación en móvil y la marca: título «AD-EL POS — Licencias», logo visible y ninguna imagen rota |
| `tests/offline-panel.cjs` | Flujo sin internet en el panel: importar, revisar, aprobar, rechazar, descargar `.lic`, transferencia forzada con nueva autenticación, desactivación firmada y solicitud de renovación sin registrar una renovación |
| `tests/trusted-devices-panel.cjs` | Dispositivos de confianza en escritorio y móvil sobre HTTPS real |

## 7. Qué se probará en las etapas siguientes

| Etapa | Pruebas nuevas o sustituidas |
| --- | --- |
| 2 | Contenido exacto de la licencia del contrato, `typ` `adelpos-lic+jws`, entornos `test`/`production`, prueba de posesión `ADELPOS-LIC-V1` y `.licreq` `ADELPOS-LICREQ-V1`, reintentos y errores. Nuevos archivos del contrato con su verificador en Go. Las pruebas de los puntos `HEREDADO:` se reemplazan |
| 3 | Planes y su propagación, tipos `perpetual`/`subscription`/`trial`, meses de calendario con día ancla, renovación desde el vencimiento o desde el pago, 5 días de tolerancia, pruebas sin tolerancia y actualizaciones de perpetuas |
| 4 | Transferencia por autoservicio (una pendiente, confirmación, `TRANSFER_LIMIT`), alertas del panel y ajustes del flujo sin internet |
| 5 | Pantallas nuevas del panel, contenido de las constancias de AD-EL POS y verificación del despliegue en el VPS (PHP 8.3.6, MySQL 8.0.46, Nginx 1.24.0) |
