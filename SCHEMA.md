# Modelo de datos

Esquema de MySQL 8 (InnoDB) del servidor de licencias de AD-EL POS. La fuente física son las migraciones de `database/migrations/`, que se aplican en orden: [`001_esquema_inicial.sql`](database/migrations/001_esquema_inicial.sql), el esquema recibido (reformateado en la etapa 3 sin cambiar el esquema que produce), [`002_entornos_de_firma.sql`](database/migrations/002_entornos_de_firma.sql), que deja los entornos de firma del contrato, [`003_contrato_v1.sql`](database/migrations/003_contrato_v1.sql), con el modelo de datos del contrato: zona horaria del cliente, planes, tipos de licencia, cupos, periodo vigente, `server_id` y datos de las revisiones, y [`004_retiro_de_lo_heredado.sql`](database/migrations/004_retiro_de_lo_heredado.sql), que retira la huella del equipo, `license_limits` y las fechas de mantenimiento anteriores, y hace obligatorio el motivo de cierre, y [`005_precios_y_pagos.sql`](database/migrations/005_precios_y_pagos.sql), con los precios opcionales del catálogo y el registro de pagos de solo inserción, que reemplaza a `subscription_periods` y `maintenance_purchases`, [`006_editor_de_planes.sql`](database/migrations/006_editor_de_planes.sql), con el estado y la versión de cada plan, y [`007_cambios_programados.sql`](database/migrations/007_cambios_programados.sql), con los cambios de plan que entran en vigor al vencer lo pagado. MariaDB no es un motor válido; el migrador y `bin/preflight.php` la rechazan.

Al cerrar la etapa 3, el esquema guarda el modelo del [contrato de licencias](docs/CONTRATO_LICENCIAS_ADEL_POS.md) V1.0 y el ciclo comercial: precios, pagos y cambios programados. La sección 9 enumera lo que cambia en las etapas siguientes.

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
| `customers` | Clientes (nombre, razón social, RFC opcional en `tax_id`) y su zona horaria IANA en `time_zone`, con la que se calculan las fechas de sus licencias | `state` en `active`, `archived`; `row_version` para formularios obsoletos; `time_zone` no vacía, `America/Monterrey` por omisión (el servicio la valida contra la lista de zonas de PHP) | `CatalogService` |
| `customer_contacts` | Contacto principal | Uno por cliente | `CatalogService` |
| `products` | Productos que se licencian | `state` en `active`, `archived` | `CatalogService` |
| `plans` | Catálogo de planes del contrato (sección 11): nombre, cupos de cajas, comanderos y KDS, y precios opcionales en MXN: mensual, trimestral, semestral, anual, perpetua y mantenimiento anual | Cupos de 0 a 999 (`chk_plan_limits`); nombre no vacío; precios vacíos o no negativos, `DECIMAL(10,2)` (`chk_plan_prices`); `state` en `active`, `archived` (`chk_plan_state`); identificador con el formato que se firma, `^[a-z][a-z0-9_]{1,63}$` (`chk_plan_identifier`); `row_version` para formularios obsoletos. Un plan usado por una licencia o un pago no se puede borrar; la aplicación tampoco tiene permiso de borrar | `PlanService`, solo el superadministrador |
| `product_capabilities` | Módulos de cada producto | PK producto y clave | `CatalogService` |
| `capability_dependencies` | Dependencias entre módulos del mismo producto | Llaves foráneas al mismo catálogo; un módulo no puede requerirse a sí mismo. El servicio impide los ciclos. | `CatalogService` |

### Licencias comerciales

