# Avance — Etapa 1: copia base y renombre

Especificación: `ETAPA_1_COPIA_BASE_Y_RENOMBRE.md`. Contrato de referencia (no se implementa en esta etapa): `CONTRATO_LICENCIAS_ADEL_POS.md` V1.0.

| Fase | Estado |
| --- | --- |
| 0 — Verificación contra el código | **Cerrada.** Línea base: 147 pruebas, 20 904 aserciones, 1 error con MySQL 8.0.46. El propietario decidió corregirlo en la Fase 1. |
| 1 — Repositorio y copia limpia | **Cerrada.** 161 pruebas, 20 994 aserciones, todas OK. |
| 2 — Renombre de la identidad interna | **Cerrada.** 162 pruebas, 20 997 aserciones, todas OK. Verificador Go OK con archivos regenerados. |
| 3 — Esquema inicial consolidado | **Cerrada.** 161 pruebas, 20 980 aserciones, todas OK. Equivalencia con la cadena original comprobada. |
| 4 — Retirar lo exclusivo de AIDEX | **Cerrada.** 163 pruebas, 21 368 aserciones, todas OK. Verificador Go OK. 20 puntos heredados marcados. |
| 5 — Marca y textos | **Cerrada.** 164 pruebas, 21 370 aserciones, todas OK. Playwright: panel y dispositivos de confianza OK. |
| 6 — Documentación y cierre | Pendiente |

---

## Fase 0 — Verificación contra el código (sin cambios)

Fecha: 2026-11-16. No se modificó la copia de AIDEX: todas las ejecuciones se hicieron sobre una copia temporal.

### 0.1 Origen

| Dato | Valor |
| --- | --- |
| Ruta de la copia de AIDEX (solo lectura) | `/Applications/XAMPP/xamppfiles/htdocs/adelposlicensing/aidexlicense` |
| Último commit del repositorio de AIDEX | `ef537cde356c101e56349bb45cba6e5769fb681c` — 2026-11-03 21:27:52 -0600 — «Guardar Dispositivo de Confianza 2FA» |
| Fechas de los archivos (sin `.git`, `vendor/`, `node_modules/`) | del 2026-09-25 19:00 al 2026-11-03 21:24 |
| Fecha de `composer.lock` | 2026-10-21 20:18 |
| SHA-256 de `composer.lock` | `7f996783b79412c73ad00d23e5354a5141517ca93da5d294370f353aab9f1730` |

### 0.2 Línea base de pruebas

La copia temporal se hizo con `rsync` sin `.git/`; `diff -rq` contra el original no mostró diferencias. Tras un reinicio del equipo, que borró el directorio temporal, se volvió a copiar: idéntica y con el mismo SHA-256 de `composer.lock`.

Entorno: PHP 8.5.7, PHPUnit 12.5.36 y **MySQL 8.0.46**, la misma versión del VPS. MySQL está instalado por el propietario en `/usr/local/mysql`, con socket `/tmp/mysql.sock` y usuario `root` exclusivo de pruebas. El servidor estaba vacío: solo las bases del sistema. Nginx 1.31.3, PHP-FPM 8.5.7 y `pdftotext` 26.08.0.

**Línea base medida (2026-11-16), copia sin cambios:**

| Conjunto | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| `unit` (`tests/Unit`) | 57 | 20 116 | OK |
| `integration` (`tests/Integration`) | 90 | 788 | 89 OK, **1 error** (`BackupTest`) |
| **Total de `phpunit.xml`** | **147** | **20 904** | **146 OK, 1 error**, sin omisiones ni advertencias. Duración: 1 min 5 s. |

**Diagnóstico en una segunda copia temporal** (la de la línea base no se tocó): al quitar solo la opción `--no-login-paths` de `src/Infrastructure/MySqlFiles.php`, la suite completa da **147 pruebas y 20 937 aserciones, todas OK**. `BackupTest` pasa de 2 aserciones antes del error a 35. Detalle en 0.7, punto 1.

Limpieza: después de cada corrida quedan 0 bases y 0 usuarios `aibid_test_*` en MySQL, y ninguna carpeta temporal `aibid-*`.

Pruebas por archivo:

| Unitarias | Pruebas | Integración | Pruebas |
| --- | --- | --- | --- |
| ActivationCodeTest | 2 | ActivationsTest | 20 |
| EncryptedArchiveTest | 3 | AdministrationTest | 17 |
| OfflineRequestTest | 15 | BackupTest | 1 |
| ProductionConfigTest | 14 | CommercialDeliveryTest | 7 |
| ProtectedRecordTest | 1 | CommercialMigrationTest | 1 |
| ProtocolTest | 13 | HttpApiTest | 2 |
| RulesTest | 9 | OfflineTest | 19 |
| | | ProductionHttpTest | 3 |
| | | TrustedDevicesHttpTest | 3 |
| | | TrustedDevicesTest | 17 |

**Excluidas por depender del cliente real de AIDEX (`../expediente`):**

| Archivo | Motivo |
| --- | --- |
| `tests/System/RealClientTest.php` | 2 pruebas. Necesita `TEST_CLIENT_SOURCE` con el código Go del cliente AIDEX. No está en ningún `testsuite` de `phpunit.xml`, así que no forma parte de los 147. |
| `tests/RealClientBridge.php` | Auxiliar de la prueba anterior; no contiene pruebas. |
| `tests/client-bridge_test.go` | Prueba Go que se ejecuta dentro de la copia del cliente AIDEX. |

Lo que necesita la integración, además de MySQL 8:

| Requisito | Pruebas que lo usan | Disponible aquí |
| --- | --- | --- |
| `TEST_MYSQL_DSN` (+ `TEST_MYSQL_USER`/`TEST_MYSQL_PASSWORD` si no es `root` sin contraseña) | todas las de integración | Sí: MySQL 8.0.46 en `/tmp/mysql.sock` |
| `mysql` y `mysqldump` de MySQL 8 (`TEST_MYSQL_BIN`, `TEST_MYSQLDUMP_BIN`) | BackupTest | Sí: `/usr/local/mysql/bin/` (8.0.46) |
| `php-fpm`, `nginx`, `openssl` | ProductionHttpTest, TrustedDevicesHttpTest | Sí |
| `pdftotext` (`TEST_PDFTOTEXT_BIN`) | pruebas de constancias PDF | Sí |

### 0.3 Verificador del contrato heredado

```
$ go run contracts/v1/verify-vectors.go contracts/v1/fixtures/vectors.json
OK: 5 JWS, 3 proofs, 3 offline requests, 3 negative JWS; PHP/Go signatures match.
Client entitlement/state cases are specified in fixtures, not executed by this crypto oracle.
```

Resultado esperado: **OK**. También pasa `php contracts/v1/generate-fixtures.php --check` («Verified 5 JWS, 3 proofs, 3 offline requests and 3 negative JWS fixtures»).

### 0.4 Versiones disponibles en este equipo

| Herramienta | Versión | Nota |
| --- | --- | --- |
| PHP (CLI y FPM) | 8.5.7 | Es la única instalada. Los alias de Homebrew `php@8.1`, `php@8.3`, `php@8.4` y `php@8.5` apuntan todos a 8.5.7. |
| Extensiones | sodium (libsodium 1.0.22), pdo_mysql (mysqlnd 8.5.7), mbstring (oniguruma 6.9.10), gd 2.3.3, xmlwriter (libxml 2.9.13), openssl 3.6.2, intl (ICU 78.3), ctype, curl, dom, fileinfo, iconv, json, zip | Están todas las que exige `composer.json`. |
| Composer | 2.8.6 | Muestra avisos de obsolescencia propios bajo PHP 8.5; no afectan. |
| MySQL | 8.0.46 (MySQL Community Server, paquete oficial) | Instalado por el propietario en `/usr/local/mysql`. La MariaDB 10.4.21 de XAMPP no se usa y debe estar detenida, porque ambas usan el puerto 3306. Ver 0.7. |
| Go | 1.25.5 | Las pruebas de AIDEX registran 1.27.1; el verificador funciona con 1.25.5. |
| Node / npm | 22.15.0 / 11.6.3 | |
| Nginx | 1.31.3 | |
| OpenSSL | 3.6.3 | |
| Google Chrome | 157.0.8103.78 | Para Playwright y `bin/render-brand.cjs`. |
| Poppler `pdftotext` | 26.08.0 | |

