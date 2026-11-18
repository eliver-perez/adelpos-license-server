# Plan de pruebas del servidor de licencias de AD-EL POS

Qué se prueba, cómo se ejecuta y qué falta probar en las etapas siguientes. Los conteos actuales de pruebas y aserciones, y la comparación con la línea base, están en [AVANCE.md](AVANCE.md).

> **Etapa 5 cerrada, versión 1.0.0.** Además del contrato V1.0, del ciclo comercial (etapa 3) y de las transferencias y alertas (etapa 4), las pruebas verifican el panel definitivo (resumen, licencias con búsqueda y filtros, detalle por secciones, clientes, formularios rechazados y versión de teléfono), las constancias finales sin montos con su verificación pública, la configuración de Nginx con sus límites de intentos, la guía de despliegue y la verificación del despliegue. Toda la suite pasa en PHP 8.3 (la versión del VPS) y en PHP 8.5: cada fase se corrió en PHP 8.3, y PHP 8.5 en el cierre. Las pruebas verifican el contrato V1.0 ([docs/CONTRATO_LICENCIAS_ADEL_POS.md](docs/CONTRATO_LICENCIAS_ADEL_POS.md)): contenido firmado, API en línea, archivos sin internet, fechas y archivos formales de `contracts/v1/`. El protocolo anterior solo aparece en pruebas que comprueban que se rechaza. Cada fase comprobó además sus reglas quitándolas una a la vez (ver AVANCE.md). Lo que queda por probar después del despliegue está en la sección 7.

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
| Go | Verificador de referencia del contrato (`contracts/v1/verify/`). Sin Go, la prueba que lo ejecuta se omite |
| Node, Google Chrome (`CHROME_PATH` si no está en la ruta de macOS) | Pruebas del panel con Playwright |

## 2. Comandos

