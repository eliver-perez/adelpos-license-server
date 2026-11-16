# Modelo de datos

Esquema de MySQL 8 (InnoDB) del servidor de licencias de AD-EL POS. La fuente física son las migraciones de `database/migrations/`, que se aplican en orden: [`001_esquema_inicial.sql`](database/migrations/001_esquema_inicial.sql), el esquema recibido, que no se modifica, y [`002_entornos_de_firma.sql`](database/migrations/002_entornos_de_firma.sql), que deja los entornos de firma del contrato. MariaDB no es un motor válido; el migrador y `bin/preflight.php` la rechazan.

Las partes marcadas como **Herencia temporal** provienen del motor de licencias heredado. Siguen vigentes, pero se reemplazan en las etapas 2 a 4 según el [contrato de licencias](docs/CONTRATO_LICENCIAS_ADEL_POS.md). En el código están señaladas con `HEREDADO:`; la lista completa está en [AVANCE.md](AVANCE.md), sección 4.2.

## 1. Convenciones

- **Identificadores:** UUID guardados como `BINARY(16)`; se convierten de y a texto en el código. `product_id`, `kid` y demás identificadores técnicos usan `ascii_bin`, que distingue mayúsculas.
- **Fechas:** `DATETIME(6)` en UTC. La conexión fija `time_zone='+00:00'` y aislamiento `READ COMMITTED`, con excepciones de PDO y consultas preparadas nativas (`src/Infrastructure/Database.php`).
- **Estados:** `VARCHAR` con restricciones `CHECK` de valores cerrados.
- **Historia:** las llaves foráneas no borran en cascada licencias, activaciones, revisiones ni auditoría. La única cascada es la de `admin_trusted_devices` hacia `admin_users`.
- **JSON:** los textos firmados o encadenados (`payload_json`, `audit_events.payload_json`, `projection_json`) son `LONGTEXT` con `CHECK (JSON_VALID(...))`, para conservar sus bytes exactos.

## 2. Tablas

El esquema tiene **33 tablas**, más `schema_migrations`, que crea el migrador.

### Administración y acceso

| Tabla | Propósito | Restricciones clave | Escribe |
| --- | --- | --- | --- |
| `security_state` | Fila única que sirve de cerrojo para el alta inicial y el último superadministrador | Una sola fila (`id=1`) | Bloqueo `FOR UPDATE` |
| `admin_users` | Administradores: acceso, rol, estado, secreto TOTP cifrado y último paso TOTP aceptado | `login` único; `role` en `superadmin`, `operator`, `viewer`; `state` en `active`, `disabled` | `AuthService`, `AdminService`, `bin/recover-admin.php` |
| `admin_sessions` | Sesiones del panel; solo se guarda el hash SHA-256 del token | `stage` en `anonymous`, `pending`, `authenticated`; una sesión autenticada exige administrador | `Sessions`, `AuthService`, `TrustedDevices`, `bin/cleanup.php` |
| `admin_recovery_codes` | Códigos de recuperación de un solo uso (HMAC) | Único por administrador y código | `AuthService`, `bin/recover-admin.php` |
| `admin_trusted_devices` | Autorizaciones de «dispositivo de confianza» (hash del token, vínculo con las credenciales) | `token_hash` único; vencimiento posterior a la creación | `TrustedDevices` |
| `admin_permission_grants` | Permisos adicionales de un operador | PK administrador y permiso | `AdminService` |
| `rate_limit_buckets` | Contadores de intentos por ámbito, identidad (HMAC) y ventana | PK ámbito, identidad y ventana | `RateLimiter`, `bin/cleanup.php` |
| `admin_operations` | Idempotencia de formularios del panel: un `operation_id` ejecuta su efecto una sola vez | PK `operation_id`; digest del contenido | `Operations` |

### Auditoría

| Tabla | Propósito | Restricciones clave | Escribe |
| --- | --- | --- | --- |
| `audit_heads` | Cabeza de la cadena de auditoría; se bloquea al final de cada transacción | Fila inicial `admin` con 64 ceros | `Audit` |
| `audit_events` | Eventos encadenados con HMAC (`AUDIT_KEY`) | Secuencia única; JSON válido; la cuenta de la aplicación solo puede leer e insertar | `Audit` |

