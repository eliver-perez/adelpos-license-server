# Despliegue en licensing.sicrat.com (Ubuntu 24.04, junto a AIDEX)

Guía para instalar el servidor de licencias de AD-EL POS en el VPS de pruebas, **clonando el repositorio** en una etiqueta de versión. El VPS ya tiene **Nginx 1.24.0, PHP 8.3.6 y MySQL 8.0.46**, y en él vive el servidor de licencias de **AIDEX**: nada de esta guía toca sus archivos, su base, sus usuarios ni su pool.

`licensing.sicrat.com` es solo para pruebas: funciona en modo de producción (`APP_ENV=production`) y firma con el entorno **`test`** (contrato §3).

**Cómo leer esta guía.** Cada paso trae el comando exacto, **lo que debes ver** y **qué hacer si falla**. Los comandos se escriben en el VPS con tu usuario con `sudo`. Donde dice `TU_CORREO`, `PROPIETARIO/REPOSITORIO` o `adelpos-test-AAAAMM-1`, cambia el texto por el tuyo. No ejecutes los pasos 2 a 8 sobre una instalación que ya tiene datos: para actualizar está el paso 14.

## Resumen de rutas y nombres

| Qué | Dónde o cómo se llama |
| --- | --- |
| Código (clon del repositorio, en una etiqueta) | `/srv/adelpos-license/app` (de `root`, solo lectura para los demás) |
| Configuración | `/srv/adelpos-license/shared/config.php` (`adelpos-license`, 0600) |
| Llaves de firma | `/srv/adelpos-license/shared/keys/test` (`adelpos-license`, 0700 y 0600) |
| Registro de PHP y temporales | `/srv/adelpos-license/shared/log` y `shared/tmp` (`adelpos-license`, 0700) |
| Aviso de mantenimiento | `/srv/adelpos-license/shared/maintenance.flag` (si existe, el sitio responde 503) |
| Respaldos y clave de recuperación | `/srv/adelpos-license/backup` (`root`, 0700) |
| Usuario del sistema | `adelpos-license` (sin inicio de sesión) |
| Base y cuentas de MySQL | `adelpos_license`; `adelpos_license_migrate` (migraciones y llaves) y `adelpos_license_app` (la aplicación) |
| Pool de PHP-FPM | `[adelpos-license]` en `/etc/php/8.3/fpm/pool.d/adelpos-license.conf`, socket `/run/php/adelpos-license.sock` |
| Sitio de Nginx | `/etc/nginx/sites-available/adelpos-license` |
| Tarea diaria | `adelpos-license-maintenance.service` y `.timer` |
| Llave de despliegue | `/root/.ssh/adelpos_license_deploy`, solo lectura en el remoto |

---

## 1. Requisitos previos

### 1.1 DNS, versiones y reloj

```sh
dig +short licensing.sicrat.com
ip -4 -brief address show scope global
nginx -v
php8.3 -v
mysql --version
systemctl is-active nginx php8.3-fpm mysql
timedatectl show -p NTPSynchronized
```

**Debes ver:** la IP del primer comando entre las del segundo; `nginx/1.24.0`; `PHP 8.3.6`; `8.0.46`; tres veces `active`; y `NTPSynchronized=yes`.

**Si falla:** si el DNS no apunta al VPS, corrígelo con tu proveedor de dominio y espera a que se propague (Certbot lo necesita en el paso 10). Sin `NTPSynchronized=yes`, activa la sincronización (`sudo timedatectl set-ntp true`): los códigos de verificación en dos pasos, los desafíos y los vencimientos dependen del reloj.

### 1.2 Remoto privado y llave de despliegue

Necesitas:

- el repositorio en un **remoto privado** (por ejemplo, GitHub) con la etiqueta **`v1.0.0`**;
- acceso a la configuración del repositorio para agregar una **llave de despliegue de solo lectura** (paso 3).

### 1.3 Extensiones de PHP y herramientas

```sh
for extension in sodium pdo_mysql mbstring gd xmlwriter dom iconv; do
    php8.3 -m | grep -qix "$extension" && echo "OK      $extension" || echo "FALTA   $extension"
done
php8.3 -r 'echo in_array("argon2id", password_algos(), true) ? "OK      argon2id\n" : "FALTA   argon2id\n";'
composer --version
git --version
```

