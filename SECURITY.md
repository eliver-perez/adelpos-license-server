# Seguridad

Controles de seguridad del servidor de licencias de AD-EL POS tal como están implementados al cerrar la etapa 2. El protocolo es el del [contrato de licencias](docs/CONTRATO_LICENCIAS_ADEL_POS.md) V1.0. Las decisiones están en [DECISIONS.md](DECISIONS.md).

## 1. Modelo de amenazas

**Qué se protege:**
- las llaves privadas de firma;
- los códigos comerciales de activación;
- las credenciales y la verificación en dos pasos de los administradores;
- la historia comercial y la auditoría;
- los archivos sin internet (`.licreq`) con su evidencia;
- las constancias PDF;
- los respaldos.

**De quién:** visitantes anónimos de Internet, instalaciones que intentan obtener derechos que no les corresponden, administradores con más privilegios de los debidos, y quien obtenga una copia de la base o de un respaldo.

**Límites de confianza:**
- Internet → Nginx (TLS) → PHP-FPM → MySQL;
- navegador → sesión del panel;
- aplicación → archivos de llaves;
- servidor → respaldo externo.

Todo lo que llega del exterior se trata como dato no confiable: JSON de la API, archivos `.licreq`, formularios, nombres y fechas declaradas.

| Amenaza | Control principal | Riesgo que queda |
| --- | --- | --- |
| Falsificar o alterar una licencia | Firma Ed25519 sobre los bytes exactos; `kid` y entorno registrados | Robo de la llave privada |
| Usar una licencia en otra instalación | La licencia firma la identidad y la llave pública de la instalación | Clonación completa de la máquina por su administrador |
| Repetir desafíos o solicitudes | Desafíos de un uso con vencimiento; idempotencia por `request_id` con HMAC del contenido | Un reintento idéntico obtiene la misma respuesta, sin nuevos derechos |
| Dos activaciones simultáneas | Bloqueo de la fila de licencia e índice único de activación activa | Acceso directo a la base con privilegios de administrador de MySQL |
| Adivinar códigos o enumerar licencias | Códigos de 74 bits, límites de intentos y errores uniformes | Ataques volumétricos que requieren protección de red |
| Robo de sesión, CSRF o XSS | Cookies `__Host-` seguras, token CSRF, origen exacto, CSP y escape de salida | Equipo del administrador comprometido |
| Abuso de privilegios internos | Roles, permisos explícitos, confirmación de identidad y auditoría encadenada | El superadministrador conserva poder comercial deliberado |
| Secretos en el repositorio o en el paquete | `bin/check-secrets.php`, `.gitignore` y lista explícita del empaquetador | Error humano fuera de git |
| Fuga de un respaldo | Respaldo cifrado con llave separada | Quien tenga respaldo y llave obtiene todos los secretos |

## 2. Configuración y secretos de la aplicación

- **Ubicación.** La configuración vive en `config/local.php` (fuera de git) o en la ruta que indique `ADELPOS_LICENSE_CONFIG`. `bin/configure.php` la crea con permisos 0600. Las variables de proceso tienen prioridad.
- **Validación (`Config::validate`).**
  - `APP_ENV` debe ser `production`, `development` o `testing`.
  - `LICENSE_ENVIRONMENT` es obligatoria y debe ser `test` o `production`. No tiene valor por omisión: nunca se firma en un entorno que nadie eligió.
  - `LICENSE_ENVIRONMENT=production` exige `APP_ENV=production`, para que las llaves de producción no se usen con las facilidades de desarrollo.
  - `APP_URL` debe ser un origen sin ruta.
  - **HTTPS es obligatorio** fuera del desarrollo local en 127.0.0.1, localhost o ::1.
  - Las cinco llaves de aplicación deben ser válidas.
- **No deben regenerarse en una instalación existente:** dejarían ilegibles los datos cifrados e inválidos los HMAC.

| Llave | Uso |
| --- | --- |
| `MFA_KEY` | Cifrado XChaCha20-Poly1305 de los secretos TOTP (contexto `mfa:<administrador>`) y HMAC de los códigos de recuperación |
| `CREDENTIAL_KEY` | HMAC de los códigos comerciales para buscarlos; raíz HKDF-SHA256 del cifrado de códigos, constancias y evidencia sin internet |
| `AUDIT_KEY` | HMAC que encadena los eventos de auditoría |
| `SESSION_KEY` | Token CSRF derivado de la sesión; HMAC de las identidades en los límites de intentos |
| `OPERATION_KEY` | HMAC del contenido de cada solicitud idempotente (API, archivos sin internet y formularios del panel) |

