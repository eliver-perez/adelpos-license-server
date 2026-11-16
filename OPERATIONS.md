# Operación del servidor de licencias de AD-EL POS

Cómo configurar, operar, respaldar y verificar el servidor. La instalación paso a paso en Ubuntu 24.04 está en [DEPLOY_UBUNTU_24_04.md](DEPLOY_UBUNTU_24_04.md); las decisiones, en [DECISIONS.md](DECISIONS.md); el contrato con AD-EL POS, en [docs/CONTRATO_LICENCIAS_ADEL_POS.md](docs/CONTRATO_LICENCIAS_ADEL_POS.md).

> **Herencia temporal.** El motor de licencias todavía es el heredado: contenido `LIC-V1`/`lic+jws`, huella del equipo, una instalación por licencia, 15 días de tolerancia y límites heredados. Cada regla fija está marcada en el código con `HEREDADO:` y listada en [AVANCE.md](AVANCE.md) (Fase 4, 4.2). Se reemplaza según el contrato así:
>
> | Etapa | Qué cambia en la operación |
> | --- | --- |
> | 2 | Contenido de la licencia, rutas, prueba de posesión, archivos `.licreq`/`.lic` y entornos de firma `test`/`production` |
> | 3 | Planes, tipos de licencia (incluida la prueba), fechas de calendario, renovación y propagación de planes |
> | 4 | Transferencia por autoservicio, alertas y ajustes del flujo sin internet |
> | 5 | Pantallas nuevas del panel, contenido de las constancias y despliegue en el VPS |

## 1. Requisitos

| Componente | Versión |
| --- | --- |
| PHP (CLI y FPM) | 8.3 o superior. Extensiones: `sodium`, `pdo_mysql`, `mbstring`, `xmlwriter`, `dom`, `iconv` y `gd`; Argon2id disponible |
| MySQL | 8.0 o superior. MariaDB no es compatible: el migrador y `bin/preflight.php` la rechazan |
| Nginx | Probado con la configuración de `deploy/`; el VPS tiene 1.24.0 |
| Composer | Instala las dependencias de `composer.lock` (`--no-dev` en el servidor) |

Go y Node no se usan en el servidor; solo en desarrollo (verificador del contrato y pruebas del panel).

## 2. Configuración

La configuración es un arreglo PHP. Se lee del archivo indicado en `ADELPOS_LICENSE_CONFIG` o, si no está definida, de `config/local.php`. Las variables de entorno del proceso prevalecen. `config/local.example.php` muestra las claves con valores vacíos.

| Clave | Uso |
| --- | --- |
| `APP_ENV` | `production`, `development` o `testing`. Fuera de desarrollo local, HTTPS es obligatorio |
| `APP_URL` | Origen del sitio, sin subdirectorio. Pruebas: `https://licensing.sicrat.com` |
| `DB_DSN`, `DB_USER`, `DB_PASSWORD` | Conexión de la cuenta de aplicación (`adelpos_license_app`, permisos restringidos) |
| `MFA_KEY`, `CREDENTIAL_KEY`, `AUDIT_KEY`, `SESSION_KEY`, `OPERATION_KEY` | Cinco claves independientes de 32 bytes. **Se conservan en toda actualización y restauración** |
| `ADMIN_NETWORKS` | Redes CIDR desde las que se permite el panel, separadas por comas. Vacío: cualquier red (sigue exigiendo contraseña y verificación en dos pasos) |
| `SIGNING_KEY_DIR` | Directorio absoluto de las llaves privadas de firma, fuera de `public/`. Por omisión, `var/keys/<APP_ENV>` |

Crear una configuración nueva:

```sh
php bin/configure.php --env=production --url=https://licensing.sicrat.com \
  --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=adelpos_license;charset=utf8mb4' \
  --user=adelpos_license_app --output=/srv/adelpos-license/shared/config.php \
  --signing-dir=/srv/adelpos-license/shared/keys
```

Pide la contraseña de la base sin mostrarla, genera las cinco claves y escribe el archivo con permisos 0600. Nunca reemplaza un archivo existente.

## 3. Base de datos

| Paso | Comando |
| --- | --- |
| Aplicar migraciones (cuenta de migración, contraseña sin eco) | `php bin/migrate.php --user=adelpos_license_migrate` |
| Generar los GRANT de la cuenta de aplicación | `php bin/grants.php --database=adelpos_license --user=adelpos_license_app` |