**Debes ver:** `OK` en las ocho líneas, `Composer version 2.…` y `git version …`.

**Si falta algo:** instala solo lo que falte; el paquete de cada extensión es:

| Extensión | Paquete |
| --- | --- |
| `pdo_mysql` | `php8.3-mysql` |
| `mbstring` | `php8.3-mbstring` |
| `gd` | `php8.3-gd` |
| `xmlwriter`, `dom` | `php8.3-xml` |
| `sodium`, `iconv`, Argon2id | vienen con `php8.3-common` |
| Composer y Git | `composer`, `unzip`, `git` |

```sh
sudo apt update
sudo apt install php8.3-mysql php8.3-mbstring php8.3-gd php8.3-xml php8.3-curl php8.3-zip unzip composer git
sudo systemctl reload php8.3-fpm
```

`reload` no corta las peticiones en curso, tampoco las de AIDEX. Vuelve a correr la comprobación.

---

## 2. Usuario del sistema y carpetas

```sh
sudo adduser --system --group --no-create-home --home /nonexistent --shell /usr/sbin/nologin adelpos-license
sudo install -d -o root -g root -m 0755 /srv/adelpos-license
sudo install -d -o root -g root -m 0755 /srv/adelpos-license/shared
sudo install -d -o adelpos-license -g adelpos-license -m 0700 /srv/adelpos-license/shared/keys
sudo install -d -o adelpos-license -g adelpos-license -m 0700 /srv/adelpos-license/shared/log
sudo install -d -o adelpos-license -g adelpos-license -m 0700 /srv/adelpos-license/shared/tmp
sudo install -d -o root -g root -m 0700 /srv/adelpos-license/backup
sudo install -d -o root -g root -m 0700 /srv/adelpos-license/backup/archives
sudo ls -la /srv/adelpos-license /srv/adelpos-license/shared
```

**Debes ver:** `shared` y `backup` de `root`; `keys`, `log` y `tmp` de `adelpos-license` con `drwx------`.

**Por qué así:** la configuración, las llaves, los registros y los respaldos quedan **fuera del clon**, que se puede volver a clonar o cambiar de versión sin tocarlos. `shared/` es de `root` y legible (0755) para que Nginx compruebe el aviso de mantenimiento; lo privado está dentro, en carpetas 0700. PHP-FPM (`adelpos-license`) no puede entrar a `backup/`.

**Si falla:** si `adduser` dice que el usuario ya existe, comprueba con `id adelpos-license` que es un usuario de sistema sin shell; no lo reutilices si pertenece a otra cosa.

---

## 3. Clonar e instalar dependencias

### 3.1 Llave de despliegue de solo lectura

```sh
sudo install -d -o root -g root -m 0700 /root/.ssh
sudo ssh-keygen -t ed25519 -N '' -C 'adelpos-license despliegue (solo lectura)' -f /root/.ssh/adelpos_license_deploy
sudo cat /root/.ssh/adelpos_license_deploy.pub
```

Copia la línea `ssh-ed25519 …` y agrégala en el remoto como **llave de despliegue sin permiso de escritura** (en GitHub: el repositorio → Settings → Deploy keys → Add deploy key, sin marcar «Allow write access»).

```sh
sudo tee -a /root/.ssh/config >/dev/null <<'EOF'
Host github-adelpos-license
    HostName github.com
    User git
    IdentityFile /root/.ssh/adelpos_license_deploy
    IdentitiesOnly yes
EOF
sudo chmod 0600 /root/.ssh/config
sudo ssh -T github-adelpos-license
```

**Debes ver:** `Hi PROPIETARIO/REPOSITORIO! You've successfully authenticated, but GitHub does not provide shell access.` (la primera vez, acepta la huella de `github.com` con `yes`).

**Si falla:** `Permission denied (publickey)` quiere decir que la llave no está en el repositorio o que se pegó incompleta.

### 3.2 Clonar en la etiqueta

```sh
sudo git clone git@github-adelpos-license:PROPIETARIO/REPOSITORIO.git /srv/adelpos-license/app
sudo git -C /srv/adelpos-license/app checkout --detach v1.0.0
sudo git -C /srv/adelpos-license/app describe --tags
```

