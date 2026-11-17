# Arquitectura del servidor de licencias de AD-EL POS

Este documento describe el código tal como queda al cerrar la etapa 3. Todo lo que el servidor firma, recibe y devuelve sigue el contrato [docs/CONTRATO_LICENCIAS_ADEL_POS.md](docs/CONTRATO_LICENCIAS_ADEL_POS.md), cuya forma exacta está en [contracts/v1/](contracts/v1/README.md). Lo que queda para otras etapas está en la sección 12.

## 1. Límites del sistema

| Le corresponde | No le corresponde |
| --- | --- |
| Emitir, firmar, renovar, transferir, revocar y auditar licencias | Ejecutar o validar ventas del restaurante |
| Administrar clientes, productos y módulos | Contar sesiones o aplicar cupos: lo hace el servidor Go de AD-EL POS |
| Responder la API `/v1` y procesar solicitudes sin internet | Hablar con las terminales del restaurante |
| Guardar llaves privadas de firma y respaldos cifrados | Actualizar AD-EL POS o firmar sus instaladores |

El servidor de AD-EL POS verifica cada licencia sin internet, con las llaves públicas. Este servidor nunca recibe datos de operación del restaurante.

## 2. Componentes

| Ruta | Función |
| --- | --- |
| `public/index.php` | Punto de entrada único. Fija cabeceras de seguridad (CSP, `X-Frame-Options`, `Referrer-Policy`, HSTS con HTTPS) y entrega cada petición a `Http\Kernel`. Si algo falla al arrancar, responde 503 con un identificador de referencia y lo registra con el prefijo `adelpos-license`. |
| `public/router.php` | Solo para el servidor PHP local de desarrollo: sirve los recursos estáticos y envía lo demás a `index.php`. |
| `public/assets/` | `app.css`, `app.js`, Bootstrap 5 local (sin CDN) y la marca en `brand/` (`adel-pos-tema-claro.png`, `adel-pos-tema-oscuro.png`, `icon.png`). |
| `src/App.php`, `src/Config.php` | Arranque y composición de servicios. La configuración viene de `ADELPOS_LICENSE_CONFIG` o de `config/local.php`. Las variables de entorno con el mismo nombre tienen prioridad, y `Config::validate()` revisa claves, origen y el entorno de licencias (`LICENSE_ENVIRONMENT`). `App::boot()` se detiene si la llave de firma activa es de otro entorno. |
| `src/Http/` | `Kernel` (panel: rutas, sesión, CSRF, origen, redes permitidas), `ApiKernel` (API `/v1`), `View` (escape, fechas con su zona horaria, textos del modelo de licencias y etiquetas de auditoría) y `Network` (`ADMIN_NETWORKS`). |
| `src/Application/` | Casos de uso: `AuthService`, `TrustedDevices`, `AdminService`, `Access`/`Actor` (roles y permisos), `CatalogService` (clientes, productos, módulos), `LicenseService` (emisión y cambios de módulos, clave y revocación), `PlanService` (catálogo de planes y propagación de aumentos), `CommercialService` (renovación, conversión, cambio de plan, mantenimiento y periodo inicial, cada uno con su vista previa), `ScheduledChanges` (cambios de plan programados y la tarea que los aplica), `Payments` (registro de pagos), `LicenseHistory` (historial comercial de una licencia), `ActivationService` (API en línea), `Transfers` (transferencias de autoservicio y del propietario, su confirmación y la liberación manual), `Alerts` (alertas del panel y su revisión), `PaidPeriods` (periodos pagados que todavía corren, base de la bonificación), `OfflineService` (`.licreq`/`.lic`), `LicenseActivator` (alta de una instalación y primer periodo, común a las dos vías), `RevisionPublisher` (contenido y firma de las revisiones), `LicenseMaterials` (código y archivos sensibles), `DocumentService` (constancias) y `Operations` (idempotencia). |
| `src/Domain/` | Reglas sin estado: `LicenseContent` (campos, orden y reglas del contenido firmado), `Protocol` (peticiones de la API y prueba de posesión), `Jws`, `OfflineRequest`, `StrictJson`, `ActivationCode` (códigos 3-4-4-4), `Capabilities` (dependencias y ciclos de módulos), `LicenseTerms` (tipos, periodos, duraciones, cupos, tolerancia y precios por plazo), `CalendarPeriod` (motor de fechas: primer periodo, renovación, mantenimiento y los 12 meses anteriores de la alerta), `Money` (montos en MXN como centavos enteros), `Credit` (bonificación de un cambio de plan), `TimeZones`, `Input`, `Uuid`, `Problem`, `ApiProblem` (códigos de error y su HTTP) y `ApiResponse`. |
| `src/Infrastructure/` | MySQL (`Database`, `Migrator`, `ReadRepository`, `MySqlFiles`), criptografía (`Crypto`, `SigningKeys`, `Totp`), `Sessions`, `RateLimiter`, `Audit`, respaldos (`Backup`, `EncryptedArchive`, `RecoveryVerifier`), `CertificatePdf` y `Clock`. |
| `templates/` | Vistas PHP del panel, la verificación pública y la constancia PDF. |
| `bin/` | CLI de operación: configuración, migración, permisos, administrador inicial, llaves, respaldo y restauración, verificación de auditoría, limpieza, cambios de plan programados (`apply-scheduled-changes.php`), comprobaciones previas, paquete de despliegue y revisión de secretos. |
| `database/migrations/` | `001_esquema_inicial.sql` (esquema recibido y producto `adel_pos` sin módulos), `002_entornos_de_firma.sql`, `003_contrato_v1.sql`, `004_retiro_de_lo_heredado.sql`, `005_precios_y_pagos.sql`, `006_editor_de_planes.sql`, `007_cambios_programados.sql` y `008_transferencias_y_alertas.sql`. Ver [SCHEMA.md](SCHEMA.md). |
| `deploy/` | Ejemplos de Nginx y del pool de PHP-FPM, y el servicio y temporizador `adelpos-license-maintenance.*`. |
| `contracts/v1/` | Forma exacta del contrato: `license.schema.json`, `openapi.yaml`, los archivos de prueba con su generador y el verificador de referencia en Go. Al paquete de despliegue solo van el esquema y el OpenAPI, como documentación. |