**Contextos HKDF** (`src/Infrastructure/Crypto.php`):
- registros protegidos: `adelpos-license/<propósito>/v1`, con los propósitos `activation-code` y `document`;
- evidencia sin internet: `adelpos-license/offline-evidence/v1`.

`Crypto::assertPurpose` rechaza el propósito `offline-evidence` y cualquier nombre fuera de `[a-z][a-z0-9-]*`, para que un registro nunca derive la misma llave que la evidencia. Cada cifrado usa un nonce aleatorio de 24 bytes y datos asociados ligados al registro, por ejemplo `credential:<id>` o `document-pdf:<id>`.

## 3. Llaves de firma

- **Algoritmo.** Ed25519 mediante Sodium; no hay implementación propia.
- **Entornos de firma (contrato, sección 3).** Hay dos, `test` y `production`, definidos por `LICENSE_ENVIRONMENT` e independientes de `APP_ENV`. Cada llave pertenece a uno solo:
  - el registro guarda el entorno de cada llave, y una llave foránea compuesta impide que la llave activa de un entorno sea de otro;
  - el archivo privado también declara su entorno, y se rechaza si no coincide con el registro;
  - la carpeta es distinta por entorno, y ni `generate` ni `bin/preflight.php` aceptan una carpeta con llaves de otro;
  - el servidor firma solo con la llave activa de su entorno. Si la llave activa de la base es de otro, **no arranca**: responde 503 sin revelar el motivo, y `bin/preflight.php` lo explica en la terminal.
- **Almacenamiento.** Las llaves privadas viven en archivos JSON 0600, dentro de un directorio 0700 (`SIGNING_KEY_DIR` o `var/keys/<LICENSE_ENVIRONMENT>`), siempre **fuera de `public/`**. El nombre del archivo es una referencia aleatoria; nunca se arma con un `kid` recibido por HTTP.
- **En la base** solo están la llave pública, el `kid`, el entorno, el propósito, el estado y la referencia opaca.
- **Comprobaciones al cargar (`SigningKeys::readSecret`):**
  - ubicación fuera de `public/`;
  - que no sea un enlace simbólico;
  - permisos sin acceso de grupo ni de otros;
  - tamaño máximo;
  - coincidencia de `kid`, entorno, propósito y llave pública entre archivo y base;
  - que el par Ed25519 sea consistente.
- **Llaves públicas de prueba.** Las cinco llaves de los archivos de prueba del contrato (`contracts/v1/fixtures/`), derivadas de `ADELPOS LICENSE PUBLIC TEST ONLY V1 <nombre>`, se rechazan con `LICENSE_ENVIRONMENT=production` y en cualquier servidor con `APP_ENV=production`, aunque se les cambie el nombre en el archivo y en el registro. Así tampoco `licensing.sicrat.com` puede firmar con ellas. Lo verifica `SigningEnvironmentsTest::testPublicFixtureSeedsAreRejectedForProductionLicensesAndOnProductionServers`.
- **Exportación de llaves públicas.** `bin/signing-key.php --action=export` lee solo el registro, nunca los archivos privados, y entrega `kid`, `environment` y `public_key` de las llaves de licencias del entorno.
- **Generación y rotación** solo por CLI (`bin/signing-key.php`) con una cuenta de MySQL de gestión. La cuenta de la aplicación solo lee `signing_keys` y `signing_scopes`. Rotar deja la llave anterior en `verify_only` y no altera los JWS ya archivados.

- **Entorno firmado.** Cada licencia lleva el campo `environment`, que es el entorno de la llave que la firmó. El servidor no firma si no coinciden. La confianza por entorno dentro de AD-EL POS (una versión de producción solo confía en llaves de producción) corresponde a su etapa de integración; el verificador de referencia de `contracts/v1/verify/` ya la aplica.
- **Validación antes de firmar.** `LicenseContent::assertValid()` comprueba el contenido completo contra las reglas de la sección 4 del contrato. Si algo no cumple, no se firma y la transacción se revierte, sin efectos parciales. El error nombra el campo, nunca su valor.
- **Un solo producto.** El servidor solo emite y firma licencias de `adel_pos`.

## 4. Acceso al panel