**Debes ver:** `v1.0.0`. Se despliegan **etiquetas**, nunca la rama `main`.

### 3.3 Dependencias de producción

```sh
cd /srv/adelpos-license/app
sudo env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-plugins
sudo env COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev
sudo chmod -R go-w /srv/adelpos-license/app
```

**Debes ver:** `Generating optimized autoload files` y, en la comprobación, `success` en cada requisito.

**Por qué como `root`:** la tarea diaria corre como `root` y ejecuta este código, así que solo `root` puede modificarlo. `--no-scripts --no-plugins` impide que Composer ejecute nada de los paquetes. **No se instalan** las dependencias de desarrollo ni Node: las pruebas no corren en el VPS.

**Si falla:** `check-platform-reqs` dice qué extensión falta; vuelve al paso 1.3.

---

## 4. MySQL: base y cuentas propias

```sh
sudo mysql
```

```sql
SELECT VERSION();
CREATE DATABASE adelpos_license CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'adelpos_license_migrate'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
CREATE USER 'adelpos_license_app'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
GRANT ALL PRIVILEGES ON adelpos_license.* TO 'adelpos_license_migrate'@'localhost';
EXIT;
```

**Debes ver:** `8.0.46-…` y, después de cada `CREATE USER`, una tabla con la contraseña generada. **Guarda las dos contraseñas en tu gestor de contraseñas** en ese momento: MySQL no las vuelve a mostrar y no se escriben en el repositorio.

- La cuenta de **migración** solo se usa en la terminal (migraciones y llaves de firma); su contraseña nunca se guarda en el VPS.
- La cuenta de la **aplicación** recibe sus permisos exactos en el paso 6.
- La base y las cuentas de AIDEX no se tocan.

**Si falla:** si la base ya existe, **detente**: puede ser una instalación anterior con datos. Revisa con `SHOW DATABASES;` antes de seguir.

---

## 5. Configuración

```sh
cd /srv/adelpos-license/app
sudo php8.3 bin/configure.php --env=production --license-environment=test --url=https://licensing.sicrat.com \
    --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=adelpos_license;charset=utf8mb4' \
    --user=adelpos_license_app --output=/srv/adelpos-license/shared/config.php \
    --signing-dir=/srv/adelpos-license/shared/keys/test
sudo chown adelpos-license:adelpos-license /srv/adelpos-license/shared/config.php
sudo ls -l /srv/adelpos-license/shared/config.php
sudo touch /srv/adelpos-license/shared/maintenance.flag
```

El comando pide la contraseña de `adelpos_license_app` sin mostrarla.

**Debes ver:** `Configuración y claves independientes creadas. Entorno de licencias: test.` y el archivo con `-rw------- 1 adelpos-license adelpos-license`.

- `APP_ENV=production`, `LICENSE_ENVIRONMENT=test`, el dominio y la ruta de las llaves quedan en el archivo, con **cinco claves aleatorias** (`MFA_KEY`, `CREDENTIAL_KEY`, `AUDIT_KEY`, `SESSION_KEY` y `OPERATION_KEY`). **No las cambies nunca:** sin ellas no se leen los datos cifrados. El respaldo diario las incluye.
- El aviso de mantenimiento queda puesto hasta el paso 13.
- `ADMIN_NETWORKS` queda vacío: el panel acepta cualquier red, siempre con contraseña y verificación en dos pasos. Para limitarlo, escribe en ese valor las redes permitidas en formato CIDR, separadas por comas.

**Si falla:** «La configuración ya existe» quiere decir que hay una instalación anterior: no la sobrescribas.

---

## 6. Migraciones y permisos

```sh
cd /srv/adelpos-license/app
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php \
    php8.3 bin/migrate.php --user=adelpos_license_migrate
php8.3 bin/grants.php --database=adelpos_license --user=adelpos_license_app | sudo mysql
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/preflight.php
```

`migrate.php` pide la contraseña de la cuenta de migración.

**Debes ver:** `Aplicada: 001_esquema_inicial.sql` hasta `Aplicada: 009_indice_de_cobros.sql`, `Esquema actualizado.`; `grants.php` no imprime nada (sus `GRANT` van directo a MySQL); y `preflight` termina con `PHP 8.3.6 · MySQL 8.0.46-… · configuración y extensiones verificadas.`