```sh
composer test:unit                       # pruebas unitarias
composer test:integration                # integración (requiere TEST_MYSQL_DSN)
composer test:contract                   # recalcula y compara contracts/v1/fixtures sin sobrescribir
env GOTOOLCHAIN=local GOPROXY=off GOSUMDB=off \
  go run -C contracts/v1/verify . ../fixtures/vectors.json
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

La misma suite en PHP 8.3, la versión del VPS (en macOS, `brew install php@8.3`, que no reemplaza al `php` del equipo):

```sh
env TEST_FPM_BIN=/opt/homebrew/opt/php@8.3/sbin/php-fpm … /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit
```

Pruebas del panel en un navegador real. Playwright está fijado en `package-lock.json` y se instala sin descargar navegadores:

```sh
npm ci --ignore-scripts --no-audit --no-fund
env TEST_MYSQL_DSN=… TEST_MYSQL_USER=… TEST_MYSQL_PASSWORD=… php tests/run-panel-tests.php [panel|trusted|all]
```

`run-panel-tests.php` hace lo siguiente:
- **`panel`:** prepara datos sintéticos con `tests/prepare-panel.php` y levanta el servidor PHP local en `127.0.0.1:8088` con `ADELPOS_LICENSE_CONFIG`. Luego ejecuta `tests/panel.cjs`, que también corre `tests/screens-panel.cjs`, `tests/offline-panel.cjs`, `tests/commercial-panel.cjs` y `tests/transfers-panel.cjs`.
- **`trusted`:** levanta Nginx, PHP-FPM y TLS con `tests/prepare-trusted-panel.php` y ejecuta `tests/trusted-devices-panel.cjs`.
- **Al terminar**, siempre borra la base, el usuario de prueba, las llaves y la configuración temporal.
- **Capturas:** quedan en `var/screenshots/`, que git ignora.

## 3. Datos de prueba y limpieza

- `tests/MySqlFixture.php` crea por prueba una base y un usuario aleatorios `adelpos_license_test_<10 hex>`. Aplica todas las migraciones y los GRANT reales de la cuenta de aplicación, y usa esa cuenta limitada para los servicios.
- **Catálogo:** el esquema inicial solo trae `adel_pos`, sin módulos. El contrato fija ese producto, así que la fixture le agrega cinco **módulos sintéticos** (`prueba_a` a `prueba_e`) y una dependencia (`prueba_e` requiere `prueba_a`), para conservar las pruebas de dependencias y ciclos.
- **Limpieza:** cada prueba borra su base, su usuario y sus llaves. Los directorios temporales usan el prefijo `adelpos-license-` y también se borran. Después de una corrida no deben quedar bases ni usuarios `adelpos_license_test_*`.
- **Semillas públicas:** las llaves de `contracts/v1/fixtures/` se derivan de `ADELPOS LICENSE PUBLIC TEST ONLY V1 <nombre>`. Se rechazan para licencias de producción y en servidores con `APP_ENV=production`.

## 4. Pruebas unitarias (`tests/Unit`)

| Archivo | Qué cubre |
| --- | --- |
| `ActivationCodeTest` | Códigos 3-4-4-4: alfabeto, aleatoriedad, normalización y rechazo estricto de formatos inválidos |
| `CalendarPeriodTest` | Motor de fechas del contrato, con resultados calculados a mano: día ancla y último día del mes, años bisiestos y 29 de febrero, cambio de año, las cuatro duraciones de suscripción, pruebas de 15 días, 1 mes y 3 meses, perpetua con 0, 6 y 12 meses, día local cerca de la medianoche UTC en `America/Monterrey`, y hora inexistente y hora repetida en `America/Tijuana`, con sus límites al microsegundo, y los 12 meses anteriores de la alerta de transferencias frecuentes. Además compara cada hora local alrededor de un cambio de horario, en cinco zonas, contra una definición independiente de la regla |
| `AuditLabelsTest` | Toda operación de auditoría de `src/` y `bin/` tiene etiqueta en español en el panel |
| `CreditTest` | Bonificación de un cambio de plan: casos al centavo, redondeo de la mitad hacia arriba, segundos completos, periodo que no empieza (completo) o que ya venció, varios periodos con cada parte redondeada por separado, y el valor más alto en un año bisiesto |
| `MoneyTest` | Montos en MXN: lectura estricta (sin negativos, comas, signo de pesos ni más de dos decimales), máximo, valores guardados y formato |
| `RenewalDatesTest` | Renovación (§5.3) en el motor de fechas: los cuatro ejemplos del contrato, el límite exacto de la tolerancia en horas exactas, día ancla 31 encadenado, cambio de duración, cambio de horario y el periodo encadenado que no se salta un mes en Singapur, Kuala Lumpur y Sofía |
| `DeployGuideTest` | La guía de despliegue (etapa 5): cada herramienta de `bin/` y archivo de `deploy/` que nombra existe; sus 16 secciones; clon con la llave de despliegue en una etiqueta, nunca `main`; cada `composer install` con `--no-dev`; contraseñas generadas por MySQL; sus rutas son las de los ejemplos de Nginx, del pool y de la tarea diaria, que entra al clon y usa `php8.3` |
| `InputTest` | Mensajes de validación en español que nombran el campo y dicen qué corregir |
| `LicenseActionsTest` | Las acciones que ofrece el detalle de una licencia según su tipo, su estado y el rol de la cuenta |
| `LicenseStateTest` | Estado de una licencia en el panel (sin activar, vigente, en tolerancia, vencida y revocada) con los límites exactos de §5.6, su texto y su color, y los días de calendario en la zona del cliente, también con cambio de horario |
| `RuntimeGrantsTest` | Permisos de la cuenta de la aplicación: la lista que imprime `bin/grants.php` y su comparación con `SHOW GRANTS`, privilegio por privilegio y columna por columna; las tablas de historia solo con SELECT e INSERT |
| `StageClosureTest` | Cierre de etapa: el contrato no cambió (su SHA-256), no quedan marcas de la herencia ni del protocolo anterior en el código del servidor, y ninguna marca `PENDIENTE` |
| `CodeStyleTest` | Regla de código legible: indentación con tabuladores, sin espacios al final, máximo de 140 columnas, una sentencia por etiqueta PHP en las plantillas, y el formateador (`npm run format:check`) sin diferencias. La tercera prueba se omite si no están instaladas las dependencias de npm |
| `ContractFilesTest` | Archivos formales del contrato contra el código: los archivos de prueba son los que escribe su generador; cada licencia válida cumple `license.schema.json`, la acepta el validador del servidor y el servidor firma sus mismos bytes; cada caso negativo falla el esquema y el validador justo cuando se espera; cada regla del validador está en el esquema salvo las 11 que un esquema no puede expresar; el motor reproduce los 40 casos de fechas y `Domain\Credit` los 11 de bonificación, con varios periodos; la transferencia por autoservicio de los archivos conserva fechas y derechos, y cada licencia y caso de revisión van a la instalación que los verifica; el protocolo reproduce las pruebas de posesión y acepta los `.licreq`; las rutas, los campos de cada petición, los códigos de error y los ejemplos de `openapi.yaml` coinciden con el código; las semillas de prueba son las que el servidor rechaza en producción; y el verificador de referencia en Go acierta todos los casos |
| `EncryptedArchiveTest` | Archivo cifrado de respaldos: ida y vuelta por bloques, permisos privados, llave incorrecta, truncado o alterado, rutas peligrosas y destino existente |
| `OfflineRequestTest` | Solicitud `.licreq` del contrato: campos exactos de cada acción, bytes firmados exactos, proyección sin el código comercial, formato anterior rechazado (huella, identidad anterior y prefijo `LICREQ-V1`), 20 contenidos inválidos, firma alterada, claves duplicadas y tamaño máximo |
| `ProductionConfigTest` | Configuración: orígenes inseguros, separación entre producción y desarrollo, entorno desconocido, claves reales obligatorias y emisor «AD-EL POS Licencias» del código de verificación. Entorno de licencias: obligatorio, solo `test` o `production`, independiente de `APP_ENV`, y `production` solo con `APP_ENV=production`; el ejemplo y `bin/configure.php` lo piden de forma explícita |
| `ProtectedRecordTest` | Registros cifrados ligados a su propósito y contexto; un propósito no puede usar el contexto de la evidencia sin internet |
| `LicenseContentTest` | Contenido firmado del contrato (sección 4): el ejemplo del contrato y los tres tipos son válidos; los campos y su orden son exactos; cada regla rota (71 casos) se rechaza nombrando el campo y nunca su valor |
| `ProtocolTest` | JSON estricto, base64url canónico, esquema sin conversiones ni campos desconocidos, encabezado exacto del JWS (`adelpos-lic+jws`) con firma verificable, mensaje exacto de la prueba de posesión (`ADELPOS-LIC-V1`), campos exactos de cada petición de la API (27 peticiones inválidas, entre ellas el protocolo anterior y `transfer`), y códigos, HTTP y formato de los errores del contrato |
| `ReleasePackageTest` | Paquete de despliegue: contenido exacto con archivos locales sembrados (con la verificación del despliegue y las guías del panel y de la integración), manifiesto, `.sha256`, y aborto si se versiona una llave |
| `RulesTest` | UUID, fechas inválidas, dependencias y ciclos de módulos, vector TOTP RFC y repetición, cifrado del secreto de verificación, redes IPv4/IPv6 y HTTP de desarrollo. Del contrato: enteros del formulario dentro de su rango, condiciones de licencia (tipos, periodos, duraciones, meses, cupos y tolerancia) y zonas horarias válidas |
| `ViewTest` | Textos del panel y de las constancias (también los de la etapa 5: insignias de estado con su texto y su color, días restantes, fechas largas y por mes, identificadores cortos, enlaces con filtros, íconos y lo escrito en un formulario rechazado, sin secretos): fechas con su zona horaria a la vista (las de una licencia, en la zona de su cliente), «Empieza al activar», nombre de cada tipo de licencia con su condición y motivo de cierre de una revisión, y los textos de las dos alertas, con la ventana de 12 meses en la zona del cliente |
| `SecretsCheckTest` | `bin/check-secrets.php` en repositorios temporales: cada tipo de hallazgo sin imprimir el valor, contenido del índice, semillas públicas aceptadas y el propio repositorio limpio |

## 5. Pruebas de integración (`tests/Integration`, MySQL 8)

| Archivo | Qué cubre |
| --- | --- |
| `ActivationsTest` | Activación en línea con el protocolo del contrato: la misma computadora que vuelve a activar recibe su revisión vigente, otra recibe `ACTIVATION_LIMIT` con `transfer_available`, desactivación con su revisión de cierre, activaciones terminadas, `REQUEST_CONFLICT`, identidad solo en minúsculas, rechazo del protocolo anterior, idempotencia, códigos viejos o mal formados, intentos limitados, desafíos (vencimiento exacto, un solo uso, acción ligada), contenido firmado exacto de cada tipo de licencia (campo por campo y en orden), primer periodo calculado al activar en la zona del cliente, refresco, desactivación y revocación con su motivo de cierre, contador de revisiones global por licencia, contenido inválido que no se firma ni deja cambios, una instalación activa, rotación de llaves, y fallos de firma o auditoría con reversión completa |
| `AdministrationTest` | Esquema inicial (`adel_pos` sin módulos), creación única del primer administrador, emisión y conflictos, emisión de los tres tipos con sus condiciones y los cupos de su plan, licencia personalizada, validaciones de la emisión, zona horaria del cliente, verificación en dos pasos y recuperación, rechazo de renovación y mantenimiento, dependencias inválidas, permisos de consulta, último superadministrador, historia no editable, unicidad de credenciales y obligatoriedad de su copia protegida, búsqueda sin inyección y concurrencia entre dos procesos |
| `BackupTest` | Respaldo cifrado por CLI con `mysqldump` 8.0, extracción y restauración solo en base vacía, conservando historia, llaves, evidencia, constancias y el entorno de licencias |
| `ContractDataModelTest` | Reglas de la base para el modelo del contrato, probadas con escrituras directas: condiciones según el tipo de licencia, cupos de 0 a 999, plan existente, periodo completo o vacío y coherente con el tipo, `server_id`, motivo y entorno de firma de las revisiones, permisos de la cuenta de aplicación sobre planes, activaciones y revisiones, y que el primer periodo calculado por el motor de fechas cumple esas reglas en cada tipo de licencia |
| `PaymentsTest` | Precios opcionales y no negativos, monto de alta sugerido y modificable con motivo, fecha de pago en la zona del cliente, pagos de solo inserción y sus reglas en la base, idempotencia y auditoría |
| `PlanCatalogTest` | Editor de planes: crear, editar, archivar, permisos, propagación solo de aumentos y solo cuando se elige, exclusiones, reintento, fallo a la mitad y nombre firmado |
| `RenewalTest` | Renovación con la licencia activada: ejemplos del contrato, cupos y duración, pago, cadena de día ancla, zona del cliente, rechazos y dos operadores a la vez |
| `ConversionTest` | Conversión de una prueba a cada tipo, activada y sin activar, antes y después de vencer, con cambio de plan y rechazos |
| `PlanChangeTest` | Cambio de plan con bonificación exacta (también con una renovación anticipada, sobre un periodo pagado con bonificación y la combinación de los dos), mixto con reducción programada, reducción pura, pago vencido como renovación, perpetua que solo sube, prueba sin cobro, personalizada y concurrencia |
| `MaintenanceTest` | Mantenimiento antes y después de que terminen las actualizaciones, encadenado, rechazos, y edición del periodo inicial |
| `ScheduledChangesTest` | Tarea programada: antes, en y después del instante, dos ejecuciones a la vez, renovación antes y después del instante, licencia vencida, cambio cancelado y el comando real |
| `CommercialDeliveryTest` | Constancias PDF con su diseño final (etapa 5): de licenciamiento y de entrega para cada tipo de licencia y forma de activación, en una página, sin montos, pagos ni referencia comercial, con la revisión firmada y las fechas en la zona del titular; la verificación pública con el estado actual de la licencia; modalidad, plan, cupos y fechas del periodo antes y después de activar; idempotencia, historial de revisiones, códigos recuperables solo con permiso y verificación en dos pasos, archivo firmado inmutable, y formato de código desconocido no recuperable |
| `HttpApiTest` | Ciclo en línea por HTTP real sin sesiones del panel, con **cada respuesta validada contra `openapi.yaml`** (cuerpo, campos obligatorios y petición aceptada) y cada licencia contra `license.schema.json`; validación, TLS y límites con el formato de error del contrato; respuesta 503, sin detalles, cuando la llave activa es de otro entorno de licencias o falta `LICENSE_ENVIRONMENT` |
| `InitialSchemaTest` | Equivalencia de `001_esquema_inicial.sql`, sola, con la cadena original 001-007, leída del primer commit. Solo difieren los dos endurecimientos registrados. También: base vacía con todas las migraciones y el catálogo de planes, repetición sin efecto, entornos de firma `test` y `production` en llaves y ámbitos, migrador (pendientes, modificadas e incompletas) y permisos por tabla |
| `SigningEnvironmentsTest` | Entornos de firma del contrato: registro, archivo y carpeta por entorno; identificador sugerido y etiqueta del entorno; el servidor firma solo con la llave activa de su entorno, aunque se cambie la etiqueta en el registro o se copie el archivo; arranque y `bin/preflight.php` detenidos si la llave activa o la carpeta son de otro entorno; semillas públicas rechazadas; exportación de llaves públicas sin nada privado; y entorno visible en cada operación de `bin/signing-key.php` |
| `OfflineTest` | Flujo sin internet con el formato del contrato: importación cifrada, bytes alterados, aprobación idempotente, rechazo, `renew` que entrega la revisión vigente sin firmar otra (registrar una renovación al aprobar se rechaza), primer periodo al aprobar, la misma computadora que vuelve a activar, activación terminada y licencia revocada que reciben su cierre, formato anterior rechazado, desactivación firmada, transferencia forzada atómica, evidencia alterada, roles, espacio de `request_id` compartido, recuperación por equipo averiado y concurrencia |
| `ProductionHttpTest` | Pila real Nginx + PHP-FPM + TLS: cookies `__Host-`, encabezados de seguridad, marca AD-EL POS (logos servidos con su SHA-256), control de acceso, permisos de los controladores, privacidad de la verificación pública, página de mantenimiento sin secretos y formularios rechazados que vuelven a su pantalla con lo escrito (401 y 422) o sin él (409) |
| `NginxConfigTest` | `deploy/nginx.conf.example` en la pila real (etapa 5): el 429 de la API con el JSON exacto del contrato y sin llegar a la aplicación, el 413, el límite del inicio de sesión solo para los envíos, 404 en los ocultos y en cualquier `.php`, la redirección de HTTP a HTTPS y Certbot, y la convivencia con AIDEX: nombres propios en el contexto `http`, compatibles con 1.24.0 |
| `DeploymentCheckTest` | `bin/verify-deployment.php` en la pila real, con la disposición de la guía y un systemd de prueba: una instalación sana da OK sin cambiar ninguna tabla; cada problema de permisos, de la cuenta de MySQL, de las migraciones, de la llave, de la tarea diaria, del certificado y de un sitio mal configurado da su Error o Advertencia con qué hacer; y el comando completo con su resumen y su código de salida |
| `PanelQueriesTest` | Consultas del panel definitivo: conteos por estado, «por vencer» en días de calendario de la zona de cada cliente, vencidas recientes, cobros por mes, búsqueda, filtros, orden y páginas de las licencias, clientes, y el impacto de propagar un plan |
| `TrustedDevicesHttpTest` | Dispositivos de confianza sobre HTTPS real: autorización, cierre de sesión, revocación, CSRF y origen ajeno |
| `TransferModelTest` | Reglas de la base para las transferencias y las alertas (migración `008`), con escrituras directas: una sola transferencia de autoservicio pendiente por licencia, vías, estados, confirmación y liberación manual, una alerta abierta por tipo y licencia, desafío `transfer` y permisos de la cuenta de aplicación |
| `SelfServiceTransferTest` | Transferencia por autoservicio de cada tipo de licencia (vigente, en tolerancia y vencida) con las fechas idénticas al microsegundo, el cierre de la anterior, lo que no es transferencia (misma instalación, sin activación vigente, revocada, código desconocido), `TRANSFER_LIMIT` con una sola alerta, `transfer_available`, reintentos, conflictos, desafío de otra acción, límite por código, y dos carreras: dos transferencias a la vez y una transferencia contra la desactivación de la anterior |
| `TransferConfirmationTest` | Confirmación por `refresh`, `deactivate` y las dos solicitudes sin internet; lo que no confirma (la nueva, una llave ajena) y la idempotencia; liberación manual con rol, motivo y nueva autenticación; las del propietario no consumen el autoservicio; y la carrera de una confirmación contra un intento de transferencia |
| `AlertsTest` | Alertas: cada tipo, una abierta con su contador, otra después de revisar, el límite exacto de 12 meses en la zona del cliente, las transferencias del propietario, permisos y auditoría de la revisión, y el número sin revisar en la barra superior |
| `TrustedDevicesTest` | Reglas de confianza: verificación en dos pasos, vencimiento a 30 días sin extensión, ligada a la cuenta, revocación, cambio de contraseña, cuenta deshabilitada, fallas de consulta y nueva autenticación |

Los procesos auxiliares `tests/activation-worker.php` (con `tests/ApiRace.php` para activar, transferir, consultar y desactivar a la vez), `tests/offline-worker.php`, `tests/race-worker.php` y `tests/commerce-worker.php` (este último con `tests/Concurrency.php`) prueban la concurrencia real. `tests/TransferScenario.php` reúne el escenario común de las pruebas de transferencia. Leen `performance_schema.data_locks` para comprobar que dos procesos esperan el mismo bloqueo, en lugar de confiar en pausas.

## 6. Pruebas del panel (Playwright)

| Script | Qué recorre |
| --- | --- |
| `tests/screens-panel.cjs` | El panel definitivo (etapa 5) en escritorio y en teléfono: el resumen con sus conteos, «Por vencer» y los cobros; búsqueda, filtros, orden y limpiar en las licencias; cada sección del detalle con su dirección; la lista y el detalle de los clientes; un formulario rechazado que vuelve con lo escrito; etiquetas en todos los campos, navegación con teclado, sin desbordamiento en el teléfono y tablas como tarjetas |
| `tests/panel.cjs` | Acceso, verificación en dos pasos, roles, CSRF y origen, zona horaria del cliente, emisión de los tres tipos de licencia con su plan, cupos que llena el plan y aviso de licencia personalizada, validación de cupos, detalle de la licencia antes y después de activar (fechas en la zona del cliente y motivo de cierre de cada revisión), formato de error de la API, rechazo de renovación y mantenimiento, navegación en móvil y la marca: título «AD-EL POS — Licencias», logo visible y ninguna imagen rota |
| `tests/commercial-panel.cjs` | Ciclo comercial en el panel: renovar con vista previa, subir de plan con la bonificación visible, programar y cancelar una reducción, convertir una prueba, comprar mantenimiento y editar el catálogo propagando un aumento |
| `tests/transfers-panel.cjs` | Transferencias y alertas (etapa 4): AD-EL POS simulado por la API (`tests/panel-api.php`) transfiere por autoservicio y un segundo intento queda bloqueado; la alerta en la barra superior, en su menú y en el resumen; el detalle con la transferencia pendiente; marcar como revisada; liberar el contador con nueva autenticación; y la alerta de transferencias frecuentes en «Alertas», con su filtro |
| `tests/offline-panel.cjs` | Flujo sin internet en el panel con el formato del contrato: importar, revisar, aprobar, rechazar, descargar `.lic`, transferencia forzada con nueva autenticación (la computadora anterior queda «Transferida»), desactivación firmada con su motivo y solicitud `renew` que entrega el mismo archivo vigente |
| `tests/trusted-devices-panel.cjs` | Dispositivos de confianza en escritorio y móvil sobre HTTPS real |

## 7. Qué queda por probar después del despliegue

| Cuándo | Qué |
| --- | --- |
| Al desplegar en `licensing.sicrat.com` | `bin/verify-deployment.php` en el VPS real (PHP 8.3.6, MySQL 8.0.46, Nginx 1.24.0), junto al sitio de AIDEX; la restauración de prueba del paso 12.3 de la guía |
| En la integración de AD-EL POS | Las 14 pruebas de [docs/INTEGRACION_ADEL_POS.md](docs/INTEGRACION_ADEL_POS.md), sección 7, contra `licensing.sicrat.com`, y los casos de `contracts/v1/fixtures/` en el servidor Go del restaurante |