## 3. Panel administrativo

Recorrido de una petición:

1. **Red.** `Kernel` deriva a `ApiKernel` cualquier ruta `/v1/...`, y atiende `/verify/<token>` (verificación pública de constancias, limitada por IP). El resto del panel solo responde a redes de `ADMIN_NETWORKS`.
2. **Sesión.** Se carga desde la cookie `__Host-license_admin`, o `adelpos_admin_dev` en desarrollo. Las sesiones viven en `admin_sessions`, guardadas como hash del token.
3. **Formularios.** Cada `POST` exige el token CSRF de la sesión. Si el navegador envía `Origin`, debe coincidir con `APP_URL`.
4. **Acceso.** Usuario y contraseña (Argon2id), luego la verificación en dos pasos (TOTP) obligatoria y 8 códigos de recuperación de un solo uso. Con HTTPS se puede confiar en el navegador durante 30 días (cookie `__Host-adelpos_trusted_device`); la contraseña sigue siendo obligatoria.
5. **Roles.**
   - `superadmin`: todo.
   - `operator`: escribe, con permisos opcionales (`credentials.reveal`, `documents.delivery` y `offline.download`).
   - `viewer`: solo consulta.

   Las acciones sensibles (revelar el código, descargar un `.lic`, constancias de entrega, transferencias y la liberación manual del contador de autoservicio) piden de nuevo la contraseña y un código de verificación.
7. **Alertas.** Cada pantalla muestra en la barra superior el botón de notificaciones con el número de alertas sin revisar (`ReadRepository::alertSummary`).
8. **Idempotencia.** Cada mutación lleva un `operation_id`. `Operations` guarda el resultado, así que un reintento no repite la operación.

## 4. API en línea `/v1`

Sigue la sección 8 del contrato. Su especificación formal es [contracts/v1/openapi.yaml](contracts/v1/openapi.yaml), y una prueba comprueba que rutas, campos y errores coinciden con el código.