### Clientes y catálogo

| Tabla | Propósito | Restricciones clave | Escribe |
| --- | --- | --- | --- |
| `customers` | Clientes (nombre, razón social, RFC opcional en `tax_id`) | `state` en `active`, `archived`; `row_version` para formularios obsoletos | `CatalogService` |
| `customer_contacts` | Contacto principal | Uno por cliente | `CatalogService` |
| `products` | Productos que se licencian | `state` en `active`, `archived` | `CatalogService` |
| `product_capabilities` | Módulos de cada producto | PK producto y clave | `CatalogService` |
| `capability_dependencies` | Dependencias entre módulos del mismo producto | Llaves foráneas al mismo catálogo; un módulo no puede requerirse a sí mismo. El servicio impide los ciclos. | `CatalogService` |

### Licencias comerciales

| Tabla | Propósito | Restricciones clave | Escribe |
| --- | --- | --- | --- |
| `licenses` | Derechos comerciales: cliente, producto, tipo, vencimiento, tolerancia, mantenimiento, versiones autorizadas y contador de revisiones firmadas | `chk_license_status`; `chk_license_terms` (ver herencia); `(license_id, product_id)` único para las llaves compuestas | `LicenseService`, `OfflineService`, `RevisionPublisher` |
| `license_features` | Módulos habilitados por licencia | Llaves compuestas: el módulo pertenece al producto de la licencia | `LicenseService` |
| `license_limits` | Límites del contenido heredado de la licencia | Ver herencia | `LicenseService` |
| `license_credentials` | Códigos comerciales 3-4-4-4: HMAC para buscar y copia cifrada para consulta autorizada | Una credencial activa por licencia (columna generada `active_license_id` con índice único); `(digest_key_version, key_digest)` único; `credential_encrypted` y `code_format` **NOT NULL** | `LicenseService` |
| `license_changes` | Historial comercial con instantánea JSON | Versión única por licencia; solo inserción | `LicenseService` |
| `license_administrative_changes` | Cambios de referencia y nota administrativa | Único por licencia, versión y campo; solo inserción | `LicenseService` |
| `subscription_periods` | Renovaciones de suscripciones | Solo inserción | `LicenseService` |
| `maintenance_purchases` | Compras de mantenimiento de perpetuas | Solo inserción | `LicenseService` |
| `license_documents` | Constancias PDF: folio, digest de verificación, datos públicos, instantánea y PDF cifrados, SHA-256 del PDF | Folio y digest únicos; `document_type` en `licensing`, `delivery`; solo inserción | `DocumentService` |

### Firma

| Tabla | Propósito | Restricciones clave | Escribe |
| --- | --- | --- | --- |
| `signing_keys` | Llaves públicas Ed25519 con `kid`, entorno, propósito, estado y referencia opaca al archivo privado. **Ninguna llave privada en la base.** | `environment` en `test`, `production` (entorno de firma del contrato, no `APP_ENV`); `purpose` en `license`, `manifest`; `state` en `staged`, `signing`, `verify_only` | `SigningKeys` (por CLI) |
| `signing_scopes` | Llave activa por entorno y propósito | `environment` en `test`, `production`. Llave foránea compuesta: no se cruzan entornos ni propósitos | `SigningKeys` (por CLI) |

### Activación en línea

| Tabla | Propósito | Restricciones clave | Escribe |
| --- | --- | --- | --- |
| `activations` | Vínculo de una licencia con una instalación: llave pública, huella, estado y revisión vigente | Una activación activa por licencia (columna generada `active_license_id` con índice único); `chk_activation_end`; `fk_current_revision` apunta a una revisión de la misma activación | `ActivationService`, `OfflineService`, `RevisionPublisher` |
| `license_revisions` | Revisiones firmadas: contador global por licencia, `kid`, contenido y JWS exactos | `(license_id, license_revision)` único; revisión mayor que cero; solo inserción | `RevisionPublisher` |
| `activation_challenges` | Desafíos de un solo uso con vencimiento | La identidad de la acción es coherente; consumo atómico. Sin llave foránea a activaciones, para no revelar si existen. | `ActivationService`, `bin/cleanup.php` |
| `license_requests` | Idempotencia de la API y de las solicitudes sin internet: digest HMAC, identidad, respuesta exacta | PK `(product_id, request_id)`; la respuesta queda completa o vacía | `ActivationService`, `OfflineService` |