**Si falla:**

- `Access denied` en la migración: la contraseña no es la de `adelpos_license_migrate`.
- `preflight` dice «permisos de modificación excesivos»: la cuenta de la aplicación tiene más permisos de los que da `grants.php` (por ejemplo, alguien le dio `ALL`). Quítaselos con `REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'adelpos_license_app'@'localhost';` y repite `grants.php`.
- «Falta aplicar una migración o su archivo cambió»: el clon no está en la etiqueta o tiene cambios locales (`sudo git -C /srv/adelpos-license/app status`).

---

## 7. Primer administrador

```sh
cd /srv/adelpos-license/app
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php \
    php8.3 bin/bootstrap-admin.php --login=TU_CORREO --name='Administración AD-EL POS'
```

Pide la contraseña nueva dos veces (12 caracteres o más) y solo funciona en una base sin administradores.

**Debes ver:** la confirmación de la cuenta creada.

La **verificación en dos pasos** se configura al entrar por primera vez al panel (paso 13): el panel muestra un código QR para tu aplicación de autenticación y después **ocho códigos de recuperación** de un solo uso. Guárdalos en tu gestor de contraseñas. Si algún día pierdes el teléfono y los códigos, `bin/recover-admin.php` restablece el acceso desde la terminal (OPERATIONS.md, sección 4).

---

## 8. Llave de firma de prueba

```sh
cd /srv/adelpos-license/app
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php \
    php8.3 bin/signing-key.php --action=generate --user=adelpos_license_migrate
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php \
    php8.3 bin/signing-key.php --action=list
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php \
    php8.3 bin/signing-key.php --action=export | sudo tee /srv/adelpos-license/shared/llaves-publicas-test.json
```

**Debes ver:** cada comando dice `Entorno de licencias: test`; `generate` asigna un identificador como `adelpos-test-202611-1` (en `staged`); `export` imprime un JSON con `environment: "test"` y la llave pública (`public_key`), sin nada privado.

**Guarda la exportación para la integración de AD-EL POS:** es pública. Cópiala a tu computadora y agrégala al proyecto de AD-EL POS, donde sus versiones de **desarrollo** guardan las llaves de prueba en las que confían (`docs/INTEGRACION_ADEL_POS.md`, «Llaves públicas»):

```sh
scp TU_USUARIO@licensing.sicrat.com:/srv/adelpos-license/shared/llaves-publicas-test.json .
```

Con la pública ya guardada para AD-EL POS, actívala (cambia el identificador por el que mostró `generate`):

```sh
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php \
    php8.3 bin/signing-key.php --action=activate --kid=adelpos-test-AAAAMM-1 \
    --reason='Pública exportada para la integración de AD-EL POS' --trust-confirmed --user=adelpos_license_migrate
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php \
    php8.3 bin/preflight.php --require-signer
```

**Debes ver:** `Llave firmante de licencias del entorno test verificada.`

**Si falla:** sin una llave activa, la API responde 503 `TEMPORARY_UNAVAILABLE` al activar; el panel sigue funcionando. Las versiones de producción de AD-EL POS **nunca** deben confiar en esta llave.

---

## 9. PHP-FPM: un pool propio

```sh
sudo install -o root -g root -m 0644 /srv/adelpos-license/app/deploy/php-fpm-pool.conf.example \
    /etc/php/8.3/fpm/pool.d/adelpos-license.conf
sudo php-fpm8.3 -t
sudo systemctl reload php8.3-fpm
ls -l /run/php/adelpos-license.sock
```

**Debes ver:** `test is successful` y el socket `srw-rw---- 1 adelpos-license www-data`.

El pool corre como `adelpos-license`, separado del de AIDEX; limpia el entorno y solo recibe `ADELPOS_LICENSE_CONFIG`; guarda su registro en `shared/log/php-error.log`; y deja la caché de código fija (`opcache.validate_timestamps = off`), por eso **cada actualización termina recargando PHP-FPM**.

**Si falla:** `php-fpm8.3 -t` revisa **todos** los pools, también el de AIDEX: si el error es de otro archivo, no recargues y revisa ese archivo; si es del nuevo, corrígelo y repite.