| Ruta | Acción |
| --- | --- |
| `POST /v1/activations/challenge` | Desafío de un solo uso, de 5 minutos, para `activate`, `transfer`, `refresh` o `deactivate` |
| `POST /v1/activations` | Activar con el código comercial y la prueba de posesión |
| `POST /v1/activations/transfer` | Transferir por autoservicio a esta instalación (sección 12 de este documento) |
| `POST /v1/activations/refresh` | Devolver la revisión vigente de una activación; si ya terminó, su revisión de cierre, y confirmar la transferencia pendiente que salió de ella |
| `POST /v1/activations/deactivate` | Liberar la licencia y devolver la revisión de cierre; también confirma la transferencia pendiente |

**Condiciones de cada petición:**
- `POST`;
- HTTPS (en desarrollo se acepta HTTP desde la propia máquina);
- `Content-Type: application/json`;
- 16 KiB como máximo, y JSON estricto: se rechazan claves duplicadas y valores inválidos;
- exactamente los campos del contrato: ni faltantes ni sobrantes;
- límite de 30 peticiones por minuto por IP.

**Identidad de la instalación.** `server_id` (UUID versión 4 en minúsculas) más su llave pública de licencias. La prueba de posesión es una firma Ed25519 sobre el mensaje `ADELPOS-LIC-V1`, con el desafío, el producto, el `server_id` y la activación.

**Errores.** Responden `{"error":{"code","message","request_id","details"}}`, con los diez códigos del contrato y su HTTP (`ApiProblem::STATUS`). `request_id` es el del cliente o `null`; `details` siempre es un objeto. Una falla interna responde `TEMPORARY_UNAVAILABLE` y se registra con el prefijo `adelpos-license API`.

**Funcionamiento:**
- `license_requests` guarda cada `request_id`: un reintento idéntico devuelve la misma respuesta, y uno con otro contenido recibe `REQUEST_CONFLICT`.
- La primera activación de una licencia fija su primer periodo. `LicenseActivator` registra la activación y `RevisionPublisher` firma la revisión.
- Una licencia vale para una sola computadora principal a la vez. La misma instalación (misma identidad y misma llave) que vuelve a activar recibe su revisión vigente. Otra recibe `ACTIVATION_LIMIT` con `details.transfer_available`: `true` si no hay una transferencia por autoservicio pendiente.
- Activar y transferir comparten la búsqueda del código, su límite de intentos (10 cada 15 minutos por código) y la verificación de la prueba con la llave enviada.
- Desactivar una activación que ya terminó devuelve su revisión de cierre. `REVOKED` lo recibe solo quien intenta activar una licencia revocada.
- La versión de AD-EL POS (`app_version`) queda guardada en la activación.

## 5. Firma de licencias

- **Entornos de firma.** `test` y `production`, según `LICENSE_ENVIRONMENT` (contrato, sección 3). Son independientes de `APP_ENV`: `licensing.sicrat.com` funciona con `APP_ENV=production` y `LICENSE_ENVIRONMENT=test`. Una base de datos pertenece a un solo entorno.
- **Llaves.** `SigningKeys` guarda cada llave privada Ed25519 en un archivo 0600 de `SIGNING_KEY_DIR`, por omisión `var/keys/<entorno>`. En la base solo están la pública y una referencia. Cada llave, su archivo y su carpeta son de un solo entorno.
- **Estados.** Las llaves pasan por `staged`, `signing` y `verify_only`. `signing_scopes` marca la activa por entorno y propósito, y el servidor firma solo con la de su entorno.
- **Llaves de prueba.** Las llaves derivadas de las semillas públicas de prueba se rechazan para licencias de producción y en servidores con `APP_ENV=production`.
- **Llaves públicas para AD-EL POS.** `bin/signing-key.php --action=export` entrega `kid`, `environment` y `public_key` de las llaves de licencias del entorno.
- **Contenido.** `RevisionPublisher` arma los 21 campos de la sección 4 del contrato, en su orden: entorno, licencia, activación, `server_id` y llave de la instalación, nombre del titular (la razón social si está capturada; si no, el nombre comercial), tipo, periodo de cobro, estado y motivo, revisión, fechas, tolerancia, plan, cupos y módulos habilitados.
- **Estado.** `active` solo si la licencia y la activación están vigentes. Si no, `revoked` con su motivo: `deactivated` (se liberó), `transferred` (el propietario la retiró de esa computadora) o `revoked` (el propietario revocó la licencia).
- **Validación antes de firmar.** `LicenseContent::assertValid()` comprueba el contenido completo. Si algo no cumple, no se firma y la transacción se revierte. Las mismas reglas están publicadas en `contracts/v1/license.schema.json`.
- **Revisiones.** El contador es global por licencia: cada firma lo aumenta y ningún número se reutiliza. El JWS es compacto, con encabezado exacto `{"alg":"EdDSA","kid":"<kid>","typ":"adelpos-lic+jws"}`. Cada revisión queda en `license_revisions` con su entorno, su motivo de cierre y su SHA-256; nunca se edita.