- El esquema se crea con una sola migración, `database/migrations/001_esquema_inicial.sql`. Solo inserta el producto `adel_pos` («AD-EL POS»), sin módulos. Las tablas y columnas están en [SCHEMA.md](SCHEMA.md).
- El migrador guarda el checksum de cada archivo, usa un bloqueo para que no haya dos migraciones a la vez y se detiene ante una migración incompleta o modificada. Una migración aplicada no se edita: los cambios van en un archivo nuevo.
- `bin/grants.php` imprime los GRANT por tabla. La cuenta de aplicación no puede modificar ni borrar historia: auditoría, revisiones firmadas, cambios comerciales y decisiones sin internet.

## 4. Administradores y acceso

| Tarea | Comando o lugar |
| --- | --- |
| Crear el primer superadministrador (solo en una base sin administradores) | `php bin/bootstrap-admin.php --login=correo@ejemplo.com --name="Nombre"` |
| Recuperar una cuenta | `php bin/recover-admin.php --login=correo@ejemplo.com --reason="Motivo"` |
| Crear administradores, roles y permisos especiales | Panel → Administradores |

- **Roles:** `superadmin`, `operator` (operación) y `viewer` (consulta).
- **Permisos especiales** del operador: revelar códigos de activación, generar constancias de entrega y descargar archivos de licencia (`.lic`). Requieren contraseña y verificación en dos pasos.
- **Primer ingreso:** cada cuenta configura la verificación en dos pasos y guarda sus códigos de recuperación.
- **Dispositivo de confianza:** opcional.
- **Recuperar una cuenta:** reemplaza la contraseña e invalida sesiones, dispositivos de confianza y códigos anteriores. La verificación en dos pasos debe configurarse de nuevo.

## 5. Llaves de firma

```sh
php bin/signing-key.php --action=generate --kid=lic-prod-2026-a --purpose=license --user=adelpos_license_migrate
php bin/signing-key.php --action=list
php bin/signing-key.php --action=activate --kid=lic-prod-2026-a --reason="Pública distribuida" --trust-confirmed --user=adelpos_license_migrate
php bin/preflight.php --require-signer
```

- `generate` crea una llave privada aleatoria en `SIGNING_KEY_DIR` (0600) y muestra solo la pública. Queda en estado `staged`.
- `activate` exige `--trust-confirmed`: confirma que la pública ya se distribuyó en AD-EL POS por un canal autenticado. Las llaves anteriores quedan para verificación (`verify_only`) y no se borran.
- **Rotación:** generar otro `kid`, distribuir la nueva pública junto con las anteriores y solo después activarla.
- En producción se rechazan las llaves derivadas de las semillas públicas de prueba (`ADELPOS LICENSE PUBLIC TEST ONLY V1 …`).
- **Herencia temporal (etapa 2):** los entornos de firma son `production`, `development` y `testing`. El contrato define `test` y `production`, con `kid` asociado al entorno.

## 6. Licencias y operación sin internet

Todo se hace en el panel:
- **Licencias:** emitir (muestra el código 3-4-4-4 una sola vez), renovar, registrar mantenimiento, cambiar módulos, reemplazar la clave, revocar y generar constancias en PDF.
- **Solicitudes sin internet:**
  - importar un `.licreq` (máximo 64 KiB);
  - revisar la identidad del equipo;
  - elegir la licencia, aprobar o rechazar;
  - descargar el `.lic`.

  Importar no concede nada: solo la aprobación firma una revisión.
- **Recuperación de equipo averiado:** libera la plaza con autorización excepcional. La firma no demuestra que se hayan borrado copias del equipo anterior.

La API en línea solo atiende `POST` a `/v1/activations/challenge`, `/v1/activations`, `/v1/activations/refresh` y `/v1/activations/deactivate`, con JSON de hasta 16 KiB. **Herencia temporal (etapa 2):** el contrato de AD-EL POS sustituye estas rutas, sus cuerpos y los formatos `LIC-V1`/`LICREQ-V1`.

## 7. Mantenimiento diario

`deploy/adelpos-license-maintenance.sh` se instala como `/usr/local/sbin/adelpos-license-maintenance` y lo ejecuta el servicio y temporizador `adelpos-license-maintenance` (03:15 UTC, con `flock`). Hace tres cosas:

1. `bin/cleanup.php`: borra sesiones, contadores de intentos y desafíos vencidos. Conserva la historia comercial y la idempotencia.
2. `bin/audit-verify.php`: verifica la cadena de auditoría y escribe un anclaje (`.anchor.json`).
3. `bin/backup.php --action=create`: crea el respaldo cifrado (`.adelposbackup`) y su recibo (`.receipt.json`).

La tarea no borra respaldos antiguos. La retención y la copia externa las define el operador.

## 8. Respaldos y restauración