---

## 10. Nginx y certificado

### 10.1 Certificado con Certbot

Primero un sitio temporal que solo atiende la validación de Certbot por HTTP:

```sh
sudo install -d -o root -g root -m 0755 /var/www/letsencrypt
sudo tee /etc/nginx/sites-available/adelpos-license-certbot >/dev/null <<'EOF'
server {
    listen 80;
    server_name licensing.sicrat.com;
    server_tokens off;
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
        default_type text/plain;
        try_files $uri =404;
    }
    location / {
        return 404;
    }
}
EOF
sudo ln -s /etc/nginx/sites-available/adelpos-license-certbot /etc/nginx/sites-enabled/adelpos-license-certbot
sudo nginx -t && sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/letsencrypt -d licensing.sicrat.com
sudo rm /etc/nginx/sites-enabled/adelpos-license-certbot /etc/nginx/sites-available/adelpos-license-certbot
```

**Debes ver:** `Successfully received certificate.` con los archivos en `/etc/letsencrypt/live/licensing.sicrat.com/`.

**Si falla:** Certbot necesita que el DNS apunte al VPS y el puerto 80 abierto. Si `nginx -t` falla, no recargues: el mensaje dice qué archivo y qué línea.

### 10.2 El sitio definitivo

```sh
sudo install -o root -g root -m 0644 /srv/adelpos-license/app/deploy/nginx.conf.example \
    /etc/nginx/sites-available/adelpos-license
sudo ln -s /etc/nginx/sites-available/adelpos-license /etc/nginx/sites-enabled/adelpos-license
sudo nginx -t
sudo systemctl reload nginx
```

**Debes ver:** `syntax is ok` y `test is successful`.

El sitio (`deploy/nginx.conf.example`) sigue el patrón de AIDEX: raíz en `public/` del clon, `server_tokens off`, TLS 1.2 y 1.3, cabeceras de seguridad, el socket del pool propio, HTTP redirigido a HTTPS, y **límites de intentos** en la API (60 por minuto por IP, ráfagas de 20) y en el inicio de sesión (10 envíos por minuto, ráfagas de 5), con el JSON del contrato. Lo que declara fuera de los `server{}` lleva el prefijo `adelpos_` para no chocar con AIDEX.

**Si falla:** `nginx -t` revisa **todos** los sitios. Si dice `limit_req_zone "adelpos_…" is already bound` o algo parecido, otro sitio usa el mismo nombre: no recargues. Si dice que no encuentra el certificado, vuelve al paso 10.1. **No toques los archivos de AIDEX.**

### 10.3 Renovación del certificado

```sh
sudo install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nnginx -t && systemctl reload nginx\n' | sudo tee /etc/letsencrypt/renewal-hooks/deploy/adelpos-license-nginx >/dev/null
sudo chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/adelpos-license-nginx
systemctl is-enabled certbot.timer
sudo certbot renew --dry-run --cert-name licensing.sicrat.com
```

**Debes ver:** `enabled` y `Congratulations, all simulated renewals succeeded`. La renovación usa el bloque HTTP del sitio definitivo.

---

## 11. Tarea diaria: limpieza, cambios programados, anclaje de auditoría y respaldo

```sh
cd /srv/adelpos-license/app
sudo php8.3 bin/backup.php --action=keygen --key-file=/srv/adelpos-license/backup/recovery.key
sudo install -o root -g root -m 0750 deploy/adelpos-license-maintenance.sh /usr/local/sbin/adelpos-license-maintenance
sudo install -o root -g root -m 0644 deploy/adelpos-license-maintenance.service /etc/systemd/system/
sudo install -o root -g root -m 0644 deploy/adelpos-license-maintenance.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl start adelpos-license-maintenance.service
sudo systemctl status adelpos-license-maintenance.service --no-pager
sudo systemctl enable --now adelpos-license-maintenance.timer
systemctl list-timers adelpos-license-maintenance.timer
```

**Debes ver:** el servicio con `status=0/SUCCESS`; en `backup/archives`, un `.adelposbackup`, su `.receipt.json` y un `.anchor.json`; y el timer con su próxima ejecución (todos los días a las 03:15 UTC, con hasta 5 minutos al azar).