### Motor de fechas

`Domain\CalendarPeriod` calcula todas las fechas de una licencia (contrato, secciones 5.1, 5.2, 5.4 y 5.5). No consulta la base de datos ni la hora del sistema: recibe el instante de inicio de quien lo llama, que lo toma del reloj de la aplicación.

- **Zona horaria.** Calcula en la zona del cliente y devuelve instantes UTC, con microsegundos.
- **Meses de calendario** (`addMonths`). Suma meses al mes local del inicio y busca el día y la hora ancla. Si el día no existe en ese mes, usa el último: 31 ene → 28 feb → 31 mar → 30 abr. El ancla se guarda en la licencia y no cambia. Al encadenar periodos, si el inicio es un ancla que cayó en una hora inexistente y pasó al mes siguiente, cuenta desde el mes del ancla (etapa 3: hay casos reales en Singapur, Kuala Lumpur y Sofía).
- **Días de calendario** (`addDays`), para la prueba de 15 días: la misma hora local, 15 fechas después.
- **Cambios de horario.** Si la hora local se repite, usa el primero de los dos instantes. Si no existe, usa el primer instante después del salto. Lo resuelve con las transiciones de la zona, sin depender de cómo PHP ajusta esas horas.
- **Primer periodo** (`firstPeriod`). Suscripción: vence 1, 3, 6 o 12 meses después del inicio. Prueba: 15 días, 1 mes o 3 meses. En las dos, las actualizaciones terminan con el vencimiento. Perpetua: no vence, y las actualizaciones duran 0, 6 o 12 meses; con 0 terminan en la activación misma. La conversión de una prueba y la subida de plan de una suscripción lo usan desde la fecha de pago.
- **Renovación** (`renewal`, §5.3). Antes de vencimiento + `grace_days` × 24 horas exactas, la base es el vencimiento y se conserva el ancla; desde ese límite, la base es la fecha de pago y el ancla pasa a ser la del pago.
- **Mantenimiento** (`maintenance`, §5.5). 12 meses desde la fecha posterior entre el pago y el fin vigente de las actualizaciones.

`LicenseActivator` lo usa en la primera activación de una licencia, en línea o al aprobar una solicitud sin internet. Las fechas son de la licencia: si después se activa en otra computadora, conserva las suyas.

## 6. Flujo sin internet

Sigue la sección 9 del contrato.

1. AD-EL POS genera una solicitud `.licreq`: un sobre con el contenido en base64url y la firma de la instalación sobre `ADELPOS-LICREQ-V1`. El contenido lleva la acción (`activate`, `renew` o `deactivate`), `server_id`, la llave pública y la versión de AD-EL POS.
2. En **Solicitudes sin internet** el panel la importa. `OfflineRequest` verifica la firma y el formato, y el archivo original se guarda cifrado y sin cambios. Recibirla no concede nada.
3. El propietario revisa la identidad y los derechos, y aprueba o rechaza:
   - `activate`: asigna la licencia. Su primer periodo empieza en ese momento. Si la misma computadora ya la tiene activa, recibe su revisión vigente.
   - `renew`: entrega la revisión vigente, tal como está firmada, o registra ahí mismo la renovación con su pago (si la computadora sigue activa) y entrega la revisión nueva.
   - `deactivate`: libera la licencia y firma la revisión de cierre.
   - Una activación ya terminada o una licencia revocada reciben su revisión de cierre con `renew` o `deactivate`.