**Versiones del VPS (confirmadas por el propietario el 2026-11-16):**

| Componente | VPS (Ubuntu 24.04) | Este equipo | Comprobación |
| --- | --- | --- | --- |
| PHP / PHP-FPM | **8.3.6** | 8.5.7 | `composer.json` exige `php ^8.3` y todos los paquetes de `composer.lock` admiten 8.3 (PHPUnit 12.5 pide `>=8.3`). Una búsqueda en `src/`, `bin/`, `public/`, `templates/`, `tests/` y `contracts/` no encontró sintaxis ni funciones de PHP 8.4 u 8.5. No se pueden ejecutar pruebas en 8.3 en este equipo. |
| MySQL | **8.0.46** (`8.0.46-0ubuntu0.24.04.5`) | 8.0.46 | Misma versión. Los clientes `mysql`/`mysqldump` 8.0 no aceptan `--no-login-paths` (ver 0.7, punto 1). |
| Nginx | **1.24.0** | 1.31.3 | `deploy/nginx.conf.example` y `nginx-http.conf.example` no usan directivas posteriores a 1.24 (por ejemplo, no usan `http2 on`). Las pruebas con Nginx real corren aquí con 1.31.3. |

### 0.5 Inventario de referencias a la identidad de AIDEX

Búsqueda sin distinguir mayúsculas de: `aibid`, `aidex`, `gestor_documental`, `expediente` (incluye `expedientes`), `licensing.aidex.com.mx`, `linked_libraries`, `managed_libraries`, `ocr` (como palabra completa) y `review_workflow`. Se excluyen `.git/` y `vendor/`. Resultado: **164 archivos**. De ellos, **139 entran en la lista de permitidos de la Fase 1** y deben limpiarse en las fases 2 a 6; los otros 25 no se copian.

Clasificación: **código** (`src/`, `public/`, `templates/`, `bin/`, `database/`, scripts de `contracts/`), **configuración** (`config/`, `deploy/`, `composer.*`, `package*.json`, `phpunit.xml`), **pruebas** (`tests/`), **documentación** (`*.md`) y **artefactos** (archivos generados, logos, `var/`, cachés).

#### Lo que se copia (139 archivos)