### Sin internet

| Tabla | Propósito | Restricciones clave | Escribe |
| --- | --- | --- | --- |
| `offline_requests` | Archivo `.licreq` original, cifrado, y su proyección consultable | Único por `(product_id, request_id)` y ligado a `license_requests`; `encryption_version=1`; solo inserción | `OfflineService` |
| `offline_decisions` | Decisión única por solicitud | Aprobada exige licencia y revisión; rechazada no las admite; solo inserción | `OfflineService` |
| `license_transfers` | Transferencias autorizadas por el panel | Origen único; origen y destino de la misma licencia y distintos; aceptación obligatoria del límite sin internet; solo inserción | `OfflineService` |

## 3. Datos iniciales

La migración inicial solo inserta:

- `security_state`: la fila `1`.
- `audit_heads`: la cabeza `admin` con secuencia 0 y hash de 64 ceros.
- `products`: **`adel_pos`** («AD-EL POS», «Punto de venta para restaurantes»), **sin módulos**. Coincide con `features: {}` del contrato. Los planes, límites y reglas de AD-EL POS no existen todavía (etapa 3).

No hay administradores, llaves de firma ni licencias. El primer superadministrador se crea por CLI (ver [OPERATIONS.md](OPERATIONS.md)). Las pruebas no usan `adel_pos`: `tests/MySqlFixture.php` crea un producto sintético, `producto_prueba`, con cinco módulos y una dependencia.

## 4. Endurecimiento registrado

`license_credentials.credential_encrypted` y `code_format` son `NOT NULL`. No existen credenciales anteriores al formato 3-4-4-4, así que toda credencial debe tener su copia cifrada. Es la única diferencia con el esquema heredado que reproduce esta migración (ver [DECISIONS.md](DECISIONS.md)).

## 5. Permisos de la cuenta de aplicación

`bin/grants.php --database=<base> --user=<cuenta>` imprime los `GRANT` de la cuenta del panel y la API (por omisión `adelpos_license` y `adelpos_license_app`). Las migraciones y la gestión de llaves usan cuentas distintas.

| Permiso | Tablas |
| --- | --- |
| Solo `SELECT` | `schema_migrations`, `signing_keys`, `signing_scopes` |
| `SELECT, INSERT` | `audit_events`, `license_changes`, `subscription_periods`, `maintenance_purchases`, `license_revisions`, `offline_requests`, `offline_decisions`, `license_transfers`, `license_administrative_changes`, `license_documents` |
| `SELECT, UPDATE` | `security_state`, `audit_heads` |
| `SELECT, INSERT, UPDATE` | `admin_users`, `admin_recovery_codes`, `admin_trusted_devices`, `admin_operations`, `customers`, `customer_contacts`, `products`, `product_capabilities`, `licenses`, `license_features`, `license_limits`, `license_credentials`, `license_requests` |
| `SELECT, INSERT, DELETE` | `capability_dependencies`, `admin_permission_grants` |
| `SELECT, INSERT, UPDATE, DELETE` | `admin_sessions`, `rate_limit_buckets`, `activation_challenges` |
| `SELECT, INSERT` y `UPDATE` solo de `state`, `ended_at`, `current_revision_id` | `activations` |

- En producción, `bin/preflight.php` comprueba que la cuenta **no** pueda modificar ni borrar las tablas de historia.
- `tests/Integration/InitialSchemaTest.php` comprueba que los permisos cubren exactamente las tablas del esquema.

## 6. Transacciones e idempotencia