4. El `.lic` aprobado es el mismo JWS que entrega la API en línea. Se descarga con el permiso `offline.download`. Volver a descargarlo no crea otra activación.
5. Desde el panel también se pueden autorizar una transferencia a otra computadora o el retiro de un equipo averiado. Ambas piden confirmación explícita y nueva autenticación, firman `transferred` para la computadora anterior y quedan en la auditoría.

## 7. Auditoría

- **Registro.** `Audit` encadena cada evento con un HMAC (`AUDIT_KEY`) sobre el hash anterior. La cabeza de la cadena está en `audit_heads`.
- **Permisos.** La cuenta de la aplicación no puede modificar ni borrar eventos ni la historia comercial; `bin/preflight.php` lo comprueba en producción.
- **Verificación.** `bin/audit-verify.php` recorre la cadena y emite un anclaje (número de eventos y último hash). Con `--against` lo compara con un anclaje guardado.
- **Panel.** Muestra cada operación con una etiqueta en español (`View::action`).

## 8. Respaldos y recuperación

- **Crear.** `bin/backup.php --action=create` genera un archivo `.adelposbackup` cifrado por bloques (formato `ADELPOS-LICENSE-BACKUP-V1`) con una clave de respaldo de 32 bytes que se guarda aparte. Contiene `manifest.json` con los SHA-256, el volcado `database.sql`, `config.json` y las llaves de firma del entorno.
- **Restaurar.** Solo en una base nueva y vacía, con `bin/restore-empty.php`. `bin/recovery-config.php` arma la configuración destino y `bin/recovery-check.php` verifica firmas, evidencias y auditoría restauradas.
- **Mantenimiento diario.** El servicio `adelpos-license-maintenance` limpia datos temporales, emite el anclaje de auditoría y crea el respaldo cifrado.

## 9. Constancias PDF

- **Generación.** `DocumentService` genera constancias de licenciamiento o de entrega para activación, con la modalidad, el plan, los cupos y las fechas de la licencia en la zona horaria del cliente. `CertificatePdf` las dibuja con dompdf: el logo claro de AD-EL POS, verificado por su SHA-256 y reducido solo en memoria, y un QR hacia `/verify/<token>`.
- **Almacenamiento.** Cada constancia tiene un folio `ADELPOS-…` y una revisión inmutable. Se guarda cifrada junto con la huella SHA-256 del PDF.
- **Verificación pública.** Confirma folio, revisión y estado, pero no demuestra que un equipo esté activado.

## 10. Datos protegidos

| Dato | Protección |
| --- | --- |
| Secretos TOTP y de inscripción | XChaCha20-Poly1305 con `MFA_KEY`, ligados al contexto de la cuenta |
| Código comercial 3-4-4-4 | HMAC con `CREDENTIAL_KEY` para buscarlo; copia cifrada con un contexto HKDF `adelpos-license/activation-code/v1`, recuperable solo con permiso y nueva autenticación |
| PDF y datos de constancias | Cifrados con el contexto `adelpos-license/document/v1` |
| Evidencia de solicitudes sin internet | Cifrada con el contexto propio `adelpos-license/offline-evidence/v1`, que ningún otro propósito puede usar |
| Tokens de sesión | Se guarda solo su SHA-256 |
| CSRF y límites de intentos | HMAC con `SESSION_KEY` |
| Códigos de recuperación | HMAC con `MFA_KEY` |
| Operaciones idempotentes | HMAC con `OPERATION_KEY` sobre la acción y sus datos |
| Cadena de auditoría | HMAC con `AUDIT_KEY` |

## 11. Ciclo comercial (etapa 3)

La vida comercial de una licencia después de emitirla cambia sus condiciones y sus fechas, nunca el formato del contenido firmado (contrato §4).