La tarea limpia sesiones y desafíos vencidos, aplica los cambios de plan programados cuyo momento llegó (firma su revisión), guarda el anclaje de la cadena de auditoría y crea el respaldo cifrado. No borra respaldos.

**Comprobar su última ejecución:**

```sh
systemctl list-timers adelpos-license-maintenance.timer
systemctl show adelpos-license-maintenance.service -p ExecMainExitTimestamp -p ExecMainStatus
sudo journalctl -u adelpos-license-maintenance.service --since '2 days ago' --no-pager
sudo ls -lt /srv/adelpos-license/backup/archives | head
```

`ExecMainStatus=0` es una ejecución correcta; `bin/verify-deployment.php` (paso 13) también lo revisa.

**Si falla:** `journalctl` muestra el mensaje. Los más comunes: la clave de recuperación no existe (repite `keygen`), o el servicio no encuentra `php8.3` (la tarea lo llama por ese nombre).

---

## 12. Respaldos

**Dónde quedan:** `/srv/adelpos-license/backup/archives/`, uno por día: `FECHA.adelposbackup` (base, configuración con sus cinco claves y llaves de firma, cifrados), `FECHA.receipt.json` y `FECHA.anchor.json`. La copia diaria puede perder hasta **24 horas**.

### 12.1 La clave de recuperación, aparte

```sh
sudo cat /srv/adelpos-license/backup/recovery.key
```

Guarda ese texto en tu **gestor de contraseñas**, en una entrada distinta de la de los respaldos. Sin esa clave, ningún respaldo se puede abrir; con ella y un respaldo, alguien tendría todo. Nunca la guardes junto a las copias.

### 12.2 Copiar los respaldos fuera del VPS

En el VPS, a una carpeta de tu usuario:

```sh
install -d -m 0700 ~/adelpos-license-respaldos
sudo rsync -a --ignore-existing /srv/adelpos-license/backup/archives/ ~/adelpos-license-respaldos/
sudo chown -R "$USER": ~/adelpos-license-respaldos
```

Y en tu computadora:

```sh
rsync -av --ignore-existing TU_USUARIO@licensing.sicrat.com:adelpos-license-respaldos/ ~/Respaldos/adelpos-license/
```

Los `.adelposbackup` están cifrados. Conserva los `.anchor.json` también fuera del VPS: comparan la auditoría de una restauración. La tarea no borra respaldos: revisa el espacio (`df -h /srv`) y define cuántos conservar (por ejemplo, 30 diarios y 12 mensuales) después de comprobar las copias externas.

### 12.3 Restauración de prueba

En una base **nueva y vacía**, sin tocar la de trabajo (cambia `FECHA` por la de un respaldo real):

```sh
sudo install -d -o root -g root -m 0700 /srv/adelpos-license/recovery
cd /srv/adelpos-license/app
sudo php8.3 bin/backup.php --action=extract --key-file=/srv/adelpos-license/backup/recovery.key \
    --archive=/srv/adelpos-license/backup/archives/FECHA.adelposbackup --directory=/srv/adelpos-license/recovery/ensayo1
sudo mysql
```

```sql
CREATE DATABASE adelpos_license_restore_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'adelpos_license_restore'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
GRANT ALL PRIVILEGES ON adelpos_license_restore_check.* TO 'adelpos_license_restore'@'localhost';
EXIT;
```

```sh
cd /srv/adelpos-license/app
sudo php8.3 bin/recovery-config.php --directory=/srv/adelpos-license/recovery/ensayo1 \
    --output=/srv/adelpos-license/recovery/import.php \
    --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=adelpos_license_restore_check;charset=utf8mb4' \
    --user=adelpos_license_restore --signing-dir=/srv/adelpos-license/recovery/ensayo1/keys
sudo php8.3 bin/restore-empty.php --directory=/srv/adelpos-license/recovery/ensayo1 \
    --target-config=/srv/adelpos-license/recovery/import.php
php8.3 bin/grants.php --database=adelpos_license_restore_check --user=adelpos_license_app | sudo mysql
sudo php8.3 bin/recovery-config.php --directory=/srv/adelpos-license/recovery/ensayo1 \
    --output=/srv/adelpos-license/recovery/runtime.php \
    --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=adelpos_license_restore_check;charset=utf8mb4' \
    --user=adelpos_license_app --signing-dir=/srv/adelpos-license/recovery/ensayo1/keys
sudo env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/recovery/runtime.php php8.3 bin/preflight.php --require-signer
sudo env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/recovery/runtime.php php8.3 bin/recovery-check.php \
    --against=/srv/adelpos-license/backup/archives/FECHA.anchor.json
```