- **Alta inicial.** El primer superadministrador se crea solo por CLI (`bin/bootstrap-admin.php`), con la contraseña pedida sin eco. No hay endpoint de instalación ni cuenta predeterminada. Un cerrojo (`security_state`) impide dos altas simultáneas.
- **Contraseñas.** Argon2id (64 MiB, coste 3, 1 hilo), con nuevo hash automático si cambian los parámetros. Longitud de 12 a 1024 bytes. El acceso siempre calcula un hash, también para cuentas inexistentes, y muestra un mensaje uniforme. `bin/preflight.php` falla si Argon2id no está disponible.
- **Verificación en dos pasos (TOTP).** Obligatoria para todos los administradores: pasos de 30 segundos, 6 dígitos y tolerancia de ±1 paso. Un paso ya aceptado no se reutiliza (`last_totp_step`). El secreto se guarda cifrado.
- **Códigos de recuperación.** 8 por cuenta, de 64 bits cada uno, guardados como HMAC y de un solo uso.
- **Roles.**
  - `superadmin`: tiene todos los permisos.
  - `operator`: necesita permisos explícitos para revelar códigos de activación, generar constancias de entrega y descargar archivos `.lic`.
  - `viewer`: solo consulta.
- **Confirmación de identidad** (contraseña más código TOTP nuevo). Se exige para:
  - revelar un código;
  - las constancias de entrega y la descarga de `.lic`;
  - revocar o reemplazar la clave de una licencia;
  - liberar una instalación por equipo averiado;
  - forzar una transferencia o una desactivación sin internet;
  - crear o modificar administradores;
  - cambiar la propia contraseña.

  Las acciones del panel de esta lista que afectan instalaciones o administradores exigen además ser superadministrador.
- **Recuperación del acceso.** Solo con acceso al servidor (`bin/recover-admin.php`). Revoca las sesiones de la cuenta.
- **Red.** `ADMIN_NETWORKS` limita el panel a una lista de redes; vacío permite cualquier origen.

## 5. Sesiones y navegador