| Tabla | Propósito | Restricciones clave | Escribe |
| --- | --- | --- | --- |
| `licenses` | Derechos comerciales: cliente, producto, tipo y sus condiciones, copia del plan, cupos, periodo vigente y contador de revisiones firmadas. Ver «Condiciones de una licencia» | `chk_license_status`, `chk_license_terms`, `chk_license_plan`, `chk_license_limits`, `chk_license_period`; llave foránea al plan; `(license_id, product_id)` único para las llaves compuestas | `LicenseService`, `OfflineService`, `RevisionPublisher` |
| `license_features` | Módulos habilitados por licencia | Llaves compuestas: el módulo pertenece al producto de la licencia | `LicenseService` |
| `license_credentials` | Códigos comerciales 3-4-4-4: HMAC para buscar y copia cifrada para consulta autorizada | Una credencial activa por licencia (columna generada `active_license_id` con índice único); `(digest_key_version, key_digest)` único; `credential_encrypted` y `code_format` **NOT NULL** | `LicenseService` |
| `license_changes` | Historial comercial con instantánea JSON | Versión única por licencia; solo inserción | `LicenseService` |
| `license_administrative_changes` | Cambios de referencia y nota administrativa | Único por licencia, versión y campo; solo inserción | `LicenseService` |
| `license_payments` | Historial comercial: cada pago de una licencia (alta, renovación, conversión, cambio de plan o mantenimiento), con fecha de pago, monto cobrado en MXN, precio de catálogo sugerido, bonificación, motivo si el monto se cambió, referencia, plazo y periodo que cubre, administrador y fecha de registro. Ver «Pagos» | Solo inserción. Una operación del panel registra a lo sumo un pago (`operation_id` único y llave foránea a `admin_operations`). `chk_payment_concept`, `chk_payment_term`, `chk_payment_trial`, `chk_payment_currency`, `chk_payment_amounts`, `chk_payment_suggestion`, `chk_payment_override`, `chk_payment_covers` | `Payments`, desde las operaciones comerciales |
| `license_scheduled_changes` | Cambios de plan programados: instante en que entran en vigor, plan y cupos de destino, quién los programó y cómo terminaron (`applied`, `cancelled` o `replaced`) | Un solo pendiente por licencia (columna generada `pending_license_id` con índice único). `chk_scheduled_resolution`: un pendiente no tiene resolución; uno cancelado o sustituido dice quién lo resolvió. Cupos de 0 a 999. La aplicación solo actualiza el estado, la resolución y los cupos (que suben si el catálogo propaga un aumento del plan de destino) | `ScheduledChanges`, `CommercialService`, `PlanService`, `bin/apply-scheduled-changes.php` |
| `license_documents` | Constancias PDF: folio, digest de verificación, datos públicos, instantánea y PDF cifrados, SHA-256 del PDF | Folio y digest únicos; `document_type` en `licensing`, `delivery`; solo inserción | `DocumentService` |

#### Condiciones de una licencia

| Columna | Contenido | Regla en la base |
| --- | --- | --- |
| `license_type` | `perpetual`, `subscription` o `trial` | `chk_license_terms`: cada tipo con sus condiciones, y ningún otro valor |
| `billing_period` | `monthly`, `quarterly`, `semiannual` o `annual` | Solo en suscripción, y obligatorio en ella |
| `trial_duration` | `15d`, `1m` o `3m` | Solo en prueba, y obligatorio en ella |
| `updates_months` | `0`, `6` o `12` | Solo en perpetua, y obligatorio en ella. El servicio usa 6 si no se indica |
| `grace_days` | Días de tolerancia | 5 en suscripción; 0 en prueba y perpetua |
| `plan_id`, `plan_name`, `plan_custom` | Copia del plan al emitir. `plan_custom` vale 1 si algún cupo se cambió | Llave foránea a `plans`; nombre no vacío |
| `cash_registers`, `order_takers`, `kitchen_displays` | Cupos que se firman | De 0 a 999 (`chk_license_limits`) |
| `period_payment_id` | El pago que cubre el periodo vigente. Es el último eslabón de la cadena de periodos pagados con la que se calcula la bonificación de un cambio de plan (`PaidPeriods`): su valor es lo cobrado más la bonificación aplicada | Llave foránea compuesta a `license_payments(payment_id, license_id)`: el pago es de la misma licencia. Vacío en una licencia emitida antes de la etapa 3 |
| `period_starts_at`, `expires_at`, `anchor_day`, `anchor_local_time`, `updates_until` | Periodo vigente. **Vacío hasta que la licencia se activa:** lo fija `LicenseActivator` en la primera activación, en línea o al aprobar una solicitud sin internet, y una activación posterior en otra computadora no lo cambia. El día y la hora ancla son los del inicio, en la zona horaria del cliente, con microsegundos | `chk_license_period`: todo vacío o todo completo. En perpetua no hay vencimiento y las actualizaciones no terminan antes del inicio. En suscripción y prueba el vencimiento es posterior al inicio y las actualizaciones terminan con él |