| Categoría | Término | Archivos | Ocurrencias | Fase que lo resuelve |
| --- | --- | --- | --- | --- |
| Código | `aibid` | 93 | 196 | 2 (namespace `Aibid\`, HKDF, respaldos, bloqueos) |
| Código | `aidex` | 15 | 32 | 2 y 5 |
| Código | `gestor_documental` | 6 | 17 | 3 y 4 |
| Código | `expediente(s)` | 7 | 12 | 3, 4 y 5 |
| Código | `review_workflow` | 3 | 5 | 3 y 4 |
| Código | `linked_libraries`, `managed_libraries`, `ocr` | 2 c/u | 2 c/u | 3 y 4 |
| Código | `licensing.aidex.com.mx` | 1 | 1 | 2 |
| Configuración | `aidex` | 10 | 41 | 2 |
| Configuración | `aibid` | 4 | 6 | 2 |
| Configuración | `licensing.aidex.com.mx` | 3 | 6 | 2 |
| Pruebas | `aibid` | 26 | 135 | 2 |
| Pruebas | `aidex` | 9 | 18 | 2 y 5 |
| Pruebas | `gestor_documental` | 13 | 33 | 3 (producto sintético) |
| Pruebas | `expediente(s)` | 7 | 12 | 3 |
| Pruebas | `review_workflow` | 5 | 9 | 3 |
| Pruebas | `ocr` | 6 | 8 | 3 |
| Pruebas | `linked_libraries` | 5 | 7 | 3 |
| Pruebas | `managed_libraries` | 5 | 5 | 3 |
| Pruebas | `licensing.aidex.com.mx` | 2 | 3 | 2 |
| Documentación | `aidex` | 5 | 117 | 6 (se reescriben) |
| Documentación | `aibid` | 6 | 33 | 6 |
| Documentación | `licensing.aidex.com.mx` | 3 | 14 | 6 |
| Documentación | módulos (`expediente`, `gestor_documental`, etc.) | 1–4 | 18 en total | 6 |
| Artefactos | módulos de AIDEX en `contracts/v1/fixtures/vectors.json` | 1 | 39 | 2 (se regeneran) |

Detalle de los archivos fuera de `aibid`:

- **Código:**
  - `aidex`: `bin/build-release.php`, `bin/configure.php`, `bin/grants.php`, `bin/render-brand.cjs`, `public/index.php`, `src/Application/CatalogService.php`, `src/Http/ApiKernel.php`, `src/Infrastructure/Crypto.php`, `src/Infrastructure/Totp.php`, `templates/certificate-pdf.php`, `templates/dashboard.php`, `templates/layout.php`, `templates/products.php`, `templates/verify-document.php`, `database/migrations/005_aidex_identity.sql`.
  - Módulos de AIDEX: `database/migrations/002_aibid_catalog.sql`, `database/migrations/005_aidex_identity.sql`, `contracts/v1/generate-fixtures.php`, `src/Application/CatalogService.php`, `templates/certificate-pdf.php`, `templates/layout.php`, `templates/product-form.php`, `templates/products.php`.
  - `licensing.aidex.com.mx`: `bin/configure.php`.
- **`aibid` en código, por carpeta:** `bin/` 15 archivos (59), `src/Application/` 13 (39), `src/Infrastructure/` 15 (35), `templates/` 28 (29), `src/Http/` 4 (12), `src/Domain/` 11 (11), `src/App.php` (4), `src/Config.php` (2), `contracts/` 2 (2), `database/` 2 (2), `public/index.php` (1).
- **Configuración:** `composer.json`, `package.json`, `package-lock.json`, `config/local.example.php`, `deploy/aidex-maintenance.service`, `deploy/aidex-maintenance.sh`, `deploy/aidex-maintenance.timer`, `deploy/nginx.conf.example`, `deploy/nginx-http.conf.example`, `deploy/php-fpm-pool.conf.example`.
- **Pruebas:** `tests/FrozenClock.php`, `tests/MySqlFixture.php`, `tests/NativeStackFixture.php`, los 10 archivos de `tests/Integration/`, los 7 de `tests/Unit/`, `tests/activation-worker.php`, `tests/offline-worker.php`, `tests/race-worker.php`, `tests/prepare-panel.php`, `tests/prepare-trusted-panel.php`, `tests/panel.cjs`, `tests/offline-panel.cjs`.
- **Documentación:** `README.md`, `ARCHITECTURE.md`, `SCHEMA.md`, `SECURITY.md`, `OPERATIONS.md`, `TEST_PLAN.md`, `DEPLOY_UBUNTU_24_04.md`.

#### Lo que no se copia (25 archivos con referencias)

| Categoría | Archivos |
| --- | --- |
| Documentación (10) | `ACTUALIZACION-FASE1.md`, `API.md`, `DECISIONS.md`, `DESPLIEGUE-PRODUCCION.md`, `DISPOSITIVOS-DE-CONFIANZA.md`, `INFORME-TRANSICION-AIDEX.md`, `INTEGRACION-LICENCIAMIENTO.md`, `LICENCIAMIENTO-COMERCIAL-FASE1.md`, `LICENSE_CONTRACT.md`, `STATUS.md`, `prompt_2_servidor_licencias_php_v1_2.md` |
| Configuración local con secretos (1) | `config/local.php` (permisos 600; no se abrió) |
| Pruebas del cliente real (3) | `tests/System/RealClientTest.php`, `tests/RealClientBridge.php`, `tests/client-bridge_test.go` |
| Logos de AIDEX (4 con referencias) | `public/assets/brand/logo-h.svg`, `logo-dark-h.svg`, `logo-v.svg`, `logo-dark-v.svg`. La carpeta también tiene `logo-h-pdf.png`, `logo-h-pdf.json` y `.DS_Store`. |
| Artefactos (6) | `var/client-integration-report.json`, tres `.sha256` de `var/releases/`, `.phpunit.cache/test-results`, `node_modules/.package-lock.json` |

Otros artefactos sin referencias en texto, que tampoco se copian: `var/releases/` (3 paquetes `.tar.gz`), `var/screenshots/` (12 PNG), `var/phase1-evidence/` (3 PDF), `.DS_Store` en `public/`, `public/assets/` y `public/assets/brand/`. No hay carpetas `__MACOSX`.

### 0.6 Logos de AD-EL POS

- `adelpos-license/public/assets/brand/` **todavía no existe** en el repositorio nuevo. Por indicación del propietario, los logos se copian sin cambios desde `./logos-adelpos/` en la Fase 5.
- Archivos disponibles en `./logos-adelpos/`:

| Archivo | Formato | Tamaño | SHA-256 |
| --- | --- | --- | --- |
| `adel-pos-tema-claro.png` | PNG con transparencia | 2048 × 682 | `fece00410137a618703c5921d2ce2aac5ef06227836b75505201e58125e05434` |
| `adel-pos-tema-oscuro.png` | PNG con transparencia | 2048 × 682 | `82e1a563a24ebe59fa3676fbb8cae430da4ddc263034583a0f40bbd985e29116` |

- Las plantillas de AIDEX usan cuatro SVG (horizontal claro, horizontal oscuro, vertical claro y vertical oscuro) y un PNG para las constancias PDF, generado desde el SVG con `bin/render-brand.cjs`. Ver la discrepancia 5.

### 0.7 Discrepancias y pendientes

1. **`BackupTest` falla con MySQL 8.0.46.** **Decisión del propietario (2026-11-16):** corregirlo en la Fase 1, en un commit propio después de la copia base. Ver 1.5.
   - **Causa:** `src/Infrastructure/MySqlFiles.php`, línea 51, pasa `--no-login-paths` a `mysqldump` (respaldo) y a `mysql` (restauración). Esa opción **no existe en los clientes de MySQL 8.0**, incluida la 8.0.46 del VPS: `mysqldump: [ERROR] unknown option '--no-login-paths'`. AIDEX se probó con MySQL 8.4.11, que sí la tiene.
   - **Efecto:** `bin/backup.php --action=create` y la restauración en base vacía fallan con MySQL 8.0. La prueba lo reporta como «No se pudo completar el comando (RuntimeException)».
   - **Comprobación:** en una segunda copia temporal, sin esa opción, la suite completa pasa: 147 pruebas y 20 937 aserciones.
   - **Para qué servía la opción:** impide que `mysql`/`mysqldump` lean credenciales guardadas en `~/.mylogin.cnf`, que se leen aun con `--defaults-file`. En MySQL 8.0 esa protección debe lograrse de otra forma; por ejemplo, con la variable de entorno `MYSQL_TEST_LOGIN_FILE` apuntando a un archivo inexistente.
   - **Informativo:** AIDEX convive en el mismo VPS con MySQL 8.0.46, así que su comando de respaldo probablemente también falle allí. No se modifica AIDEX en esta etapa.
2. **MySQL 8 de pruebas:** resuelto.
   - **Intento descartado 1:** el paquete oficial 8.0.46 descargado al directorio temporal no se pudo ejecutar por el control de permisos de la herramienta, y se borró con el reinicio.
   - **Intento descartado 2:** la MariaDB 10.4.21 de XAMPP no sirve como línea base, por cuatro motivos:
     - no tiene `performance_schema.data_locks` ni `data_lock_waits`, que usan las pruebas de concurrencia de `ActivationsTest`, `AdministrationTest` y `OfflineTest`;
     - trata distinto las 37 restricciones `CHECK`, las columnas `JSON` y las columnas generadas de las migraciones;
     - su `mysqldump` no es el de MySQL 8;
     - no está aislada: tiene 33 bases de otros proyectos.
   - **Solución:** el propietario instaló MySQL 8.0.46 oficial en `/usr/local/mysql` y detuvo la MariaDB de XAMPP, porque ambas usan el puerto 3306. La primera instalación no arrancó por ese choque y dejó a `root` sin la contraseña elegida; se reinstaló y funciona.
   - **Si se vuelve a encender la MariaDB de XAMPP,** hay que mover MySQL 8 a otro puerto, por ejemplo el 3307.
3. **Versiones del VPS:** resuelto. PHP 8.3.6, MySQL 8.0.46 y Nginx 1.24.0 en Ubuntu 24.04 (ver 0.4).
4. **Solo PHP 8.5.7 en este equipo.** La especificación lo permite. La compatibilidad con 8.3.6 se comprueba con `composer.json`/`composer.lock` y con una búsqueda de sintaxis nueva, pero no ejecutando las pruebas en 8.3.
5. **Nombre del paquete npm.** La tabla de la Fase 2 pide `adelpos/license-server` para Composer y npm. Es válido en Composer, pero **npm lo rechaza** («name can only contain URL-friendly characters»). **Decisión del propietario (2026-11-16):** en npm se usa `@adelpos/license-server`; en Composer, `adelpos/license-server`.
6. **Logos.** AD-EL POS tiene dos PNG horizontales (claro y oscuro) y no tiene SVG ni versión vertical o ícono. En la Fase 5:
   - las referencias horizontales se ajustarán a los PNG;
   - **decisión del propietario (2026-11-16):** no hay versiones verticales. Donde AIDEX usaba un logo vertical (barra lateral, ícono de pestaña, pantalla de acceso y página de verificación) se usa el horizontal de `logos-adelpos/`: el claro sobre fondo claro y el oscuro sobre fondo oscuro. Se aplica en la Fase 5;
   - sin SVG no se puede regenerar el PNG para PDF con `bin/render-brand.cjs`. **Decisión del propietario (2026-11-16):** las constancias PDF usan `adel-pos-tema-claro.png` tal cual, sin modificar sus bytes, en lugar del nombre en texto. Se registrará en `DECISIONS.md` en la Fase 6.
7. **`INTEGRACION-LICENCIAMIENTO.md`** existe en AIDEX y no aparece ni en la lista de permitidos ni en la de excluidos de la Fase 1. Como la Fase 1 copia solo lo permitido, **no se copia**.
8. Informativo: la documentación de AIDEX registra corridas anteriores (por ejemplo, 96 pruebas/571 aserciones o 127/20 802). La línea base vale solo con lo medido aquí: 147 pruebas y 20 904 aserciones en `phpunit.xml`.

### 0.8 Comandos para reproducir

```sh
# Copia temporal (el original no se toca)
rsync -a --exclude '.git/' aidexlicense/ "$TMP/aidex/"
cd "$TMP/aidex"

# Verificador del contrato heredado
env GOTOOLCHAIN=local GOPROXY=off GOSUMDB=off GOCACHE="$TMP/go-cache" \
  go run contracts/v1/verify-vectors.go contracts/v1/fixtures/vectors.json
php contracts/v1/generate-fixtures.php --check

# Unitarias
php vendor/bin/phpunit --testsuite unit

# Suite completa contra el MySQL 8.0.46 de pruebas de este equipo
# (MariaDB de XAMPP detenida; la contraseña de root de pruebas no se escribe en el repositorio)
export TEST_MYSQL_PASSWORD='<contraseña de root de pruebas>'
env TEST_MYSQL_DSN='mysql:unix_socket=/tmp/mysql.sock;charset=utf8mb4' \
    TEST_MYSQL_USER=root \
    TEST_MYSQL_BIN=/usr/local/mysql/bin/mysql TEST_MYSQLDUMP_BIN=/usr/local/mysql/bin/mysqldump \
    TEST_FPM_BIN=/opt/homebrew/sbin/php-fpm TEST_NGINX_BIN=/opt/homebrew/bin/nginx \
    TEST_OPENSSL_BIN=/opt/homebrew/opt/openssl@3/bin/openssl TEST_PDFTOTEXT_BIN=/opt/homebrew/bin/pdftotext \
    php vendor/bin/phpunit

# Residuos después de las pruebas (debe dar 0 y 0)
php -r '$p=new PDO("mysql:unix_socket=/tmp/mysql.sock","root",getenv("TEST_MYSQL_PASSWORD"));
  echo $p->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE \"aibid\\_test\\_%\"")->fetchColumn(), " ",
       $p->query("SELECT COUNT(*) FROM mysql.user WHERE user LIKE \"aibid\\_test\\_%\"")->fetchColumn(), "\n";'