Las contraseñas que pidan son las de `adelpos_license_restore` (la importación) y la de `adelpos_license_app` (la configuración final).

**Debes ver:** `preflight` correcto y `recovery-check` con las firmas, las evidencias y la auditoría verificadas contra el anclaje.

**Al terminar el ensayo**, borra lo que se creó (contiene secretos sin cifrar):

```sh
sudo mysql -e "DROP DATABASE adelpos_license_restore_check; DROP USER 'adelpos_license_restore'@'localhost';"
sudo rm -rf /srv/adelpos-license/recovery
```

`restore-empty` se niega a escribir en una base con tablas. Una recuperación real (no un ensayo) sigue OPERATIONS.md, sección 8, «Respaldos y restauración».

---

## 13. Verificación final y primer acceso

```sh
cd /srv/adelpos-license/app
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php \
    php8.3 bin/preflight.php --require-signer
sudo rm /srv/adelpos-license/shared/maintenance.flag
sudo env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/verify-deployment.php
```

**Debes ver:** `preflight` correcto y, en `verify-deployment`, una línea **OK** por comprobación (PHP y extensiones, permisos, base y permisos de la cuenta, migraciones, llave de firma, tarea diaria, certificado, redirección, cabeceras, archivos ocultos y API). Cada **Advertencia** o **Error** dice qué hacer. El comando no modifica nada y termina con código 1 si hay algún error.

Después, en tu navegador:

1. Abre `https://licensing.sicrat.com`, entra con tu correo y tu contraseña, configura la verificación en dos pasos y guarda los ocho códigos de recuperación.
2. Registra un cliente de prueba, emite una licencia y actívala desde una versión de **desarrollo** de AD-EL POS que confíe en la llave del paso 8.
3. Comprueba en el resumen y en el detalle de la licencia la computadora activa y su revisión firmada.

---

## 14. Actualizar a una versión nueva

Una actualización cambia a otra **etiqueta**. Anota la actual antes de empezar: `sudo git -C /srv/adelpos-license/app describe --tags`.

```sh
sudo touch /srv/adelpos-license/shared/maintenance.flag
sudo systemctl stop adelpos-license-maintenance.timer
sudo systemctl start adelpos-license-maintenance.service
cd /srv/adelpos-license/app
sudo git fetch --tags
sudo git checkout --detach v1.0.1
sudo env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-plugins
sudo chmod -R go-w /srv/adelpos-license/app
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php \
    php8.3 bin/migrate.php --user=adelpos_license_migrate
php8.3 bin/grants.php --database=adelpos_license --user=adelpos_license_app | sudo mysql
sudo -u adelpos-license env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php \
    php8.3 bin/preflight.php --require-signer
sudo systemctl reload php8.3-fpm
sudo rm /srv/adelpos-license/shared/maintenance.flag
sudo env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/verify-deployment.php
sudo systemctl start adelpos-license-maintenance.timer
```

`start adelpos-license-maintenance.service` hace un respaldo justo antes de cambiar nada. Si la versión nueva cambió `deploy/nginx.conf.example`, `deploy/php-fpm-pool.conf.example` o los archivos de la tarea, sus notas lo dicen: vuelve a instalarlos como en los pasos 9 a 11 y comprueba con `nginx -t` y `php-fpm8.3 -t` antes de recargar.

**Si algo falla, volver a la versión anterior:**

- **Si la versión nueva no aplicó ninguna migración** (`migrate.php` no imprimió ninguna línea `Aplicada`): con el aviso de mantenimiento puesto, regresa a la etiqueta anterior y recarga.

  ```sh
  cd /srv/adelpos-license/app
  sudo git checkout --detach v1.0.0
  sudo env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-plugins
  sudo systemctl reload php8.3-fpm
  ```