Las tres restricciones de condiciones comparan primero con `IS NULL` o `IS NOT NULL`: en MySQL una restricción `CHECK` da por válido un resultado nulo.

#### Pagos

| Columna | Contenido | Regla en la base |
| --- | --- | --- |
| `concept` | `issue` (alta), `renewal`, `conversion`, `plan_change` o `maintenance` | Valores cerrados |
| `term` | Plazo que se paga: `monthly`, `quarterly`, `semiannual`, `annual`, `perpetual`, `maintenance` (12 meses de actualizaciones) o `trial` | `maintenance` solo con ese concepto; `trial` solo en un alta y con monto 0 |
| `paid_at` | Instante UTC del pago, capturado en la zona horaria del cliente | No puede ser futuro ni anterior al último pago de la licencia (lo comprueba `Payments`) |
| `currency`, `amount` | Monto cobrado, tal como se cobró, sin IVA | `MXN`; no negativo |
| `catalog_amount`, `credit_amount`, `suggested_amount` | Precio de catálogo sugerido (vacío si el plan no tiene precio o la licencia es personalizada), bonificación descontada y monto sugerido | Lo sugerido es el catálogo menos la bonificación, nunca menos de 0 |
| `override_reason` | Motivo si el monto cobrado no es el sugerido | Obligatorio si el monto es distinto del sugerido, y prohibido si no lo es o no hay sugerencia |
| `covers_from`, `covers_until` | Periodo que cubre el pago | Los dos o ninguno, y el final después del inicio. Vacío si las fechas se fijan al activar o si el pago no cambia el periodo |

### Firma

| Tabla | Propósito | Restricciones clave | Escribe |
| --- | --- | --- | --- |
| `signing_keys` | Llaves públicas Ed25519 con `kid`, entorno, propósito, estado y referencia opaca al archivo privado. **Ninguna llave privada en la base.** | `environment` en `test`, `production` (entorno de firma del contrato, no `APP_ENV`); `purpose` en `license`, `manifest`; `state` en `staged`, `signing`, `verify_only` | `SigningKeys` (por CLI) |
| `signing_scopes` | Llave activa por entorno y propósito | `environment` en `test`, `production`. Llave foránea compuesta: no se cruzan entornos ni propósitos | `SigningKeys` (por CLI) |

### Activación en línea

| Tabla | Propósito | Restricciones clave | Escribe |
| --- | --- | --- | --- |
| `activations` | Vínculo de una licencia con una instalación: identidad `server_id`, llave pública, estado, revisión vigente y `app_version` de su última operación. | `state` en `active`, `deactivated`, `revoked`, `transferred`. Una activación activa por licencia (columna generada `active_license_id` con índice único); `chk_activation_end`; `fk_current_revision` apunta a una revisión de la misma activación | `ActivationService`, `OfflineService`, `RevisionPublisher` |
| `license_revisions` | Revisiones firmadas: contador global por licencia, `kid`, entorno de la llave que firmó (`environment`), contenido y JWS exactos, y `status_reason` en las revisiones de cierre | `(license_id, license_revision)` único; revisión mayor que cero; solo inserción. `(kid, environment)` es llave foránea a la llave de firma. `chk_revision_reason`: `status_reason` es obligatorio en las revisiones `revoked` (`transferred`, `deactivated` o `revoked`) y queda vacío en las `active` | `RevisionPublisher` |
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
- `products`: **`adel_pos`** («AD-EL POS», «Punto de venta para restaurantes»), **sin módulos**. Coincide con `features: {}` del contrato.