- **Mutex:** la fila de la licencia (`SELECT … FOR UPDATE`). Las activaciones, cambios comerciales, aprobaciones sin internet y transferencias de una licencia se serializan sobre ella.
- **Defensa final:** el índice único de `activations.active_license_id`, que impide una segunda activación activa incluso ante un error de código.
- **Reintentos:** en la API, `license_requests` reserva cada `request_id`. Un reintento idéntico devuelve la respuesta archivada; otro contenido con el mismo identificador se rechaza. En el panel, `admin_operations` hace lo mismo con `operation_id`.
- **Auditoría:** la cabeza se bloquea al final. El evento se confirma con el cambio, o se revierte con él.
- **Intentos de acceso:** los contadores se confirman fuera de la transacción de negocio, para que un rollback no los borre.

## 7. Migrador

`src/Infrastructure/Migrator.php`, usado por `bin/migrate.php` y por las pruebas:

- Aplica los archivos `database/migrations/*.sql` en orden de nombre y registra cada uno en `schema_migrations` con su SHA-256 y estado (`pending`, `complete`).
- Rechaza un archivo ya aplicado cuyo contenido cambió y una migración que quedó incompleta.
- Usa un bloqueo de MySQL `adelpos-license-migrations-` más 32 hexadecimales del hash de la base. MySQL limita los nombres de bloqueo a 64 caracteres.
- Exige MySQL 8 y rechaza MariaDB.
- Separa sentencias por `;` al final de línea; los comentarios del archivo no deben terminar en `;`.
- `002_entornos_de_firma.sql` se detiene si la base tiene llaves de los entornos anteriores (`development` o `testing`): esas llaves no se pueden conservar.
- Las DDL de MySQL confirman implícitamente: una migración fallida no se revierte sola. Se despliega con respaldo previo y `bin/preflight.php`, que verifica que todas las migraciones estén aplicadas con su checksum.

## 8. Prueba de equivalencia

`tests/Integration/InitialSchemaTest.php` aplica, en bases temporales:

1. La cadena heredada de siete migraciones. Se lee del primer commit del repositorio, identificado por el SHA-256 de su `composer.lock`.
2. `001_esquema_inicial.sql`, sola, sin las migraciones posteriores.

Compara tablas, columnas (posición, tipo, nulabilidad, valor por omisión, juego de caracteres, columnas generadas), índices, llaves foráneas y restricciones `CHECK`. **Las únicas diferencias admitidas son las dos columnas endurecidas.** La misma prueba comprueba:

- la base vacía con solo `adel_pos`, después de aplicar todas las migraciones;
- que repetir la migración no hace nada;
- que las llaves y sus ámbitos solo admiten los entornos `test` y `production`, y que no se cruzan;
- el rechazo de migraciones modificadas o incompletas;
- los permisos por tabla.

## 9. Herencia temporal

| Elemento del esquema | Regla heredada | Se reemplaza en |
| --- | --- | --- |
| `license_limits` (`max_installations`, `max_users`, `max_libraries`, `max_documents`) y `chk_limit_installations` (`max_installations=1`) | Contenido de licencia anterior: una instalación y límites que no aplican a AD-EL POS | Etapa 2 (contenido del contrato: `limits` de cajas, comanderos y KDS) y etapa 3 (planes) |
| `licenses.license_type` y `chk_license_terms` | Solo `perpetual` y `subscription`; suscripción con `grace_days=15` | Etapa 3 (prueba, periodos de calendario, tolerancia de 5 días) |
| `licenses.maintenance_until`, `entitled_release_until`, `maintenance_purchases` | Mantenimiento y versiones autorizadas | Etapa 3 (`updates_until` del contrato) |
| `activations.installation_id`, `fingerprint_version`, `fingerprint_hash` | Identidad con huella del equipo, versión `'1'` | Etapa 2 (`server_id` y llave de licencias de la instalación; la huella queda fuera de V1) |
| `activation_challenges.action` (`activate`, `refresh`, `deactivate`) | Sin `transfer` | Etapas 2 y 4 (transferencia por autoservicio) |
| `product_capabilities`, `capability_dependencies`, `license_features` | Módulos genéricos | Se conservan como `features` del contrato (vacío en V1); su uso comercial se define en la etapa 3 |
