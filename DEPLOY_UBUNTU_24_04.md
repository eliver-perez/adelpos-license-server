# AD-EL POS — Licencias: instalación en licensing.sicrat.com

> **Etapa 1:** esta guía solo se renombró para AD-EL POS (usuario, pool, rutas, servicios y dominio de pruebas). Su revisión completa y el despliegue en el VPS corresponden a la **etapa 5**. `licensing.sicrat.com` es el dominio de **pruebas** y usa llaves de prueba (contrato, sección 14).
>
> **Etapa 2:** la configuración y las llaves usan el entorno de licencias `test` (`--license-environment=test`), con `--env=production` para la seguridad del servidor.

Alternativa concreta Ubuntu 24.04 + Nginx; la operación general está en [OPERATIONS.md](OPERATIONS.md). Estos comandos los ejecuta el operador en el VPS. Versiones del VPS: Ubuntu 24.04, PHP 8.3.6, MySQL 8.0.46 y Nginx 1.24.0. El servidor requiere PHP 8.3+ y **MySQL 8**, no MariaDB. Go y Node no se instalan en el VPS.

Se usa `/srv/adelpos-license/current` para el código, `shared/` para configuración/claves persistentes y `backup/` para copias privadas. No instalar sobre el directorio de otro sitio. Los comandos de instalación inicial no se deben repetir sobre una instalación con datos; para actualizar, usar la sección 10.

## 0. Convivencia con el servidor de licencias de AIDEX

Este servidor convive en el mismo VPS con el servidor de licencias de AIDEX. Todo se mantiene separado; no se reutiliza ni se modifica nada del otro:

| Recurso | AD-EL POS — Licencias |
| --- | --- |
| Base de datos | `adelpos_license` (y bases de ensayo `adelpos_license_*`) |
| Usuarios de MySQL | `adelpos_license_app`, `adelpos_license_migrate` y cuentas temporales de restauración propias |
| Usuario del sistema | `adelpos-license` |
| Código, configuración, llaves y respaldos | `/srv/adelpos-license/` (`current/`, `shared/`, `backup/`), con su propia clave de respaldo |
| Pool de PHP-FPM y socket | `[adelpos-license]` en `/etc/php/8.3/fpm/pool.d/adelpos-license.conf`, `/run/php/adelpos-license.sock` |
| Nginx | `/etc/nginx/sites-available/adelpos-license-http` y `adelpos-license-https`; certificado propio |
| Tarea diaria | `adelpos-license-maintenance.service` y `.timer` |

No compartir cuentas, claves, directorios ni timers entre ambos servidores. Al aplicar cambios de Nginx o PHP-FPM, `nginx -t` y `php-fpm8.3 -t` validan también los sitios y pools del otro servidor: no recargar si alguno falla.

## 1. Preparar y copiar el paquete

Desde el proyecto en la Mac, con el PHP moderno y Composer de desarrollo disponibles:

```sh
mkdir -p var/releases
php bin/build-release.php --output="$PWD/var/releases/adelpos-license-1.0.tar.gz"
```

El comando genera `var/releases/adelpos-license-1.0.tar.gz` y su `.sha256`. El generador falla si el destino existe; para reconstruir, usar otro nombre. Solo empaqueta código, recursos, documentación y `composer.lock`: excluye `config/local.php`, privadas, `var/`, dependencias y pruebas. `RELEASE.json` registra hashes de cada archivo. Subir el paquete y su checksum a `/home/eliver/` mediante el método SSH habitual; reemplazar la IP por la real. No se necesita dar acceso SSH a esta sesión.

En el VPS, como `eliver` con `sudo`:

```sh
cd /home/eliver
sha256sum -c adelpos-license-1.0.tar.gz.sha256
mkdir adelpos-license-1.0-upload
tar -xzf adelpos-license-1.0.tar.gz -C adelpos-license-1.0-upload
```

## 2. Paquetes, DNS y reloj

El registro A de `licensing.sicrat.com` debe apuntar al VPS. Si publicas AAAA, configura también IPv6 y añade los correspondientes `listen [::]:80`/`listen [::]:443 ssl` a los bloques. Estos ejemplos escuchan IPv4. Permitir TCP 80/443 en el firewall local y del proveedor conservando el acceso SSH. No abrir MySQL a Internet.