| Paso | Comando |
| --- | --- |
| Crear la clave de respaldo (0600; guardar otra copia fuera del servidor) | `php bin/backup.php --action=keygen --key-file=/ruta/recovery.key` |
| Crear un respaldo | `php bin/backup.php --action=create --key-file=/ruta/recovery.key --output=/ruta/FECHA.adelposbackup [--mysqldump=/ruta/mysqldump] [--mysql-options=/ruta/archivo-0600]` |
| Extraer y verificar en un directorio nuevo | `php bin/backup.php --action=extract --key-file=/ruta/recovery.key --archive=/ruta/FECHA.adelposbackup --directory=/ruta/nueva` |
| Crear la configuración de la base de destino, conservando las cinco claves | `php bin/recovery-config.php --directory=/ruta/extraida --output=/ruta/config.php --dsn='…' --user=… --signing-dir=/ruta/extraida/keys` |
| Importar en una base **vacía** | `php bin/restore-empty.php --directory=/ruta/extraida --target-config=/ruta/config.php [--mysql=/ruta/mysql]` |
| Verificar firmas, referencias y auditoría contra el último anclaje externo | `php bin/recovery-check.php --against=/ruta/anclaje.json` y `php bin/audit-verify.php --against=/ruta/anclaje.json` |

- **Contenido del respaldo:**
  - `database.sql` (`mysqldump --single-transaction`);
  - `config.json`, con las cinco claves;
  - las llaves privadas registradas del entorno;
  - `manifest.json`, con los SHA-256 y el anclaje.

  Todo va cifrado con Sodium secretstream, con el encabezado `ADELPOS-LICENSE-BACKUP-V1`.
- **Las utilidades de MySQL** se ejecutan sin variables de entorno y con `MYSQL_TEST_LOGIN_FILE` apuntando a una ruta inexistente, para no usar credenciales guardadas en `~/.mylogin.cnf`. Funciona con los clientes de MySQL 8.0.
- **`restore-empty`** rechaza un destino con tablas y nunca borra ni trunca. Un anclaje posterior al respaldo hace fallar la verificación hasta conciliar la historia faltante.
- **La extracción contiene secretos.** Se retira de forma controlada al terminar.

## 9. Mantenimiento programado y verificación previa

- **Modo mantenimiento.** Si existe `shared/maintenance.flag`, Nginx responde 503 a todas las rutas; en `/v1/` lo hace con JSON `TEMPORARY_UNAVAILABLE`. No detiene los comandos de `bin/`.
- **Página «Servicio no disponible».** Es de `public/index.php`, con la marca de AD-EL POS y una referencia para el registro. Aparece cuando la aplicación no puede atender; por ejemplo, si falta la base de datos.
- **Revisión previa.** `php bin/preflight.php` comprueba:
  - la versión de PHP, las extensiones y Argon2id;
  - MySQL 8;
  - que las migraciones estén completas y sus checksums coincidan;
  - en producción, que la cuenta de aplicación no pueda modificar ni borrar historia.

  Con `--require-signer` verifica además la llave de firma activa del entorno.

## 10. Paquete de despliegue

```sh
php bin/build-release.php --output=/ruta/existente/adelpos-license.tar.gz
```

- **Qué incluye:**
  - solo los archivos versionados en git de `bin/`, `src/`, `public/`, `templates/`, `database/` y `deploy/`;
  - `composer.json` y `composer.lock`;
  - `config/local.example.php`;
  - la documentación.
- **Qué deja fuera:** pruebas, `contracts/`, `vendor/`, `var/`, configuración local y archivos sin versionar.
- **Cuándo aborta:** si aparece `config/local.php`, algo bajo `var/`, un `.DS_Store` o un archivo `.key`/`.pem`.
- **Qué genera:** `RELEASE.json`, con el SHA-256 de cada archivo, y el `.sha256` del paquete. En el servidor se ejecuta `composer install --no-dev` con el `composer.lock` incluido.

## 11. Revisión de secretos

Antes de cada commit:

```sh
php bin/check-secrets.php
```

Detalles en [README.md](README.md). Falla con:
- valores en `DB_PASSWORD` o en claves `*_KEY`;
- llaves privadas PEM o Ed25519;
- archivos bajo `var/keys/`, o archivos `.key` o `.pem`.

## 12. Vigilancia

- **Registros de error:** PHP escribe en `shared/log/php-error.log`, con el prefijo `adelpos-license [<referencia>]`, o `adelpos-license API [<request_id>]` en la API. Nunca escribe cuerpos de petición, códigos comerciales ni parámetros SQL.
- **Revisar periódicamente:**
  - temporizadores (`adelpos-license-maintenance.timer`, `certbot.timer`);
  - espacio libre;
  - antigüedad de respaldos y anclajes externos;
  - caducidad TLS;
  - sincronización del reloj (NTP);
  - errores 5xx.
- **Sin internet:** un equipo desconectado conserva sus derechos según su última licencia firmada; ninguna revocación o transferencia lo alcanza al instante.