- **Si aplicó una migración:** las migraciones no se deshacen. Deja el aviso de mantenimiento, restaura el respaldo de antes de actualizar en una base nueva (paso 12.3, con la etiqueta anterior), apunta la configuración a esa base y recarga. Mientras no lo resuelvas, el aviso hace que el panel y la API respondan 503 (la API, con el JSON del contrato), y las licencias ya instaladas siguen funcionando sin internet.

---

## 15. Convivencia con AIDEX

| Recurso | AD-EL POS — Licencias | AIDEX |
| --- | --- | --- |
| Código, configuración, llaves y respaldos | `/srv/adelpos-license/` | Los suyos |
| Usuario del sistema | `adelpos-license` | El suyo |
| Base y cuentas de MySQL | `adelpos_license`, `adelpos_license_migrate`, `adelpos_license_app` | Las suyas |
| Pool de PHP-FPM y socket | `[adelpos-license]`, `/run/php/adelpos-license.sock` | Los suyos |
| Sitio de Nginx, nombres globales y certificado | `adelpos-license`, `adelpos_*`, `licensing.sicrat.com` | Los suyos |
| Tarea diaria y clave de recuperación | `adelpos-license-maintenance.*`, `backup/recovery.key` | Las suyas |

Comparten solo los servicios del sistema (Nginx, PHP-FPM, MySQL y Certbot). Por eso `nginx -t` y `php-fpm8.3 -t` revisan los dos sitios: **nunca recargues si alguno falla**.

**Después de instalar, comprueba que AIDEX sigue bien:**

```sh
sudo nginx -t
sudo php-fpm8.3 -t
ls /run/php/
systemctl list-timers
```

Y abre el panel de AIDEX en su dominio e inicia sesión, como siempre. **Debes ver:** las dos comprobaciones correctas, los dos sockets (`adelpos-license.sock` y el de AIDEX), los timers de los dos, y el panel de AIDEX funcionando.

---

## 16. Problemas comunes

| Síntoma | Causa probable | Qué hacer |
| --- | --- | --- |
| `502 Bad Gateway` | El pool no está corriendo o el socket no tiene los permisos | `sudo systemctl status php8.3-fpm`, `ls -l /run/php/adelpos-license.sock` (debe ser de `adelpos-license` con grupo `www-data`) y `sudo tail /srv/adelpos-license/shared/log/php-error.log` |
| `503` en todo el sitio | Quedó el aviso de mantenimiento | `ls /srv/adelpos-license/shared/maintenance.flag`; bórralo si el trabajo terminó |
| `503` con «Servicio no disponible» y un número de referencia | La aplicación no arrancó (configuración o base) | Busca ese número en `shared/log/php-error.log` y corre `preflight` |
| La API responde `TEMPORARY_UNAVAILABLE` al activar | No hay llave de firma activa del entorno `test` | Paso 8 y `preflight --require-signer` |
| `403` «El panel no está disponible desde esta red» | `ADMIN_NETWORKS` no incluye tu red | Ajusta ese valor en `shared/config.php` y recarga PHP-FPM |
| `429` «Demasiados intentos…» | El límite de Nginx o de la aplicación | Espera un minuto; si es la aplicación, 15 minutos |
| `nginx -t` falla después de instalar | Un nombre global repetido o un certificado que falta | Lee el archivo y la línea del mensaje; no recargues hasta corregir |
| `preflight`: «Falta aplicar una migración» | El clon no está en la etiqueta o tiene cambios locales | `sudo git -C /srv/adelpos-license/app status` y `describe --tags`; corre `migrate.php` |
| `preflight`: «permisos de modificación excesivos» | La cuenta de la aplicación tiene permisos de más | Paso 6 |
| La tarea diaria falla | Falta la clave de recuperación, espacio o `php8.3` | `sudo journalctl -u adelpos-license-maintenance.service` |
| Certbot no renueva | El DNS cambió, el puerto 80 está cerrado o el bloque HTTP no está | `sudo certbot renew --dry-run` y `sudo nginx -t` |
| No llegan los códigos de verificación correctos | El reloj del VPS no está sincronizado | `timedatectl` (paso 1.1) |