- **Cookies.** En producción, `__Host-license_admin` (`Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, sin `Domain`). En desarrollo, `adelpos_admin_dev`.
- **Token.** 32 bytes aleatorios; en la base solo se guarda su SHA-256.
- **Vigencia.** 30 minutos de inactividad y 8 horas como máximo. Una sesión a medio autenticar dura 10 minutos.
- **Cambios de sesión.** El token se rota al autenticarse. Las sesiones se revocan al salir, al cambiar la contraseña, al modificar la cuenta y en la recuperación por CLI.
- **CSRF.** Todo POST exige un token derivado de la sesión. Si llega la cabecera `Origin`, debe ser exactamente `APP_URL`, así que `Origin: null` se rechaza. Ningún GET cambia estado.
- **Cabeceras** (`public/index.php`):
  - `Content-Security-Policy`: solo recursos propios, imágenes `data:` para el QR, `frame-ancestors 'none'`, `object-src 'none'`, `base-uri 'none'` y `form-action 'self'`;
  - `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`;
  - `Referrer-Policy: same-origin`;
  - `Cache-Control: no-store`;
  - HSTS cuando la conexión es HTTPS.

  La verificación pública de constancias usa `Referrer-Policy: no-referrer` y `noindex`. Bootstrap y el JavaScript propio se sirven localmente.
- **Dispositivos de confianza.**
  - Permiten omitir el código TOTP, nunca la contraseña, durante 30 días sin extensión por uso.
  - Se ofrecen solo en los 10 minutos posteriores a un TOTP correcto, nunca tras usar un código de recuperación.
  - Cookie `__Host-adelpos_trusted_device`, siempre `Secure`. En la base se guarda el hash del token.
  - La autorización queda ligada al hash de la contraseña y al secreto TOTP: cambiar cualquiera de los dos la invalida.
  - Se pueden revocar una por una o todas. Si no se puede guardar la confianza, el acceso continúa sin ella.

## 6. Límites de intentos

Contadores persistentes en `rate_limit_buckets`, confirmados fuera de la transacción de negocio. La IP es `REMOTE_ADDR`; no se confía en cabeceras reenviadas.

| Ámbito | Límite |
| --- | --- |
| Acceso | 20 por IP y 5 por cuenta, cada 15 minutos |
| Código TOTP | 20 por IP y 10 por cuenta, cada 15 minutos |
| Confirmación de identidad | 10 por administrador, cada 15 minutos |
| API: desafíos y operaciones | 30 por minuto por IP (contadores separados) |
| API: identidad autenticada | 30 por minuto por producto y llave pública |
| API: código comercial | 10 cada 15 minutos por producto y código |
| Importación `.licreq` | 30 por minuto por administrador |
| Generación de constancias | 10 por minuto por administrador |
| Verificación pública de constancias | 30 por minuto por IP |

El ejemplo de Nginx define respuestas JSON para 429, pero no configura `limit_req`. La barrera de red por ruta e IP queda pendiente para el despliegue (etapa 5).

## 7. API de instalaciones (`/v1`)

- **Sin cookies del panel.** Solo `POST` con JSON de hasta 16 KiB, limitado en la aplicación y en Nginx. `StrictJson` rechaza claves duplicadas, tipos ambiguos y caracteres de control. Las codificaciones base64url deben ser canónicas.
- **Identidad de la instalación.** `server_id` (UUID versión 4, solo en minúsculas) más su llave pública Ed25519 de licencias. La misma identidad con otra llave es otra instalación. El servidor no recibe huella del equipo ni ningún otro dato de la computadora.
- **Prueba de posesión.** El cliente firma con Ed25519 el mensaje `ADELPOS-LIC-V1`, que incluye un desafío de un solo uso. El desafío dura 5 minutos, queda ligado a la acción, al producto y a la identidad, y se consume bajo bloqueo. Una prueba inválida no consume un desafío legítimo.
- **Campos exactos.** Cada petición lleva exactamente los campos del contrato. Los del protocolo anterior se rechazan con `INVALID_REQUEST`.
- **Errores uniformes.** Un código inexistente o incorrecto produce el mismo error. Los detalles comerciales solo se revelan después de autenticar.
- **Códigos comerciales.** 15 símbolos aleatorios de un alfabeto de 31 (74.31 bits), sin prefijo ni dígito verificador. Se guardan como HMAC y como copia cifrada; nunca aparecen en registros, sesiones, auditoría ni respuestas idempotentes.
- **Errores técnicos.** Producen un 503 genérico (`TEMPORARY_UNAVAILABLE`), sin trazas, y no dejan efectos parciales. La referencia de correlación queda solo en el registro del servidor: la respuesta lleva el `request_id` del cliente o `null`.
- **Una computadora a la vez.** Una licencia tiene como máximo una activación vigente, también bajo concurrencia (índice único en la base). Otra instalación recibe `ACTIVATION_LIMIT`.

> **Pendiente (etapa 4).** La transferencia por autoservicio todavía no existe: la acción `transfer` y su ruta se rechazan. `limit_req` de Nginx para la API se configura en la etapa 5.

## 8. Flujo sin internet

- **Importación.** Los archivos `.licreq` se aceptan solo por el formulario, con un máximo de 64 KiB. Se verifica la firma (sobre `ADELPOS-LICREQ-V1` y el contenido tal como llegó) antes de reservar la solicitud y otra vez, sobre los bytes originales descifrados, al aprobar. El formato anterior se rechaza sin reservar nada.
- **Archivo.** El original se guarda cifrado (`adelpos-license/offline-evidence/v1`, datos asociados `offline:v1:<id>`). La proyección consultable no incluye el código comercial opcional.
- **Aprobación o rechazo.** Exigen sesión completa, rol, CSRF e idempotencia del formulario. Cada solicitud admite una sola decisión, y la evidencia, la decisión y la transferencia solo se insertan.
- **Liberación y transferencia forzadas.** Exigen superadministrador, confirmación de identidad, motivo y aceptar explícitamente el límite: una solicitud firmada prueba posesión de la llave, no que se borraron las copias desconectadas. Si falla una firma o escritura, la instalación anterior sigue activa.

- **Revisiones de cierre.** Una activación terminada o una licencia revocada solo pueden recibir su revisión de cierre: nunca vuelven a quedar activas por una solicitud `renew` o `deactivate`.

> **Pendiente (etapa 4).** Alertas por transferencias repetidas y su relación con el autoservicio.

## 9. Constancias y verificación pública

- **Almacenamiento.** Cada constancia guarda el PDF y una instantánea cifrados, el SHA-256 del PDF y un folio `ADELPOS-…`.
- **Verificación pública.** Usa un token aleatorio de 256 bits, del que solo se guarda el hash. No requiere sesión ni muestra códigos, datos personales ni la instalación.
- **Qué acredita la verificación.** El documento histórico, no que una instalación esté activa hoy. Comparar el SHA-256 detecta un QR copiado a otro documento.
- **Generación (Dompdf).** Sin contenido remoto, sin PHP ni JavaScript, con `chroot` a `public/assets/brand/`. El logo de la constancia se verifica contra su SHA-256 fijo antes de usarse. El PDF no lleva firma PAdES.

## 10. Auditoría

- **Cadena.** Cada evento se encadena con el anterior mediante HMAC (`AUDIT_KEY`), bajo el bloqueo de `audit_heads`, y se confirma junto con el cambio. Los intentos fallidos de acceso se registran aparte, sin secretos.
- **Permisos.** La cuenta de la aplicación solo puede leer e insertar en la auditoría y en las tablas de historia. En producción, `bin/preflight.php` comprueba que no pueda modificarlas ni borrarlas.
- **Comprobación externa.** `bin/audit-verify.php` recorre la cadena y produce un anclaje (número de eventos y último hash) para guardarlo fuera del servidor. Solo ese anclaje externo detecta que alguien con todos los secretos restauró la base a un pasado válido.

## 11. Respaldos y restauración

- **Formato.** `ADELPOS-LICENSE-BACKUP-V1`, extensión `.adelposbackup`. Es un flujo XChaCha20-Poly1305 (secretstream) con cabecera y bloques autenticados, cierre final obligatorio y fin de archivo exacto.
- **Contenido.** El volcado de la base, la configuración con sus secretos, las llaves de firma y un manifiesto con el SHA-256 de cada archivo.
- **Llave.** Es de 32 bytes y vive en un archivo 0600 separado del respaldo. Quien tenga el respaldo y la llave obtiene todos los secretos, así que deben custodiarse por separado.
- **Extracción.** Va a un directorio nuevo 0700 con archivos 0600. Rechaza datos alterados o truncados, nombres fuera del manifiesto y hashes incorrectos.
- **Restauración.** Solo en una base vacía. Nunca borra datos y usa un bloqueo por base.
- **Utilidades de MySQL** (`mysqldump`, `mysql`; `src/Infrastructure/MySqlFiles.php`):
  - reciben las credenciales en un archivo temporal 0600, nunca en la línea de comandos;
  - se ejecutan con el entorno vacío, salvo `MYSQL_TEST_LOGIN_FILE` apuntando a una ruta inexistente;
  - así nunca leen credenciales guardadas en `~/.mylogin.cnf`, que MySQL 8.0 lee incluso con `--defaults-file`.

## 12. Repositorio, paquete y despliegue

- **Revisión de secretos.** `php bin/check-secrets.php` se ejecuta antes de cada commit. Falla con valores en `DB_PASSWORD` o `*_KEY`, llaves privadas PEM o Ed25519, archivos bajo `var/keys/` o archivos `*.key` o `*.pem`, y nunca imprime el valor. `.gitignore` excluye la configuración local, `var/`, las llaves, los respaldos, `vendor/` y `node_modules/`.
- **Paquete.** `bin/build-release.php` solo empaqueta archivos versionados en git de `bin/`, `src/`, `public/`, `templates/`, `database/` y `deploy/`, más una lista explícita de archivos. Aborta si aparece configuración local, `var/`, `.DS_Store` o una llave. No incluye `vendor/`, pruebas ni `contracts/`. Lo verifica `tests/Unit/ReleasePackageTest.php`.
- **Nginx** (`deploy/nginx.conf.example`, dominio de pruebas `licensing.sicrat.com`):
  - TLS 1.2 y 1.3, HSTS, `server_tokens off`;
  - cuerpo máximo de 96 KiB (16 KiB en `/v1`);
  - archivos ocultos denegados y ningún `.php` accesible directamente;
  - bandera de mantenimiento con respuesta 503.
- **PHP-FPM** (`deploy/php-fpm-pool.conf.example`): pool y usuario propios `adelpos-license`, `clear_env`, errores sin mostrar, `expose_php` apagado y límites de subida de 64 KiB.

## 13. Fuera del alcance

- **Protecciones del equipo del restaurante.** Clonar una máquina virtual completa, restaurar instantáneas antiguas o cambiar el reloj: le corresponden a AD-EL POS (contrato, secciones 5.6 y 12).
- **Copias desconectadas.** No hay borrado remoto de una copia que sigue sin conexión (contrato, sección 9.2).
- **Ataques volumétricos.** Requieren protección de red, además de los límites de la aplicación.
- **Administrador de MySQL o del servidor con todos los secretos.** Puede alterar datos. La auditoría lo detecta solo comparando contra un anclaje externo previo.
- **Firma de instaladores y actualizaciones automáticas.** Fuera de V1 (contrato, sección 15).