# Inventario: búsqueda sin distinguir mayúsculas, sin .git ni vendor
grep -rIciE --exclude-dir=.git --exclude-dir=vendor \
  -e 'aibid|aidex|gestor_documental|expediente|licensing\.aidex\.com\.mx|linked_libraries|managed_libraries|\bocr\b|review_workflow' .
```

---

## Fase 1 — Repositorio y copia limpia

Fecha: 2026-11-16. Estado: **cerrada**.

### 1.1 Repositorio

Repositorio git independiente en `adelpos-license/`, rama `main`. La carpeta de trabajo superior no tiene git.

| Commit | Contenido |
| --- | --- |
| `3c0c87b` | **Copia base desde AIDEX (sin secretos ni artefactos).** Solo la lista de permitidos, más `.gitignore` ampliado y `var/.gitkeep`. |
| `295961f` | Compatibilidad de respaldos con MySQL 8.0 (ver 1.5) |
| `50b22e9` | Funcionar sin los logos de AIDEX (ver 1.5) |
| `9adde0e` | Revisión de secretos, su prueba, sección en `README.md` y este registro |

### 1.2 Copia

- Se copiaron 169 archivos de AIDEX según la lista de permitidos. Todos son **idénticos byte a byte** al original (comprobado con `cmp`), y el SHA-256 de `composer.lock` coincide.
- Se comprobó que no hay `.DS_Store`, `__MACOSX`, `config/local.php`, `var/` (salvo `.gitkeep`), `vendor/`, `node_modules/`, `.phpunit.cache/`, logos de AIDEX, pruebas del cliente real, ni archivos `*.key` o `*.pem`.
- `tests/System/` no existe, porque su única prueba es del cliente real.
- `INTEGRACION-LICENCIAMIENTO.md` no se copió (0.7, punto 7).

### 1.3 `.gitignore`

Al original se agregaron `/var/keys/`, `*.key`, `*.pem`, `*.aibidbackup`, `*.adelposbackup` y `__MACOSX/`. Ya cubría `config/local.php`, `var/*` (excepto `.gitkeep`), `vendor/`, `node_modules/`, `.phpunit.cache/` y `.DS_Store`. Se verificó con `git check-ignore` que cada caso queda ignorado y que `var/.gitkeep` sí se versiona. La extensión `*.aibidbackup` se retira en la Fase 2, junto con el formato heredado.

### 1.4 Revisión de secretos

- `bin/check-secrets.php`. Revisa el índice de git y los archivos nuevos no ignorados, y falla si encuentra:
  - un valor no vacío en `DB_PASSWORD` o `*_KEY` (en PHP, por análisis de tokens; en otros archivos, por patrones de asignación);
  - llaves privadas PEM, o semillas o llaves secretas Ed25519;
  - archivos bajo `var/keys/`, o archivos `*.key` o `*.pem`.
- Nunca imprime el valor encontrado. No necesita `composer install`.
- Excepción: las semillas de `contracts/v1/fixtures/vectors.json` se aceptan solo si son exactamente `sha256('AIBIDLICENSE PUBLIC TEST ONLY V1 ' + nombre)`, el prefijo público de prueba. En la Fase 2 cambia junto con las semillas.
- La regla de claves de configuración no revisa `public/assets/vendor/`, que es Bootstrap de terceros y define constantes de teclado como `ESCAPE_KEY`. Las reglas de llaves sí lo revisan.
- `tests/Unit/SecretsCheckTest.php` (14 pruebas). Cubre:
  - un repositorio limpio con marcadores vacíos y semillas públicas;
  - 11 tipos de hallazgo, incluido un archivo nuevo sin agregar;
  - un secreto en el índice aunque la copia de trabajo esté limpia;
  - el propio repositorio sin hallazgos.
- Resultado antes de cada commit de la fase: «sin hallazgos».
- Documentada en `README.md`, con un gancho `pre-commit` opcional.

### 1.5 Ajustes necesarios fuera de la copia

1. **Respaldos con MySQL 8.0** (decisión del propietario; 0.7, punto 1). `src/Infrastructure/MySqlFiles.php`:
   - se quita `--no-login-paths`;
   - las utilidades se ejecutan con `MYSQL_TEST_LOGIN_FILE` apuntando a una ruta inexistente, y con ninguna otra variable de entorno.

   Comprobado con el cliente 8.0.46 y `--print-defaults`:
   - un `.mylogin.cnf`, por la variable o por `HOME`, se lee aun con `--defaults-file`, y sus valores sustituyen a los del archivo privado;
   - con la variable apuntando a una ruta inexistente, no se lee ninguno.

   Verificado sobre la copia de AIDEX con los logos: 147 pruebas, 20 937 aserciones, OK.
2. **Funcionar sin los logos de AIDEX.** La Fase 1 no copia los logos, y sin ellos fallaban 7 pruebas: 5 errores al generar constancias PDF y 2 fallas en `ProductionHttpTest`.
   - `src/Infrastructure/CertificatePdf.php` y `templates/certificate-pdf.php`: si no hay archivos de marca, la constancia muestra el nombre en texto. Es el criterio de la Fase 5 cuando faltan logos. Si existen, se conservan las comprobaciones de integridad.
   - `tests/Integration/ProductionHttpTest.php`: la comprobación de recursos estáticos usa `app.css` y `app.js` en lugar de los 4 SVG de AIDEX. **Prueba sustituida parcialmente:** son 8 aserciones menos. **Pendiente para la Fase 5:** volver a comprobar la marca con los logos de AD-EL POS.
   - Mientras tanto, el panel muestra imágenes rotas donde AIDEX tenía logos. Las plantillas se cambian en la Fase 5.

### 1.6 Pruebas de la fase

| Momento | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Línea base de AIDEX (Fase 0) | 147 | 20 904 | 146 OK, 1 error (`BackupTest`) |
| AIDEX + corrección de MySQL 8.0 (copia de diagnóstico) | 147 | 20 937 | OK |
| Copia pura en el repositorio (`3c0c87b`) | 147 | 20 822 | 5 errores y 2 fallas por los logos ausentes |
| Tras «Funcionar sin los logos de AIDEX» (`50b22e9`) | 147 | 20 929 | OK |
| **Cierre de la Fase 1** | **161** | **20 994** | **OK**: unitarias 71/20 181, integración 90/813, sin omisiones ni advertencias |

Diferencias contra la línea base, justificadas:
- **+14 pruebas y +65 aserciones:** `SecretsCheckTest` es nueva.
- **−8 aserciones:** la sustitución parcial en `ProductionHttpTest` (1.5, punto 2).
- **+33 aserciones:** `BackupTest` llega al final con MySQL 8.0.
- No hay pruebas eliminadas. Las 2 del cliente real nunca estuvieron en `phpunit.xml`.

Otras comprobaciones:
- `composer install` funciona y no modifica `composer.lock`.
- El verificador Go da «OK: 5 JWS, 3 proofs, 3 offline requests, 3 negative JWS».
- `php contracts/v1/generate-fixtures.php --check` da OK.
- `php bin/check-secrets.php` no encuentra nada.
- En MySQL quedan 0 bases y 0 usuarios de prueba.

---

## Fase 2 — Renombre de la identidad interna

Fecha: 2026-11-16. Estado: **cerrada**.

### 2.1 Renombres aplicados

| Concepto | Antes | Ahora |
| --- | --- | --- |
| Namespace PHP | `Aibid\`, `Aibid\Tests\` | `AdelPos\License\`, `AdelPos\License\Tests\` (115 archivos PHP y `composer.json`) |
| Paquete Composer | `aidex/license-server` | `adelpos/license-server`. En `composer.lock` solo cambió el `content-hash`, sin versiones nuevas. |
| Paquete npm | `aidex-license-panel-tests` | `@adelpos/license-server` (decisión del propietario; 0.7, punto 5) |
| Variable del archivo de configuración | `AIBID_CONFIG` | `ADELPOS_LICENSE_CONFIG` |
| Cookie de desarrollo | `aibid_admin_dev` | `adelpos_admin_dev` |
| Cookie de dispositivo de confianza | `__Host-license_trusted_device` | `__Host-adelpos_trusted_device` |
| Contextos HKDF | `aidex/<propósito>/v1`, `aibid/offline-evidence/v1` | `adelpos-license/<propósito>/v1`, `adelpos-license/offline-evidence/v1` |
| Formato y extensión de respaldos | `AIBID-BACKUP-V1`, `.aibidbackup` | `ADELPOS-LICENSE-BACKUP-V1`, `.adelposbackup` |
| Bloqueos y temporales | `aibid-migrations-`, `aibid:restore:`, `.aibid-backup-*`, `aibid-mysql-*` | `adelpos-license-migrations-`, `adelpos-license-restore-`, `.adelpos-license-backup-*`, `adelpos-license-mysql-*` |
| Semillas públicas de prueba | `AIBIDLICENSE PUBLIC TEST ONLY V1 …` | `ADELPOS LICENSE PUBLIC TEST ONLY V1 …` (generador, verificador Go, `SigningKeys`, pruebas y revisión de secretos) |
| Emisor TOTP | `AIDEX Licencias` | `AD-EL POS Licencias` |
| Prefijos de registro | `AIDEX [`, `AIDEX API [` | `adelpos-license [`, `adelpos-license API [` |
| Bases y usuarios de prueba | `aibid_test_*` | `adelpos_license_test_*` (31 caracteres; el límite de usuario de MySQL es 32) |
| Temporales de pruebas | `aibid-*`, `aidex-pdf-*` | `adelpos-license-*` |
| `deploy/` | `aidex-maintenance.*`, pool y usuario `aidex`/`aidexlicense`, `/srv/aidexlicense`, `licensing.aidex.com.mx` | `adelpos-license-maintenance.*`, pool y usuario `adelpos-license`, `/srv/adelpos-license`, `licensing.sicrat.com` |
| Ejemplos de configuración y CLI | `aidex_licenses`, `aidex_app`, `licensing.aidex.com.mx` | `adelpos_license`, `adelpos_license_app`, `licensing.sicrat.com` |

**Sin cambios, como pide la especificación:** las rutas `/v1/...` y los mensajes del protocolo heredado (`LIC-V1`, `LICREQ-V1`, `lic+jws`). En los archivos regenerados solo cambian las llaves, las semillas y las firmas; el encabezado JWS es idéntico.

### 2.2 Decisiones técnicas de la fase

1. **Límite de 64 caracteres en los bloqueos de MySQL.**
   - El de migraciones usa 32 hexadecimales del hash de la base (27 + 32 = 59). Con el prefijo nuevo y los 40 originales serían 67, y MySQL lo rechazaría.
   - El de restauración usa un hash del nombre de la base en vez del nombre (24 + 40 = 64), para no depender de su largo.
2. **Separación de llaves.** Al unificar los contextos HKDF bajo `adelpos-license/`, un registro con propósito `offline-evidence` podría haber derivado la misma llave que la evidencia sin internet. `Crypto::seal`/`open` ahora rechazan ese propósito y cualquier nombre fuera de `[a-z][a-z0-9-]*`. Nueva prueba: `ProtectedRecordTest::testRecordPurposesCannotAliasTheOfflineEvidenceKeyContext`.
3. **Llave de prueba en producción.** `ActivationsTest::testProductionRejectsPublicFixtureKeyEvenWhenRelabeled` deriva la llave de la semilla nueva y comprueba que producción la rechaza.
4. **Archivos de prueba del contrato.** Se regeneraron con `php contracts/v1/generate-fixtures.php` (9 archivos).
5. **Datos sintéticos de prueba sin la marca anterior.** `ADELPOS-…` como código inválido, `ADELPOS-sensitive-optional` y `adelpos-license-legacy-synthetic`. Conservan la misma función en cada prueba.
6. **`ProductionHttpTest`.** Se quitó la aserción «la pantalla de acceso no contiene AIBID» (−1). En la Fase 5 se agrega su equivalente: «no contiene AIDEX».
7. **`bin/build-release.php`.** Se cambió el prefijo del paquete a `adelpos-license/` y se quitó de su lista `INFORME-TRANSICION-AIDEX.md`, que no existe. El resto de la lista se corrige en la Fase 4.
8. **Scripts de Playwright.** `tests/*.cjs` y `tests/prepare-*.php` usan las cookies y rutas nuevas, pero no se ejecutan hasta la Fase 5.
9. **Documentación (`*.md`).** Se reescribe en la Fase 6.
10. **Dominio en la configuración de ejemplo.** `config/local.example.php` usa `licensing.sicrat.com` con `APP_ENV=production`. El contrato reserva ese dominio para pruebas; la separación de entornos de firma (`test`/`production`) se implementa en la etapa 2.

### 2.3 Búsqueda de `aibid` y `aidex`

En código, configuración, pruebas, `deploy/` y `bin/` (sin `*.md`): **cero** en `bin/`, `deploy/`, `config/`, `composer.json`, `package*.json`, `contracts/` y en toda la identidad interna de `src/` y `tests/`.

Quedan 27 líneas con menciones que **no son identidad interna**. Cada una tiene su propia fase y su propia búsqueda de cierre:

| Archivo | Líneas | Qué es | Fase |
| --- | --- | --- | --- |
| `database/migrations/002_aibid_catalog.sql`, `005_aidex_identity.sql` | 3 | Catálogo e historia de AIDEX | 3 (se reemplazan por `001_esquema_inicial.sql`) |
| `tests/Integration/AdministrationTest.php` | 4 | Prueba de la migración 005 y del nombre del producto AIDEX | 3 |
| `src/Application/CatalogService.php` | 2 | Mensajes del catálogo de AIDEX | 4 |
| `templates/` (5 archivos) | 15 | Textos visibles y `alt` de logos | 5 |
| `public/index.php` | 1 | Texto visible de la página de mantenimiento | 5 |
| `tests/Integration/ProductionHttpTest.php` | 2 | Aserciones de esos textos visibles | 5 |

**Criterio aplicado:** la Fase 2 cubre la identidad interna (tabla 2.1). Los textos visibles y el catálogo de AIDEX tienen sus propias búsquedas de cierre en las fases 3, 4 y 5, que eliminan esas menciones.

### 2.4 Pruebas de la fase

| Momento | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Cierre de la Fase 1 | 161 | 20 994 | OK |
| **Cierre de la Fase 2** | **162** | **20 997** | **OK**: unitarias 72/20 185, integración 90/812, sin omisiones ni advertencias |

Diferencias: +1 prueba y +4 aserciones por la prueba nueva de `ProtectedRecordTest`, y −1 aserción en `ProductionHttpTest` (2.2, punto 6).

Otras comprobaciones:
- El verificador Go da «OK: 5 JWS, 3 proofs, 3 offline requests, 3 negative JWS» con los archivos regenerados.
- `generate-fixtures.php --check` da OK.
- `composer validate` da OK.
- `php bin/check-secrets.php` no encuentra nada.
- Quedan 0 bases y 0 usuarios de prueba, tanto con el prefijo viejo como con el nuevo.

---

## Fase 3 — Esquema inicial consolidado

Fecha: 2026-11-16. Estado: **cerrada**.

**Instrucción del propietario (2026-11-16):** eliminar desde ahora cualquier mención de `aibid` o `aidex` en código, plantillas, pruebas y configuración, sin dejarlas para fases posteriores. Esta fase quita las del esquema, el catálogo y las pruebas. El commit siguiente, «Marca AD-EL POS», quita las de textos visibles y logos.

### 3.1 Migración consolidada

- `database/migrations/001_esquema_inicial.sql` sustituye a las migraciones heredadas 001 a 007, que siguen disponibles en el commit raíz `3c0c87b`.
- Conserva el orden de tablas, columnas y llaves foráneas de la cadena heredada. Las columnas que agregaban las migraciones 006 y 007 quedan al final de su tabla, como antes. Así también coinciden las posiciones de columna y los nombres automáticos de las llaves foráneas (`*_ibfk_N`).
- **Se eliminaron los datos y pasos que solo existían por la historia de AIDEX:**
  - el catálogo heredado de producto y módulos (migración 002);
  - la migración de identidad 005;
  - el relleno de la referencia comercial desde `license_changes` (migración 006).
- **Endurecimiento registrado:** `license_credentials.credential_encrypted` y `code_format` pasan a `NOT NULL`, porque no existen credenciales anteriores al formato 3-4-4-4. Se anotará en `DECISIONS.md` (Fase 6). No hay otros cambios de esquema.
- **Datos iniciales:**
  - solo el producto `adel_pos` («AD-EL POS», «Punto de venta para restaurantes»), **sin módulos**;
  - las filas técnicas de siempre: `security_state` y la cabeza de auditoría `admin`.
- **Sin cambios:** el migrador (control por checksum, bloqueo e integridad), `bin/migrate.php` y `bin/grants.php`. El esquema tiene las mismas 33 tablas, más `schema_migrations`. Una prueba comprueba que los permisos cubren exactamente esas tablas.

### 3.2 Producto sintético de prueba

`tests/MySqlFixture.php` crea, después de migrar, un producto sintético con módulos sintéticos. Los nombres conservan el orden alfabético de los anteriores, así que no cambia ningún resultado que dependa del orden.

| Antes (catálogo heredado) | Ahora (sintético) |
| --- | --- |
| producto del gestor documental | `producto_prueba` («Producto de prueba») |
| `expedientes` | `prueba_a` |
| `linked_libraries` | `prueba_b` |
| `managed_libraries` | `prueba_c` |
| `ocr` | `prueba_d` |
| `review_workflow`, que requiere `expedientes` | `prueba_e`, que requiere `prueba_a` |

- Se conservan las pruebas de dependencias y ciclos.
- El generador de `contracts/v1/fixtures/` también usa el producto sintético. Los archivos se regeneraron y el verificador Go da OK.
- El protocolo heredado no cambia (campos, `LIC-V1`, `LICREQ-V1`, `lic+jws`). Los límites heredados (`max_libraries`, `max_documents`) se conservan hasta la etapa 2 y se marcan en la Fase 4.
- Los scripts de Playwright (`tests/*.cjs`, `tests/prepare-panel.php`) usan los mismos nombres sintéticos.

### 3.3 Código

`src/Application/CatalogService.php`: se quitaron las tres reglas fijas del catálogo heredado y sus dos mensajes con el nombre AIDEX. Solo aplicaban a un producto que ya no existe, y ninguna prueba las ejercitaba. Las reglas genéricas de dependencias y ciclos (`Capabilities`) no cambian.

### 3.4 Pruebas eliminadas, sustituidas y nuevas

**Eliminadas (5).** Probaban rutas de actualización de la historia de AIDEX, que no existen con un esquema inicial único:

| Prueba | Qué probaba |
| --- | --- |
| `AdministrationTest::testIdentityMigrationPreservesCustomCatalogAndLicenseData` | Migración de identidad 005 |
| `AdministrationTest::testStageThreeMigrationPreservesExistingCommercialRecords` | Actualización de la etapa 2 a la 3 (migraciones 003 y 004 sobre datos) |
| `OfflineTest::testMigrationFourPreservesOnlineHistoryAndCredentials` | Actualización de la etapa 3 a la 4 (migración 004 sobre datos) |
| `TrustedDevicesTest::testMigrationPreservesExistingUsersSessionsAndExpiry` | Migración 007 sobre datos |
| `CommercialMigrationTest` (archivo completo) | Actualización desde 5 migraciones y relleno de datos de la 006 |

**Nueva: `tests/Integration/InitialSchemaTest.php` (4 pruebas).** Sustituye a las anteriores y cubre:

1. **Equivalencia.** La cadena heredada 001-007 se lee del commit raíz, identificado por el SHA-256 de su `composer.lock` sin nombrarlo, y se aplica en una base temporal. `001_esquema_inicial.sql` se aplica en otra. Se comparan tablas, columnas (posición, tipo, nulabilidad, valor por omisión, juego de caracteres, columnas generadas), índices, llaves foráneas y restricciones `CHECK`. **Las únicas diferencias admitidas son los dos endurecimientos.** Para comprobar que detecta cambios, se alteró temporalmente un índice: la prueba falló como debía, y el archivo se restauró idéntico.
2. **Base vacía.** Crea solo `adel_pos`, sin módulos, licencias, usuarios ni llaves. Repetir la migración no aplica nada y deja el esquema idéntico.
3. **Migrador genérico.** Con una migración sintética, aplica solo lo pendiente sin tocar los datos existentes, y rechaza un archivo modificado o una migración incompleta. Antes no había pruebas de estas dos reglas.
4. **Permisos.** Los de `bin/grants.php` cubren exactamente las tablas del esquema.

**Ajustadas:**
- `AdministrationTest::testMigrationIsRepeatableAndBootstrapIsSingleUse` comprueba ahora `adel_pos` sin módulos, en vez del producto heredado.
- `AdministrationTest::testUniqueCredentialAndCommercialChecksExistInDatabase`:
  - antes, la segunda credencial activa se rechazaba por cualquier error;
  - ahora se envían todos los campos obligatorios y se exige el error de unicidad (1062);
  - se agregó la comprobación del endurecimiento: rechazar una credencial sin copia protegida (1364).
- `CommercialDeliveryTest`: la simulación de credencial heredada (copia protegida en NULL, ya imposible) se cambió por un formato de código desconocido. Ejercita la misma rama (409) y la misma reemisión sin revelar el código.
- `RulesTest`: dos métodos renombrados con la terminología sintética.

### 3.5 Pruebas de la fase

| Momento | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Cierre de la Fase 2 | 162 | 20 997 | OK |
| **Cierre de la Fase 3** | **161** | **20 980** | **OK**: unitarias 72/20 185, integración 89/795, sin omisiones ni advertencias |

Diferencias: −5 pruebas eliminadas y +4 nuevas (3.4).

Otras comprobaciones:
- El verificador Go da OK con los archivos regenerados.
- `generate-fixtures.php --check` da OK.
- `php bin/check-secrets.php` no encuentra nada.
- Quedan 0 bases y 0 usuarios de prueba.

---

## Marca AD-EL POS (adelantada de la Fase 5)

Fecha: 2026-11-16. Por instrucción del propietario, se quitan desde ahora todas las menciones del producto anterior, y se usan los logos de `logos-adelpos/`.

### Logos

Copiados **sin modificar sus bytes** a `public/assets/brand/`:

| Archivo | Uso | SHA-256 |
| --- | --- | --- |
| `adel-pos-tema-claro.png` | Fondos claros: acceso en móvil, resumen, productos, verificación de constancias, página de mantenimiento y constancias PDF | `fece00410137a618703c5921d2ce2aac5ef06227836b75505201e58125e05434` |
| `adel-pos-tema-oscuro.png` | Fondos oscuros: barra lateral y panel de acceso | `82e1a563a24ebe59fa3676fbb8cae430da4ddc263034583a0f40bbd985e29116` |

- **Sin versiones verticales** (decisión del propietario). Donde había logo vertical se usa el horizontal del tema que corresponde.
- **Sin ícono de pestaña.** Se quitaron las referencias porque no hay ícono. Pendiente, si el propietario entrega uno.
- **Observación:** `adel-pos-tema-oscuro.png` tiene píxeles blancos sueltos alrededor de «ADEL». Se usa tal cual.
- `public/assets/app.css`: el logo de la barra lateral usa alto automático, porque el alto fijo de 90 px deformaba el PNG de proporción 3:1.

### Textos

- Nombre visible: **«AD-EL POS — Licencias»** en títulos, metadatos, pie del panel, pantalla de acceso, verificación de constancias y página de mantenimiento.
- La pantalla de acceso tenía un lema del producto anterior sobre bibliotecas digitales. Ahora dice «Licencias de AD-EL POS, en orden y bajo control.» y «Administración de licencias».
- `product-form.php`: se quitó la nota del «modo híbrido», que era del catálogo anterior y solo aparecía para ese producto.
- `products.php`: la tarjeta de `adel_pos` muestra su logo; los demás productos, su nombre.
- **Folio de constancias:** el prefijo `ADX-`, abreviatura del producto anterior, pasa a `ADELPOS-`.

### Constancias PDF

- Usan `adel-pos-tema-claro.png` (decisión del propietario). `CertificatePdf` verifica su SHA-256 fijo antes de usarlo y, si el archivo falta, muestra el nombre en texto.
- **Copia reducida en memoria.** Para el PDF se reduce a 812 px de ancho (300 ppp al ancho de 195 pt). El archivo original no se modifica.

  | Medida | PNG original | Copia reducida |
  | --- | --- | --- |
  | Peso de cada constancia | 324 KB | 74.5 KB |
  | Tiempo de generación | 0.65 s | 0.14 s |

  Cada constancia se guarda cifrada en la base, así que la diferencia se acumula.
- Se revisó visualmente una constancia sintética: el logo se ve nítido, con el nombre y el pie de AD-EL POS. Pesó 94.6 KB.
- Se eliminó `bin/render-brand.cjs`. Generaba el derivado PNG desde el SVG del producto anterior, y ya no hay SVG.

### Pruebas

- `ProductionHttpTest` comprueba que:
  - la pantalla de acceso dice «AD-EL POS — Licencias» y usa el logo oscuro;
  - los dos logos se sirven byte a byte, como `image/png`, con su SHA-256 original;
  - la página de mantenimiento muestra el nombre nuevo.
- Se quitó la aserción «la pantalla de acceso no contiene el nombre anterior», porque nombraba la marca. La comprobación queda en la búsqueda de cierre de abajo.

| Momento | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Cierre de la Fase 3 | 161 | 20 980 | OK |
| **Marca AD-EL POS** | **161** | **20 988** | **OK**: unitarias 72/20 185, integración 89/803 |

### Búsqueda de cierre

```sh
git grep -n -i -E 'aibid|aidex' -- . ':!*.md'      # 0 resultados
git grep -n -E 'ADX' -- . ':!*.md'                 # 0 resultados
```

**Quedan menciones solo en documentación (`*.md`):**

| Documento | Líneas | Por qué siguen |
| --- | --- | --- |
| `AVANCE.md` | 82 | Registro de procedencia y de cada cambio de esta etapa |
| `DEPLOY_UBUNTU_24_04.md`, `OPERATIONS.md`, `README.md`, `TEST_PLAN.md`, `SCHEMA.md`, `SECURITY.md`, `ARCHITECTURE.md` | 135 en total | Son los documentos del producto anterior, copiados como base. La Fase 6 los reescribe completos; cambiar solo los nombres dejaría afirmaciones falsas sobre un gestor documental. |

---

## Fase 4 — Retirar lo exclusivo del producto anterior

Fecha: 2026-11-16. Estado: **cerrada**.

### 4.1 Lo que se eliminó

- **Interfaz y constancias.** Ya no aparecen límites ni módulos del producto anterior:
  - en el formulario de licencia: «Usuarios ilimitados» y «Sin límites de documentos ni bibliotecas»;
  - en el detalle de licencia: «Usuarios y documentos: Sin límite»;
  - en las constancias PDF: «Usuarios permitidos» y «Módulos autorizados».

  AD-EL POS no cobra por usuario, y sus planes y cupos se definen en la etapa 5.
- **Ya quitado antes:** los mensajes y reglas del catálogo anterior (Fase 3) y la nota del «modo híbrido» (commit de marca).
- **Se conserva** la selección genérica de módulos del panel. Corresponde a `features` del contrato, que en V1 va vacío.
- **Pruebas del cliente real del producto anterior:** no quedaba ninguna referencia.
- **Documentación:** sus menciones de módulos y límites desaparecen con la reescritura de la Fase 6.

### 4.2 Motor de licencias heredado: marcado, sin cambios de lógica

- **Marcador.** La especificación pide `// HEREDADO AIDEX: se reemplaza en etapa 2–4 según CONTRATO_LICENCIAS_ADEL_POS.md`. Por la instrucción del propietario de no nombrar el producto anterior, el marcador es:

  ```
  HEREDADO: se reemplaza en etapa 2–4 según CONTRATO_LICENCIAS_ADEL_POS.md (<regla>)
  ```

  Va como `//` en PHP, `--` en SQL y `<?php /* … */ ?>` en plantillas, este último sin cambiar el HTML generado.
- **Dónde va:** solo donde la regla está fija en el código:
  - `max_installations=1` y una activación vigente por licencia;
  - tolerancia de 15 días;
  - `fingerprint_version` `'1'`;
  - la lista de límites.
- **Sin cambios de lógica:** el generador del contrato produce exactamente los mismos archivos (`--check` OK).

**Lista de puntos heredados, insumo para las etapas 2 a 4.** Se genera con:

```sh
git grep -n -o -E "HEREDADO: se reemplaza en etapa 2–4 según CONTRATO_LICENCIAS_ADEL_POS\.md \([^)]*\)"
```

Total: 20 marcas.

| Regla heredada | Ubicaciones |
| --- | --- |
| huella del equipo en el contenido de la licencia | `src/Application/RevisionPublisher.php:34` |
| huella del equipo, versión '1' | `contracts/v1/generate-fixtures.php:84`, `contracts/v1/generate-fixtures.php:143`, `src/Domain/OfflineRequest.php:29`, `src/Domain/Protocol.php:51` |
| lista de límites en el contenido de la licencia | `src/Application/RevisionPublisher.php:23` |
| lista de límites en las constancias | `src/Application/DocumentService.php:27` |
| tolerancia de 15 días en suscripción | `contracts/v1/generate-fixtures.php:102`, `database/migrations/001_esquema_inicial.sql:110`, `src/Application/LicenseService.php:31`, `templates/license-detail.php:4`, `templates/license-form.php:6`, `templates/license-form.php:8` |
| una instalación activa por licencia | `src/Application/ActivationService.php:75`, `src/Application/OfflineService.php:79` |
| una instalación por licencia | `templates/license-detail.php:4`, `templates/license-form.php:13` |
| una instalación por licencia y lista de límites | `contracts/v1/generate-fixtures.php:94`, `database/migrations/001_esquema_inicial.sql:127`, `src/Application/LicenseService.php:34` |

### 4.3 Paquete de despliegue (`bin/build-release.php`)

- **Lista explícita.** Toma `composer.json`, `composer.lock`, `config/local.example.php` y los siete documentos existentes. Se quitaron 11 documentos del producto anterior que ya no existen; antes el comando fallaba por ellos. `DECISIONS.md` y `docs/` se agregan en la Fase 6.
- **Solo archivos versionados.** De `bin/`, `src/`, `public/`, `templates/`, `database/` y `deploy/` toma únicamente lo que está en git (`git ls-files`). Antes recorría las carpetas del disco y habría metido archivos locales, como los `.DS_Store` de macOS.
- **Barrera adicional.** Aborta si aparece `config/local.php`, algo bajo `var/`, un `.DS_Store` o un archivo `.key` o `.pem`.
- **Sin cambios:** el prefijo `adelpos-license/`, el manifiesto `RELEASE.json` con el SHA-256 de cada archivo, el `.sha256` del paquete y la exclusión de `vendor/`, pruebas y `contracts/`.
- **Paquete real de prueba:** 120 archivos más `RELEASE.json`, 1.4 MB.

**Nueva prueba: `tests/Unit/ReleasePackageTest.php` (2 pruebas).**
1. Arma el paquete desde un repositorio temporal con los archivos versionados actuales. En él siembra:
   - `config/local.php`;
   - una llave bajo `var/keys/`;
   - un respaldo `.adelposbackup`;
   - un `.DS_Store` en `public/`;
   - un `.key` en `bin/`;
   - un PHP sin versionar en `src/`.

   Exige que el paquete contenga exactamente los archivos permitidos, y nada de configuración local, `var/`, pruebas, `vendor/`, `contracts/`, `AVANCE.md` ni artefactos. También comprueba el manifiesto contra cada archivo y el `.sha256` del paquete.
2. Si alguien versiona una llave a la fuerza, el empaquetado se aborta sin dejar archivos.

### 4.4 Pruebas de la fase

| Momento | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Marca AD-EL POS | 161 | 20 988 | OK |
| **Cierre de la Fase 4** | **163** | **21 368** | **OK**: unitarias 74/20 565, integración 89/803, sin omisiones ni advertencias |

Diferencia: +2 pruebas y +380 aserciones, todas de `ReleasePackageTest`.

Otras comprobaciones:
- El verificador Go da OK y `generate-fixtures.php --check` también.
- `php bin/check-secrets.php` no encuentra nada.
- No hay menciones de `aibid` ni `aidex` fuera de `*.md`.

---

## Fase 5 — Marca y textos

Fecha: 2026-11-16. Estado: **cerrada**. El nombre, los logos y las constancias se adelantaron en el commit «Marca AD-EL POS» (sección anterior). Esta fase completa la revisión de textos y las pruebas del panel.

### 5.1 Textos en español claro

**26 textos cambiados en 14 archivos** (plantillas, mensajes de `src/`, títulos de página y nombres de permisos):

| Antes | Ahora |
| --- | --- |
| «offline» / «online» (menú, títulos, solicitudes, entregas, transferencias, mensajes, constancias) | «sin internet» / «en línea» |
| «MFA» (emisión, seguridad, administradores) | «verificación en dos pasos» |
| «ID de solicitud» | «Identificador de la solicitud» |
| «Huella · versión» | «Huella del equipo · versión» |
| «Descargar archivo(s) offline» | «Descargar archivo(s) de licencia (.lic)» |
| «Verifica firma Ed25519, producto, identidad y revisión.» (constancia) | «AD-EL POS comprueba la firma, el producto, la identidad del equipo y la revisión.» |

Otros cambios:
- **Auditoría.** 16 operaciones se mostraban con su clave interna; por ejemplo, «credential.disclosed_on_issue» aparecía en «Actividad reciente». Ahora tienen etiqueta en español, y «Segundo factor configurado» pasa a «Verificación en dos pasos configurada». La prueba nueva `tests/Unit/AuditLabelsTest.php` recorre `src/` y falla si una operación queda sin etiqueta. Se comprobó que, sin las etiquetas, lista exactamente las que faltaban.
- **Frase eliminada.** «Las credenciales antiguas de desarrollo no son recuperables» (detalle de licencia) ya no aplica: el esquema exige la copia protegida.
- **Términos técnicos que se conservan a propósito**, porque el panel los necesita y están en español:
  - las extensiones `.lic` y `.licreq`;
  - «SHA-256 del PDF original», el dato que el cliente debe comparar;
  - «Clave pública de instalación» y «Huella del equipo».

### 5.2 Pruebas del panel con Playwright

- **Entorno:** Playwright 1.63.0, la versión fijada en `package-lock.json`, instalado con `npm ci --ignore-scripts`, sin descargar navegadores, y Google Chrome 157 del equipo.
- **Nuevo `tests/run-panel-tests.php`.** Hace cuatro cosas:
  - prepara los datos sintéticos;
  - levanta el servidor local (`panel`) o la pila Nginx + PHP-FPM + TLS (`trusted`);
  - ejecuta el script de Playwright;
  - **siempre** elimina la base, el usuario, las llaves y la configuración temporal.

  La conexión se toma de las variables `TEST_*`, sin contraseñas en el código.
- **Ajustes a los scripts:**
  1. **Marca.** `panel.cjs` comprueba, en la pantalla de acceso, el resumen y la vista móvil, que el título termina en «AD-EL POS — Licencias», que se ve un logo `adel-pos-tema-*.png` y que ninguna imagen visible falla al cargar.
  2. **Producto.** Los dos formularios de emisión eligen `producto_prueba`, porque el esquema inicial ahora también trae `adel_pos` y el formulario lo mostraba primero.
  3. **Defecto heredado del producto anterior.** La renovación sin internet escribía una hora UTC y esperaba recibirla igual, pero el panel interpreta las fechas en `America/Monterrey`, como indica junto al campo, y firma en UTC. La conversión entró en el commit `0759474` del origen (2026-10-22 12:35) y la prueba se actualizó por última vez en `c0e0305` (10:45 del mismo día): nunca se ajustó. Ahora la prueba calcula el UTC esperado con las reglas de la zona horaria. El código no cambia.
  4. **Ícono de pestaña.** No hay ícono, así que el navegador pide `/favicon.ico` y recibe 404. Los scripts ya intentaban ignorar ese error, pero filtraban por el texto del mensaje, y Chrome ya no incluye ahí la URL. Ahora filtran por la URL del recurso, y cualquier otro error se reporta con su URL.
  5. **Datos sintéticos.** Los clientes de archivos y bibliotecas pasan a ser restaurantes: «Restaurante del Norte», «Cocina Regional», «Café Central» y «Restaurante de Pruebas».
- **Resultados:**
  - **Panel OK:** acceso, verificación en dos pasos, roles, CSRF y origen, emisión, mantenimiento, renovación, flujo sin internet completo (importar, revisar, aprobar, rechazar, descargar), transferencia forzada con nueva autenticación, desactivación firmada y navegación en móvil.
  - **Dispositivos de confianza OK:** sobre Nginx, PHP-FPM y TLS reales.
  - **12 capturas** en `var/screenshots/` (ignorada por git). Se revisaron el acceso, el resumen, el detalle de licencia y la vista móvil.

### 5.3 Búsqueda de cierre

```sh
git grep -n -i -E "aidex|gestor documental|gestor_documental" -- templates public src   # 0 resultados
```

### 5.4 Pruebas de la fase

| Momento | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Cierre de la Fase 4 | 163 | 21 368 | OK |
| **Cierre de la Fase 5** | **164** | **21 370** | **OK**: unitarias 75/20 567, integración 89/803 |

Diferencia: +1 prueba y +2 aserciones, de `AuditLabelsTest`. Además: verificador Go OK, Playwright OK en los dos scripts, revisión de secretos sin hallazgos y 0 bases o usuarios de prueba residuales.

**Pendientes del propietario:**
- un ícono de pestaña, si quiere tenerlo;
- el logo oscuro tiene píxeles sueltos alrededor de «ADEL» (observación; se usa tal cual).