| Operación | Servicio | Qué hace |
| --- | --- | --- |
| Monto de alta | `LicenseService::issue` | Registra el pago de alta con el precio sugerido del catálogo; una prueba vale 0 |
| Editor de planes | `PlanService` | Crea, edita y archiva planes (solo el superadministrador). Propaga solo aumentos, y solo si se elige, en una transacción |
| Renovación | `CommercialService::renew` | §5.3: base según la tolerancia, cambio de duración, misma activación. También al aprobar un `renew` sin internet |
| Conversión | `CommercialService::convert` | Prueba a suscripción o perpetua, desde la fecha de pago |
| Cambio de plan | `CommercialService::changePlan` | Cupo por cupo: aumentos con bonificación de todos los periodos pagados que no terminan (`PaidPeriods` y `Domain\Credit`), reducciones programadas, perpetua que solo sube, prueba sin cobro |
| Cambio programado | `ScheduledChanges` y `bin/apply-scheduled-changes.php` | Se aplica una sola vez al llegar su instante, por la tarea diaria o por la renovación |
| Mantenimiento | `CommercialService::buyMaintenance` | 12 meses desde la fecha posterior entre el pago y el fin vigente |
| Periodo inicial | `CommercialService::editInitialTerms` | Tipo, condición, plan y cupos de una licencia sin activar |

Reglas comunes:

1. **Una transacción por operación:** pago (`Payments`), cambio de la licencia, revisión firmada (`RevisionPublisher`) y auditoría (`LicenseHistory`), o nada.
2. **Idempotencia** por `operation_id` (`Operations`) y **bloqueo optimista** por `row_version`.
3. **Orden de bloqueo:** producto, plan de destino, licencia, cambio programado y, al final, la cabeza de auditoría.
4. **Vista previa:** cada operación tiene un `quote…` que calcula lo mismo sin escribir. El panel la muestra (GET) antes de confirmar (POST), y la confirmación lleva el instante exacto de pago de la vista previa.
5. **Firma** solo cuando cambia algo del contenido firmado, para cada activación vigente. Las computadoras sin internet la reciben con `renew` o con la descarga del `.lic`.

## 12. Transferencias y alertas (etapa 4)

| Pieza | Dónde | Qué hace |
| --- | --- | --- |
| Transferencia por autoservicio | `ActivationService::transfer` y `Transfers::selfService` | Contrato §8.4, en el orden de la especificación: código, revocada, misma instalación, sin activación vigente (activación normal), pendiente (`TRANSFER_LIMIT`) y, si no, cierre `transferred` de la anterior, activación nueva con las mismas fechas (`LicenseActivator::attach`) y registro `self_service` / `pending`, en una transacción |
| Transferencias del propietario | `Transfers::recordOwner`, desde `OfflineService` | La transferencia sin internet con destino y «recuperar plaza» quedan con la vía `owner`, confirmadas: no consumen el autoservicio |
| Confirmación | `Transfers::confirm`, desde `ActivationService` y `OfflineService` | La computadora anterior confirma al autenticarse y recibir su cierre: `refresh`, `deactivate`, o la aprobación de su `.licreq` de `renew` o `deactivate`. Idempotente |
| Liberación manual | `Transfers::release` | El superadministrador, con motivo y nueva autenticación dentro del servicio |
| Alertas | `Alerts` | `transfer_blocked` con cada `TRANSFER_LIMIT`; `frequent_transfers` con dos o más transferencias en los 12 meses de calendario anteriores, en la zona del cliente. Una abierta por tipo y licencia, con su contador; revisarla la cierra |

**Garantías en la base:** una sola transferencia de autoservicio pendiente por licencia y una sola alerta abierta por tipo y licencia, con índices únicos sobre columnas generadas. **Orden de bloqueo:** producto, licencia, activación, transferencia, alerta y, al final, la cabeza de auditoría; `Alerts` nunca escribe en la auditoría por su cuenta.

**Transferir nunca cambia las fechas.** Las fechas son de la licencia, no de la activación: una prueba o suscripción vencida se transfiere y sigue vencida.

## 13. Pendiente para las etapas siguientes

Las etapas 3 y 4 no dejaron marcas `PENDIENTE etapa 3` ni `PENDIENTE etapa 4`. Lo que todavía no existe está marcado en el código con `PENDIENTE etapa N:`.

| Parte | Cómo funciona hoy | Etapa |
| --- | --- | --- |
| Panel y constancias | Ajuste mínimo para emitir, consultar, operar el ciclo comercial, las transferencias y las alertas | 5: pantallas definitivas, diseño final de las constancias, `limit_req` de Nginx y despliegue en el VPS |
| Contrato `_v2` | V1.0 con 37 propuestas V1.1 registradas | El propietario lo redacta con la tabla consolidada de `DECISIONS.md`, sección 15 |
