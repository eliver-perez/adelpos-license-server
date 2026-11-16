# Arquitectura del servidor de licencias de AD-EL POS

Este documento describe el código tal como queda al cerrar la etapa 1. Cada parte que todavía sigue el protocolo heredado está señalada como **herencia temporal**, con la etapa que la reemplaza según el contrato [docs/CONTRATO_LICENCIAS_ADEL_POS.md](docs/CONTRATO_LICENCIAS_ADEL_POS.md).

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
| `src/App.php`, `src/Config.php` | Arranque y composición de servicios. La configuración viene de `ADELPOS_LICENSE_CONFIG` o de `config/local.php`. Las variables de entorno con el mismo nombre tienen prioridad, y `Config::validate()` revisa claves y origen. |
| `src/Http/` | `Kernel` (panel: rutas, sesión, CSRF, origen, redes permitidas), `ApiKernel` (API `/v1`), `View` (escape, fechas en `America/Monterrey` y etiquetas de auditoría) y `Network` (`ADMIN_NETWORKS`). |
| `src/Application/` | Casos de uso: `AuthService`, `TrustedDevices`, `AdminService`, `Access`/`Actor` (roles y permisos), `CatalogService` (clientes, productos, módulos), `LicenseService` (emisión y cambios), `ActivationService` (API en línea), `OfflineService` (`.licreq`/`.lic`), `RevisionPublisher` (firma de revisiones), `LicenseMaterials` (código y archivos sensibles), `DocumentService` (constancias) y `Operations` (idempotencia). |
| `src/Domain/` | Reglas sin estado: `Protocol`, `Jws`, `OfflineRequest`, `StrictJson`, `ActivationCode` (códigos 3-4-4-4), `Capabilities` (dependencias y ciclos de módulos), `Input`, `Uuid`, `Problem` y `ApiProblem`. |
| `src/Infrastructure/` | MySQL (`Database`, `Migrator`, `ReadRepository`, `MySqlFiles`), criptografía (`Crypto`, `SigningKeys`, `Totp`), `Sessions`, `RateLimiter`, `Audit`, respaldos (`Backup`, `EncryptedArchive`, `RecoveryVerifier`), `CertificatePdf` y `Clock`. |
| `templates/` | Vistas PHP del panel, la verificación pública y la constancia PDF. |
| `bin/` | CLI de operación: configuración, migración, permisos, administrador inicial, llaves, respaldo y restauración, verificación de auditoría, limpieza, comprobaciones previas, paquete de despliegue y revisión de secretos. |
| `database/migrations/` | `001_esquema_inicial.sql`: esquema único y producto `adel_pos` sin módulos. |
| `deploy/` | Ejemplos de Nginx y del pool de PHP-FPM, y el servicio y temporizador `adelpos-license-maintenance.*`. |
| `contracts/v1/` | Archivos de prueba del contrato heredado, su generador PHP y el verificador independiente en Go. Solo para desarrollo; no entran en el paquete. |

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

   Las acciones sensibles (revelar el código, descargar un `.lic`, constancias de entrega, transferencias) piden de nuevo la contraseña y un código de verificación.
6. **Idempotencia.** Cada mutación lleva un `operation_id`. `Operations` guarda el resultado, así que un reintento no repite la operación.

## 4. API en línea `/v1`

| Ruta | Acción |
| --- | --- |
| `POST /v1/activations/challenge` | Desafío de un solo uso para `activate`, `refresh` o `deactivate` |
| `POST /v1/activations` | Activar con el código comercial y la prueba de posesión |
| `POST /v1/activations/refresh` | Devolver la revisión vigente de una activación |
| `POST /v1/activations/deactivate` | Desactivar y liberar la licencia |

**Condiciones de cada petición:**
- `POST`;
- HTTPS (en desarrollo se acepta HTTP desde la propia máquina);
- `Content-Type: application/json`;
- 16 KiB como máximo, y JSON estricto: se rechazan claves duplicadas y valores inválidos;
- límite de 30 peticiones por minuto por IP.

**Errores:** responden `{"error":{"code","message","request_id"}}`. Una falla interna responde `TEMPORARY_UNAVAILABLE` y se registra con el prefijo `adelpos-license API`.