```sh
sudo apt update
sudo apt install nginx mysql-server mysql-client php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-gd php8.3-curl php8.3-zip composer unzip ca-certificates certbot
php8.3 -v
mysql --version
sudo systemctl enable --now mysql php8.3-fpm nginx
timedatectl status
```

Comprobar sincronización NTP activa; TOTP, desafíos y vencimientos dependen del reloj. Si falta, configurar el servicio de sincronización de Ubuntu que utilice el VPS. Conservar los sitios y pools existentes. Los paquetes PHP 8.3 de Noble están en los [repositorios de Ubuntu](https://packages.ubuntu.com/search?keywords=php8.3-fpm); verificar la versión instalada con los comandos y con preflight. Las pruebas locales usan PHP 8.5.7 y MySQL 8.0.46; el VPS tiene PHP 8.3.6 y MySQL 8.0.46. La comprobación final se hace allí con `bin/preflight.php`.

## 3. Dependencias y permisos

```sh
cd /home/eliver/adelpos-license-1.0-upload/adelpos-license
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts --no-plugins
composer check-platform-reqs --no-dev
sudo adduser --system --group --home /srv/adelpos-license --no-create-home adelpos-license
sudo install -d -o root -g root -m 0755 /srv/adelpos-license /srv/adelpos-license/releases
sudo mv /home/eliver/adelpos-license-1.0-upload/adelpos-license /srv/adelpos-license/releases/1.0
sudo chown -R root:root /srv/adelpos-license/releases/1.0
sudo chmod -R u=rwX,go=rX /srv/adelpos-license/releases/1.0
sudo ln -s /srv/adelpos-license/releases/1.0 /srv/adelpos-license/current
sudo install -d -o adelpos-license -g adelpos-license -m 0700 /srv/adelpos-license/shared
sudo install -d -o adelpos-license -g adelpos-license -m 0700 /srv/adelpos-license/shared/keys /srv/adelpos-license/shared/log /srv/adelpos-license/shared/tmp
sudo install -d -o root -g root -m 0700 /srv/adelpos-license/backup /srv/adelpos-license/backup/archives
cd /srv/adelpos-license/current
```

El código será de solo lectura para FPM. El usuario `adelpos-license` es exclusivo de este pool y puede escribir en sus carpetas privadas. No usar permisos 777 ni ejecutar Composer como root.

## 4. Base MySQL, configuración y migraciones

Abrir `sudo mysql`. Confirmar `SELECT VERSION();` devuelve MySQL 8. En una instalación inicial:

```sql
CREATE DATABASE adelpos_license CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'adelpos_license_migrate'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
CREATE USER 'adelpos_license_app'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
GRANT ALL PRIVILEGES ON adelpos_license.* TO 'adelpos_license_migrate'@'localhost';
```

Guardar las dos contraseñas que imprime MySQL en el gestor de secretos. La cuenta de migración solo administra esta base; sus credenciales no se guardan en PHP-FPM. Usamos socket para que el DSN corresponda a las cuentas `localhost`.

```sh
sudo -u adelpos-license php8.3 bin/configure.php --env=production --license-environment=test --url=https://licensing.sicrat.com --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=adelpos_license;charset=utf8mb4' --user=adelpos_license_app --output=/srv/adelpos-license/shared/config.php --signing-dir=/srv/adelpos-license/shared/keys/test
sudo chown root:root /srv/adelpos-license/shared
sudo chmod 0755 /srv/adelpos-license/shared
sudo touch /srv/adelpos-license/shared/maintenance.flag
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/migrate.php --user=adelpos_license_migrate
php8.3 bin/grants.php --database=adelpos_license --user=adelpos_license_app > /home/eliver/adelpos-license-runtime-grants.sql
sudo mysql < /home/eliver/adelpos-license-runtime-grants.sql
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/preflight.php
```

Las contraseñas se introducen sin eco en una terminal. Se crean cinco claves independientes en `shared/config.php`, con permisos 0600; conservarlas en toda actualización. `shared/` pasa a ser propiedad de root y permite a Nginx comprobar el indicador de mantenimiento; `config.php`, claves, temporales y logs permanecen privados. Las migraciones de `database/migrations/` se aplican en orden: `001_esquema_inicial.sql` crea el esquema y solo el producto `adel_pos`, sin módulos, y las siguientes lo adaptan al contrato; el migrador conserva el control por checksum. `preflight` muestra el entorno de licencias (`test`).

Si deseas restringir el panel por red, editar `ADMIN_NETWORKS` dentro de `shared/config.php` con CIDR separados por comas. Vacío requiere igualmente autenticación/MFA, pero permite conexiones desde cualquier red. No configurar encabezados de proxy como sustituto de HTTPS; estos ejemplos suponen Nginx directamente expuesto.

## 5. Administrador y confianza del cliente

```sh
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/bootstrap-admin.php --login=administrador@example.com --name='Administración AD-EL POS'
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/signing-key.php --action=generate --user=adelpos_license_migrate
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/signing-key.php --action=list
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/signing-key.php --action=export
```

Reemplazar el login por el administrador real. Bootstrap pide una contraseña nueva dos veces y solo funciona en una base sin administradores. La CLI de firma crea una privada aleatoria y muestra únicamente la pública y sus metadatos; aún no la activa.

Cada comando muestra `Entorno de licencias: test`. `generate` asigna el identificador `adelpos-test-AAAAMM-1` y deja la privada en `shared/keys/test`.

`export` imprime las llaves públicas de licencias del entorno. Ese documento se incluye en las versiones de **desarrollo** de AD-EL POS por su canal autenticado; cómo lo incorpora AD-EL POS se define en su etapa de integración:

```json
{
    "environment": "test",
    "keys": [
        { "kid": "adelpos-test-202611-1", "environment": "test", "public_key": "REEMPLAZAR_POR_LA_PUBLICA_BASE64URL" }
    ]
}
```

Las llaves nuevas se agregan a las anteriores; no las reemplazan. `public_key` son 32 bytes Ed25519 en base64url sin `=`. Las versiones de producción de AD-EL POS solo confían en llaves del entorno `production` y nunca en las de este dominio de pruebas. Nunca distribuir privadas, aceptar una pública porque acompaña a un `.lic`, ni inventar una ruta pública `/v1/keys`.

Una vez comprobada la distribución:

```sh
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/signing-key.php --action=activate --kid=adelpos-test-202611-1 --reason='Pública incorporada y verificada en la distribución de AD-EL POS' --trust-confirmed --user=adelpos_license_migrate
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/preflight.php --require-signer
```

Para rotar, generar otro `kid`, distribuir la nueva pública **junto con las antiguas**, verificarla y solo entonces activarla. Las firmas antiguas siguen verificándose; no borrar privadas registradas que deban entrar en el respaldo.

## 6. PHP-FPM y certificado HTTPS

```sh
sudo install -m 0644 deploy/php-fpm-pool.conf.example /etc/php/8.3/fpm/pool.d/adelpos-license.conf
sudo php-fpm8.3 -t
sudo systemctl reload php8.3-fpm
sudo install -d -o root -g root -m 0755 /var/www/letsencrypt
sudo install -m 0644 deploy/nginx-http.conf.example /etc/nginx/sites-available/adelpos-license-http
sudo ln -s /etc/nginx/sites-available/adelpos-license-http /etc/nginx/sites-enabled/adelpos-license-http
sudo nginx -t
sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/letsencrypt -d licensing.sicrat.com
```

El bloque HTTP sirve únicamente desafíos ACME y redirige al dominio HTTPS. Mantenerlo para renovaciones. Certbot solicita el correo de contacto y sus condiciones; no cambiar los otros sitios de Nginx. El modo webroot requiere DNS correcto y puerto 80 accesible ([documentación de Ubuntu](https://ubuntu.com/server/docs/how-to/security/obtain-tls-certificates/)).

```sh
sudo install -m 0644 deploy/nginx.conf.example /etc/nginx/sites-available/adelpos-license-https
sudo ln -s /etc/nginx/sites-available/adelpos-license-https /etc/nginx/sites-enabled/adelpos-license-https
sudo nginx -t
sudo systemctl reload nginx
sudo install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
sudo sh -c 'printf "#!/bin/sh\nnginx -t && systemctl reload nginx\n" > /etc/letsencrypt/renewal-hooks/deploy/adelpos-license-nginx'
sudo chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/adelpos-license-nginx
sudo systemctl enable --now certbot.timer
sudo certbot renew --dry-run
```

No habilitar el bloque HTTPS antes de tener sus archivos de certificado. Su `map` va dentro del contexto `http` de Nginx, como ocurre con `sites-enabled` en Ubuntu. Publica solo `current/public`, limita JSON a 16 KiB y multipart a 96 KiB, sirve 413/503 del proxy como JSON para `/v1/` y preserva los errores JSON de PHP con `fastcgi_intercept_errors off`. PHP admite un `.licreq` de 64 KiB. El pool limpia el entorno y carga solo el `ADELPOS_LICENSE_CONFIG` persistente. Ver [Nginx/FastCGI](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_intercept_errors) y [configuración FPM](https://www.php.net/manual/en/install.fpm.configuration.php).

## 7. Respaldo y tarea diaria

```sh
sudo php8.3 bin/backup.php --action=keygen --key-file=/srv/adelpos-license/backup/recovery.key
sudo install -o root -g root -m 0750 deploy/adelpos-license-maintenance.sh /usr/local/sbin/adelpos-license-maintenance
sudo install -o root -g root -m 0644 deploy/adelpos-license-maintenance.service /etc/systemd/system/adelpos-license-maintenance.service
sudo install -o root -g root -m 0644 deploy/adelpos-license-maintenance.timer /etc/systemd/system/adelpos-license-maintenance.timer
sudo systemctl daemon-reload
sudo systemctl start adelpos-license-maintenance.service
sudo systemctl status adelpos-license-maintenance.service --no-pager
sudo systemctl enable --now adelpos-license-maintenance.timer
sudo systemctl list-timers adelpos-license-maintenance.timer
```

El script usa `/usr/bin/php` mediante PATH de systemd; confirmar que es PHP 8.3+ con las mismas extensiones. Si hay varias versiones, cambiar las tres invocaciones a `php8.3` en el script instalado. Ejecuta cleanup, exporta un anclaje y crea copia cifrada diaria a las 03:15 UTC, con exclusión `flock`. El proceso root puede leer la clave de recuperación; PHP-FPM no puede atravesar `backup/`. Las credenciales SQL usadas son las de aplicación, con SELECT suficiente para el dump configurado. Se puede usar `backup.php --mysql-options=/ruta/archivo0600` para una cuenta de respaldo con SELECT sobre esta base; los secretos nunca se pasan en argumentos.

El archivo `.adelposbackup` contiene SQL consistente, configuración con las cinco claves, privadas registradas del entorno y manifiesto de hashes/anclaje. Usa [Sodium secretstream](https://doc.libsodium.org/secret-key_cryptography/secretstream), autenticación por bloques y cierre final obligatorio. `mysqldump --single-transaction` obtiene la copia InnoDB; la aplicación mantiene bloqueados los metadatos de firma y la cabecera de auditoría durante la copia para coordinar historia y claves. No ejecutar DDL/migraciones en paralelo. En una base grande, este bloqueo puede retrasar operaciones: medir su duración ([referencia MySQL](https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html)).

Conservar **otra copia de `recovery.key` fuera del VPS**, separada de las copias cifradas. Copiar `.adelposbackup`, `.receipt.json` y `.anchor.json` a almacenamiento externo protegido, bajo una credencial que no pueda borrar la retención histórica. La entrega genera estos archivos localmente; no configura un proveedor externo ni acredita que ya estén fuera del servidor. Un anclaje guardado únicamente junto a la BD no detecta una restauración conjunta antigua.

La tarea no elimina respaldos: revisar espacio y definir retención después de verificar copias externas (punto de partida: 30 diarias y 12 mensuales). Una interrupción abrupta puede dejar staging `.adelpos-license-backup-*` con datos sin cifrar, protegido 0700 dentro de `backup/archives`; revisar después de un fallo y limpiar solo el staging de ese intento tras investigar. En caso de recuperación, la extracción también contiene secretos y debe retirarse de forma controlada cuando deje de necesitarse.

La copia diaria tiene hasta **24 horas de pérdida potencial**. El objetivo propuesto de 15 minutos requiere archivar binlogs de forma independiente y ensayar recuperación a un punto en el tiempo; eso no está configurado por este paquete. RTO de cuatro horas es un objetivo por medir, no una garantía.

## 8. Verificación antes de habilitar

Con mantenimiento activo:

```sh
curl -i https://licensing.sicrat.com/v1/activations/challenge -H 'Content-Type: application/json' --data '{}'
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/preflight.php --require-signer
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/recovery-check.php
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/audit-verify.php
```

La primera llamada debe devolver 503 JSON con `TEMPORARY_UNAVAILABLE` y UUID de correlación. Ensayar extracción/restauración según la sección 9 en otra base vacía, y comprobar que el respaldo inicial y su clave ya tienen copia externa. Después:

```sh
sudo rm /srv/adelpos-license/shared/maintenance.flag
curl -sS -D - -o /dev/null https://licensing.sicrat.com/login
curl -i https://licensing.sicrat.com/v1/activations/challenge -H 'Content-Type: application/json' --data '{}'
curl -i https://licensing.sicrat.com/.env
curl -i https://licensing.sicrat.com/index.php
```

`/login` debe cargar el panel; el POST vacío de API debe devolver 400 JSON sin cookie administrativa. Los dos archivos directos se rechazan (403/404). Comprobar cookie `__Host-…` con Secure, HttpOnly y SameSite=Lax, HSTS y certificado válido en el navegador. Entrar con el administrador y enrolar TOTP; guardar los códigos de recuperación una sola vez. No publicar `phpinfo()`.

Con una licencia e instalación de prueba de producción, verificar activar → reiniciar cliente → refresh → desactivar → activar otra instalación; después importar/aprobar `.licreq` y cargar su `.lic`. No usar claves deterministas de los tests. Confirmar rechazo de una segunda plaza simultánea. La retirada offline entrega JWS `revoked` y conserva la licencia comercial `issued`; la respuesta online mantiene `status:deactivated`.

El comportamiento del servidor del restaurante ante la revisión de cierre (`revoked`) se define en la etapa de integración de AD-EL POS, según el contrato.

## 9. Restaurar sin sobrescribir la base original

En un ensayo, mantener el destino sin tráfico. En un incidente real, crear `shared/maintenance.flag`, detener `adelpos-license-maintenance.timer`, cerrar procesos/CLI que emiten o cambian licencias y dejar terminar solicitudes en curso antes de restaurar. El indicador bloquea HTTP, no otros comandos CLI. No quitar mantenimiento hasta conciliar operaciones posteriores a la copia con anclajes externos/binlogs. Un respaldo antiguo puede contener plazas o contadores antiguos aunque todas sus firmas sean válidas.

Trabajar como root en una sesión privada (`sudo -i`); los nombres de copia de abajo se sustituyen por los reales:

```sh
cd /srv/adelpos-license/current
install -d -m 0700 /srv/adelpos-license/recovery
php8.3 bin/backup.php --action=extract --key-file=/srv/adelpos-license/backup/recovery.key --archive=/srv/adelpos-license/backup/archives/FECHA.adelposbackup --directory=/srv/adelpos-license/recovery/ensayo1
mysql
```

En MySQL crear **otra base** y cuenta temporal de importación. No ejecutar migraciones antes de importar:

```sql
CREATE DATABASE adelpos_license_restore_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'adelpos_license_restore'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
GRANT ALL PRIVILEGES ON adelpos_license_restore_check.* TO 'adelpos_license_restore'@'localhost';
```

Guardar la contraseña temporal y salir de MySQL. Generar configuración recuperada preservando las cinco claves:

```sh
php8.3 bin/recovery-config.php --directory=/srv/adelpos-license/recovery/ensayo1 --output=/srv/adelpos-license/recovery/import.php --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=adelpos_license_restore_check;charset=utf8mb4' --user=adelpos_license_restore --signing-dir=/srv/adelpos-license/recovery/ensayo1/keys
php8.3 bin/restore-empty.php --directory=/srv/adelpos-license/recovery/ensayo1 --target-config=/srv/adelpos-license/recovery/import.php
php8.3 bin/grants.php --database=adelpos_license_restore_check --user=adelpos_license_app > /srv/adelpos-license/recovery/grants.sql
mysql < /srv/adelpos-license/recovery/grants.sql
php8.3 bin/recovery-config.php --directory=/srv/adelpos-license/recovery/ensayo1 --output=/srv/adelpos-license/recovery/runtime.php --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=adelpos_license_restore_check;charset=utf8mb4' --user=adelpos_license_app --signing-dir=/srv/adelpos-license/recovery/ensayo1/keys
env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/recovery/runtime.php php8.3 bin/preflight.php --require-signer
env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/recovery/runtime.php php8.3 bin/recovery-check.php --against=/RUTA/AL/ULTIMO-ANCLAJE-EXTERNO.json
env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/recovery/runtime.php php8.3 bin/audit-verify.php --against=/RUTA/AL/ULTIMO-ANCLAJE-EXTERNO.json
```

En un servidor nuevo, crear también `adelpos_license_app` con contraseña aleatoria antes de sus GRANT. `restore-empty` rechaza cualquier tabla existente y no borra ni trunca tablas. Un error durante la importación puede dejar una copia parcial: conservarla para diagnóstico y crear otro destino vacío para reintentar. `recovery-config` no ejecuta el JSON del respaldo como código y no crea claves nuevas. La configuración de importación tiene permisos SQL amplios y no debe usarse como runtime/FPM.

Si el respaldo proviene de una versión con menos migraciones, aplicar las pendientes con `bin/migrate.php --user=adelpos_license_migrate` y la configuración runtime recuperada, después de importar y antes de preflight. Conservar los checksums de los SQL originales.

Los verificadores comprueban auditoría, anclaje, firmas JWS históricas, referencias de activación, contadores globales, una plaza activa y evidencia offline cifrada. Un anclaje posterior al respaldo debe fallar hasta recuperar la historia faltante. Para un ensayo se puede comprobar además el anclaje de la propia copia, pero eso no demuestra continuidad hasta hoy. Conservar también JWS posteriores aportados por clientes para conciliación; no emitir números de revisión inventados ni rehabilitar automáticamente plazas. Si la historia no puede reconstruirse, mantener suspendida la emisión y resolver administrativamente antes de reabrir.

Un ensayo termina sin cambiar `shared/config.php`. Para convertir una recuperación conciliada en producción, con mantenimiento activo: instalar sus claves en una nueva carpeta privada persistente de `shared/` propiedad de `adelpos-license` (0700/0600), generar otra configuración runtime con ese directorio y el DSN recuperado, verificarla de nuevo, sustituir `shared/config.php` conservando propiedad `adelpos-license:adelpos-license`/0600, recargar FPM y probar. No volver a ejecutar configure/bootstrap/generate. Las cuentas MySQL, GRANT, Nginx/TLS, paquetes y timers no forman parte del dump y se preparan con esta guía. Retirar la cuenta de importación y acceso temporal al ensayo al cerrar la recuperación; la herramienta no los borra automáticamente.

## 10. Actualizaciones y operación

Preparar cada versión en `releases/<version>` con sus dependencias y código root/solo lectura. Conservar `shared/` y `backup/`. Crear mantenimiento, detener el timer mientras dure el despliegue, hacer respaldo previo, ejecutar migraciones desde **el nuevo código** con `ADELPOS_LICENSE_CONFIG` persistente/cuenta de migración y aplicar los GRANT actualizados. Verificar preflight, auditoría y recuperación desde esa versión. Cambiar `current` al directorio aprobado y recargar `php8.3-fpm` (OPcache no revisa timestamps). Ejecutar `nginx -t` si cambió el proxy. Quitar mantenimiento tras las pruebas y reactivar el timer.

Si falla una migración, no restaurar silenciosamente un dump antiguo ni editar su checksum: conciliar el DDL pendiente. Volver a código anterior solo cuando su esquema sea compatible. Conservar la versión anterior para diagnóstico.

```sh
sudo journalctl -u php8.3-fpm -u adelpos-license-maintenance.service --since today
sudo tail -n 100 /srv/adelpos-license/shared/log/php-error.log
sudo nginx -t
sudo systemctl list-timers certbot.timer adelpos-license-maintenance.timer
```

Vigilar fallos de timers, espacio libre, antigüedad de copias/anclajes externos, caducidad TLS, NTP y errores 5xx. Configurar rotación del log PHP privado y los logs Nginx existentes del VPS. No habilitar registro de cuerpos, claves comerciales ni parámetros SQL. `cleanup` elimina datos temporales vencidos, pero conserva historial, decisiones e idempotencia. Los clientes offline conservan derechos según su último JWS: ninguna transferencia garantiza revocación instantánea de un equipo desconectado.