`003_contrato_v1.sql` agrega el catálogo de planes de la sección 11 del contrato:

| `plan_id` | Nombre | Cajas | Comanderos | KDS |
| --- | --- | --- | --- | --- |
| `basico` | Plan básico | 1 | 0 | 0 |
| `elemental` | Plan elemental | 3 | 3 | 3 |
| `premium` | Plan premium | 5 | 10 | 5 |

No hay administradores, llaves de firma ni licencias. El primer superadministrador se crea por CLI (ver [OPERATIONS.md](OPERATIONS.md)). El contrato fija el producto `adel_pos`, así que las pruebas lo usan: `tests/MySqlFixture.php` le agrega cinco módulos sintéticos (`prueba_a` a `prueba_e`) y una dependencia.

## 4. Endurecimiento registrado

`license_credentials.credential_encrypted` y `code_format` son `NOT NULL`. No existen credenciales anteriores al formato 3-4-4-4, así que toda credencial debe tener su copia cifrada. Es la única diferencia con el esquema heredado que reproduce esta migración (ver [DECISIONS.md](DECISIONS.md)).

## 5. Permisos de la cuenta de aplicación

`bin/grants.php --database=<base> --user=<cuenta>` imprime los `GRANT` de la cuenta del panel y la API (por omisión `adelpos_license` y `adelpos_license_app`). Las migraciones y la gestión de llaves usan cuentas distintas.

| Permiso | Tablas |
| --- | --- |
| Solo `SELECT` | `schema_migrations`, `signing_keys`, `signing_scopes` |
| `SELECT, INSERT` | `audit_events`, `license_changes`, `license_payments`, `license_revisions`, `offline_requests`, `offline_decisions`, `license_transfers`, `license_administrative_changes`, `license_documents` |
| `SELECT, UPDATE` | `security_state`, `audit_heads` |
| `SELECT, INSERT, UPDATE` | `admin_users`, `admin_recovery_codes`, `admin_trusted_devices`, `admin_operations`, `customers`, `customer_contacts`, `products`, `plans`, `product_capabilities`, `licenses`, `license_features`, `license_credentials`, `license_requests` |
| `SELECT, INSERT, DELETE` | `capability_dependencies`, `admin_permission_grants` |
| `SELECT, INSERT, UPDATE, DELETE` | `admin_sessions`, `rate_limit_buckets`, `activation_challenges` |
| `SELECT, INSERT` y `UPDATE` solo de `state`, `ended_at`, `current_revision_id`, `app_version` | `activations` |
| `SELECT, INSERT` y `UPDATE` solo de `state`, `resolved_at`, `resolved_by` y los tres cupos | `license_scheduled_changes` |

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

## 9. Pendiente para las etapas siguientes

| Elemento del esquema | Estado al cerrar la etapa 3 | Etapa |
| --- | --- | --- |
| `activation_challenges.action` (`activate`, `refresh`, `deactivate`) | Sin `transfer` | 4: transferencia por autoservicio |
| `license_transfers` | Registra las transferencias y retiros que hace el propietario desde el panel | 4: transferencias por autoservicio, su confirmación y las alertas |
| `product_capabilities`, `capability_dependencies`, `license_features` | Módulos del producto. Se firman como `features` del contrato, que en V1 va vacío para `adel_pos` | Su uso comercial se define más adelante |
| `signing_keys.purpose = 'manifest'` | Propósito recibido, sin uso | Fuera de V1 (contrato, sección 15) |