**Funcionamiento:**
- La prueba de posesión es una firma Ed25519 de la instalación sobre el desafío.
- `license_requests` guarda cada `request_id`: un reintento idéntico devuelve la misma respuesta, y uno con otro contenido se rechaza.
- `ActivationService` registra la activación y `RevisionPublisher` firma una revisión nueva.

## 5. Firma de licencias

- **Llaves.** `SigningKeys` guarda cada llave privada Ed25519 en un archivo 0600 de `SIGNING_KEY_DIR`, por omisión `var/keys/<APP_ENV>`. En la base solo están la pública y una referencia.
- **Estados y entornos.** Las llaves pasan por `staged`, `signing` y `verify_only`. `signing_scopes` marca la activa por entorno y propósito.
- **Llaves de prueba.** En producción se rechazan las llaves derivadas de las semillas públicas de prueba.
- **Revisiones.** `RevisionPublisher` arma el contenido, incrementa el contador de revisión de la licencia y firma un JWS compacto (`EdDSA`). Cada revisión queda en `license_revisions` con su SHA-256; nunca se edita.

## 6. Flujo sin internet

1. AD-EL POS genera una solicitud `.licreq`: JSON firmado por la instalación, con acción `activate`, `renew` o `deactivate`.
2. En **Solicitudes sin internet** el panel la importa. `OfflineRequest` verifica la firma y el formato, y el archivo original se guarda cifrado como evidencia.
3. El propietario revisa la identidad y los derechos, asigna la licencia en una activación y aprueba o rechaza. Aprobar firma la revisión que corresponde.
4. El `.lic` aprobado (el mismo JWS que en línea) se descarga con el permiso `offline.download`. Volver a descargarlo no crea otra activación.
5. Desde el panel también se pueden autorizar una transferencia a otra instalación o la recuperación de un equipo averiado. Ambas piden confirmación explícita y nueva autenticación, y quedan en la auditoría.

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

- **Generación.** `DocumentService` genera constancias de licenciamiento o de entrega para activación. `CertificatePdf` las dibuja con dompdf: el logo claro de AD-EL POS, verificado por su SHA-256 y reducido solo en memoria, y un QR hacia `/verify/<token>`.
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

## 11. Herencia temporal

El motor de licencias recibido se conserva sin cambios de lógica. Las reglas fijas en el código llevan el comentario `HEREDADO: se reemplaza en etapa 2–4 según CONTRATO_LICENCIAS_ADEL_POS.md (<regla>)`, y la lista de ubicaciones está en [AVANCE.md](AVANCE.md), sección 4.2.

| Parte heredada | Cómo funciona hoy | Etapa que la reemplaza |
| --- | --- | --- |
| Contenido de la licencia | `installation_id`, huella del equipo, `maintenance_until`, `entitled_release_until`, `features` y `limits` | 2: campos del contrato (`server_id`, `environment`, `license_type`, `billing_period`, `updates_until`, `plan`, cupos) |
| Rutas y mensajes del protocolo | Las 4 rutas de la sección 4, prueba `LIC-V1`, solicitud `LICREQ-V1`, encabezado `lic+jws` | 2: prueba `ADELPOS-LIC-V1`, `ADELPOS-LICREQ-V1`, `adelpos-lic+jws`, ruta de transferencia |
| Archivos de prueba del contrato | `contracts/v1/fixtures/` con el producto sintético y el verificador Go actual | 2 |
| Una instalación por licencia | `max_installations=1` y una sola activación activa | 2 |
| Tolerancia | 15 días fijos en suscripción | 2 y 3 |
| Huella del equipo | `fingerprint_version` `'1'` obligatoria | 2 |
| Lista de límites | `max_users`, `max_libraries`, `max_documents` | 2: `cash_registers`, `order_takers`, `kitchen_displays` |
| Tipos y fechas | Perpetua o suscripción con vencimiento libre; mantenimiento como compra separada | 3: planes, pruebas, meses de calendario, renovación y propagación de planes |
| Transferencias | Solo desde el panel, por el propietario | 4: autoservicio y alertas; ajustes del flujo sin internet |
| Panel y constancias | Pantallas actuales y constancias sin planes ni cupos | 5: pantallas nuevas, contenido de las constancias y despliegue en el VPS |
