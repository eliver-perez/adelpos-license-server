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
| 6 — Documentación y cierre | **Cerrada.** 164 pruebas, 21 384 aserciones, todas OK. Playwright OK. Etapa 1 cerrada. |

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

---

## Fase 6 — Documentación y cierre

Fecha: 2026-11-16. Estado: **cerrada**.

### 6.1 Contrato

`docs/CONTRATO_LICENCIAS_ADEL_POS.md` se copió **sin cambios** (comparado con `cmp`). SHA-256: `a4c3bfd0ec428b7dc14434e294d6fbb10940c8992718b7d4db2a0703f1f554bb`, registrado en `DECISIONS.md`.

### 6.2 `DECISIONS.md`, escrito desde cero

Contiene:
- la procedencia (fecha de la copia, commit de origen y SHA-256 de su `composer.lock`);
- el SHA-256 del contrato;
- las decisiones del propietario, con referencia a la sección 14 del contrato y las tomadas en esta etapa;
- el endurecimiento del esquema;
- 13 decisiones técnicas con su motivo;
- el marcador de herencia;
- los SHA-256 de los archivos de marca.

### 6.3 Documentos reescritos para AD-EL POS

| Documento | Contenido | Herencia temporal señalada |
| --- | --- | --- |
| `README.md` | Requisitos, instalación, llaves de firma, pruebas, revisión de secretos, paquete, índice y procedencia breve | Tabla por etapa, de la 2 a la 5 |
| `ARCHITECTURE.md` | Límites, componentes, panel, API `/v1`, firma, flujo sin internet, auditoría, respaldos, constancias y datos protegidos | Protocolo `LIC-V1`/`LICREQ-V1`/`lic+jws` y puntos `HEREDADO` (etapa 2); tipos, fechas, renovación y planes (etapa 3); transferencias y alertas (etapa 4); pantallas, constancias y VPS (etapa 5) |
| `SCHEMA.md` | Las 33 tablas por área, más `schema_migrations`; datos iniciales, endurecimiento, permisos por tabla, transacciones, migrador y prueba de equivalencia | `license_limits`, `chk_license_terms` (15 días), huella, acciones de desafío, entornos de firma y módulos |
| `SECURITY.md` | Amenazas, secretos y contextos HKDF, llaves de firma, acceso, sesiones, límites de intentos, API, flujo sin internet, constancias, auditoría, respaldos, repositorio y paquete; qué queda fuera del alcance | Entornos de firma `test`/`production` (etapa 2), protocolo y huella (etapa 2), una instalación y 15 días (etapas 2 a 4), alertas (etapa 4) |
| `OPERATIONS.md` | Configuración, base de datos, administradores, llaves, operación sin internet, mantenimiento, respaldos, revisión previa, paquete, revisión de secretos y vigilancia | Tabla por etapa y puntos `HEREDADO` |
| `TEST_PLAN.md` | Requisitos, comandos, datos sintéticos y limpieza, qué cubre cada archivo de prueba, Playwright y qué se probará en las etapas 2 a 5. Las cifras remiten a este archivo. | Pruebas del motor heredado y su sustitución por etapa |
| `DEPLOY_UBUNTU_24_04.md` | **Solo se renombró**: usuario, pool, rutas, servicios, variables, cuentas de MySQL y dominio de pruebas `licensing.sicrat.com`. Tiene una nota de que la revisión completa es de la etapa 5, las versiones del VPS y la sección «Convivencia» con recursos separados. | El ejemplo de configuración del cliente queda como referencial (integración de AD-EL POS) |

- **Cifras y evidencias históricas:** se quitaron todas. Los enlaces apuntan solo a archivos que existen.
- **Correcciones hechas durante la revisión, contrastando los documentos con el código:**
  - la guía de despliegue no instalaba `php8.3-gd`, aunque `composer.json` lo exige; ahora sí;
  - la guía describía las migraciones antiguas; ahora describe la migración única;
  - la guía citaba las versiones de MySQL de las pruebas del origen; ahora cita MySQL 8.0.46;
  - dos operaciones de auditoría registradas desde `bin/` no tenían etiqueta en español: `maintenance.cleanup` y `admin.recovered_via_cli`. Ya la tienen, y `AuditLabelsTest` revisa ahora también `bin/`;
  - dos mensajes de la terminal decían «MFA»; ahora dicen «verificación en dos pasos»;
  - `npm run test:panel` ejecuta ahora `tests/run-panel-tests.php all`.
- **Diferencia entre la documentación anterior y el código, que queda pendiente para la etapa 5:** `deploy/nginx.conf.example` no configura `limit_req`. Los límites de intentos los aplica solo la aplicación, y la respuesta JSON de Nginx para 429 nunca se activa.

### 6.4 Ícono de pestaña

**Decisión del propietario (2026-11-16):** usar `logos-adelpos/icon.png`.
- **Archivo:** se copió sin modificar a `public/assets/brand/icon.png` (1024 × 1024, SHA-256 `241504ff0356a39044862474bf3b071d82418a20763f001ba5e2f6287804456c`).
- **Dónde se enlaza:** el panel, la verificación de constancias y la página de mantenimiento.
- **Pruebas:**
  - `ProductionHttpTest` comprueba el enlace y que el archivo se sirve intacto;
  - `panel.cjs` comprueba que el enlace existe y carga.
- **Filtro de `/favicon.ico` en Playwright:** se conserva para las peticiones que Chrome hace por su cuenta, por ejemplo en descargas.

### 6.5 Paquete de despliegue

`bin/build-release.php` y `ReleasePackageTest` incluyen ahora `DECISIONS.md` y `docs/CONTRATO_LICENCIAS_ADEL_POS.md`.

### 6.6 Historial sin secretos

Se revisaron todos los commits de la etapa:

| Comprobación | Resultado |
| --- | --- |
| Rutas agregadas alguna vez (`config/local.php`, `var/` salvo `.gitkeep`, `vendor/`, `node_modules/`, `.phpunit.cache/`, `.DS_Store`, `*.key`, `*.pem`) | Ninguna |
| Llaves privadas PEM en cualquier versión | Ninguna. Solo `SecretsCheckTest` las arma por partes, en memoria. |
| `bin/check-secrets.php` sobre cada commit, en un árbol de trabajo temporal | Sin hallazgos desde la Fase 2. Los cuatro commits anteriores solo marcan las 4 semillas de `contracts/v1/fixtures/vectors.json`; se verificó que todas son las semillas **públicas** de prueba derivadas del prefijo anterior, que la revisión actual ya no reconoce. |

---

## Cierre de la etapa 1

### Línea base contra resultado final

| Momento | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Línea base del producto de origen (Fase 0) | 147 | 20 904 | 146 OK, 1 error (respaldos con MySQL 8.0) |
| **Final de la etapa 1** | **164** | **21 384** | **Todas OK**: unitarias 75/20 576, integración 89/808, sin omisiones ni advertencias |

**Cambios en el número de pruebas, cada uno justificado:**

| Cambio | Pruebas | Detalle |
| --- | --- | --- |
| Excluidas desde la Fase 0 | 0 | Las 2 del cliente real nunca estuvieron en `phpunit.xml` (Fase 0). |
| Nuevas | +22 | `SecretsCheckTest` (14), `ReleasePackageTest` (2), `InitialSchemaTest` (4), `ProtectedRecordTest::testRecordPurposesCannotAliasTheOfflineEvidenceKeyContext` (1) y `AuditLabelsTest` (1). |
| Eliminadas | −5 | Rutas de actualización de la historia anterior, sustituidas por `InitialSchemaTest` (Fase 3). |
| **Total** | **147 + 22 − 5 = 164** | |

**Aserciones:** las variaciones se explican fase por fase (secciones 1.6, 2.4, 3.5, 4.4, 5.4 y la marca).

### Otras comprobaciones finales

- **Verificador Go:** «OK: 5 JWS, 3 proofs, 3 offline requests, 3 negative JWS», con los archivos de prueba regenerados.
- **`php contracts/v1/generate-fixtures.php --check`:** OK.
- **Playwright:** panel y dispositivos de confianza OK, con la marca nueva y el ícono.
- **`php bin/check-secrets.php`:** sin hallazgos.
- **Paquete de despliegue:** 123 archivos más `RELEASE.json`, 1.8 MB; incluye `DECISIONS.md`, el contrato y el ícono; sin `vendor/`, pruebas, `var/`, `contracts/` ni configuración local.
- **Búsquedas de cierre:**
  - `git grep -n -i -E 'aibid|aidex' -- . ':!*.md'` da 0 resultados.
  - En la documentación quedan solo las menciones permitidas: procedencia en `README.md` y `DECISIONS.md`, convivencia en el VPS en `DEPLOY_UBUNTU_24_04.md` y el registro de esta etapa en `AVANCE.md`.
- **Residuos:** 0 bases y 0 usuarios de prueba en MySQL.

### Criterios de aceptación

- [x] El repositorio es independiente, con un primer commit de la copia limpia y commits por fase. La Fase 1 tiene 4 commits: copia base, compatibilidad con MySQL 8.0 por decisión del propietario, funcionamiento sin los logos anteriores y cierre. Además hay un commit de marca, adelantado por instrucción del propietario.
- [x] Ningún secreto, llave privada, configuración local ni artefacto del producto anterior está en el historial (6.6).
- [x] No hay referencias a la identidad del producto anterior fuera de las menciones de procedencia (búsquedas de cierre).
- [x] Hay un único esquema inicial, con prueba de equivalencia contra la cadena original, y los datos iniciales son solo `adel_pos`, sin módulos (Fase 3).
- [x] La suite completa pasa. Las pruebas eliminadas y nuevas están justificadas arriba.
- [x] El verificador en Go pasa con los archivos de prueba regenerados.
- [x] El paquete de despliegue se genera sin secretos (`ReleasePackageTest`).
- [x] El panel muestra la marca de AD-EL POS, con logos e ícono (Playwright y capturas revisadas).
- [x] Los puntos heredados están marcados y listados para las etapas 2 a 4 (Fase 4: 20 puntos).
- [x] El contrato está copiado sin cambios y su SHA-256 está registrado (6.1).

### Pendientes para etapas posteriores

- **Etapa 2:**
  - implementar el contrato de AD-EL POS: contenido de la licencia, rutas, prueba de posesión y archivos de prueba nuevos;
  - separar los entornos de firma `test` y `production`.

  `config/local.example.php` ya usa `licensing.sicrat.com`.
- **Etapas 2 a 4:** reemplazar los 20 puntos marcados como heredados (Fase 4).
- **Etapa 5:**
  - pantallas nuevas del panel y contenido de las constancias (planes y cupos);
  - revisión completa de `DEPLOY_UBUNTU_24_04.md` y despliegue en el VPS.
- **Etapa 5:** configurar `limit_req` en Nginx o retirar la respuesta 429 de su ejemplo (6.3).
- **Observación del propietario:** el logo oscuro tiene píxeles blancos sueltos alrededor de «ADEL».

### Comandos para reproducir la verificación

```sh
cd adelpos-license
composer install
export TEST_MYSQL_DSN='mysql:unix_socket=/tmp/mysql.sock;charset=utf8mb4' TEST_MYSQL_USER=root
export TEST_MYSQL_PASSWORD='<contraseña de root de pruebas>'
export TEST_MYSQL_BIN=/usr/local/mysql/bin/mysql TEST_MYSQLDUMP_BIN=/usr/local/mysql/bin/mysqldump
export TEST_FPM_BIN=/opt/homebrew/sbin/php-fpm TEST_NGINX_BIN=/opt/homebrew/bin/nginx
export TEST_OPENSSL_BIN=/opt/homebrew/opt/openssl@3/bin/openssl TEST_PDFTOTEXT_BIN=/opt/homebrew/bin/pdftotext

php vendor/bin/phpunit                                  # suite completa (MySQL 8.0 aislado)
php contracts/v1/generate-fixtures.php --check          # archivos de prueba del contrato
env GOTOOLCHAIN=local GOPROXY=off GOSUMDB=off go run contracts/v1/verify-vectors.go contracts/v1/fixtures/vectors.json
npm ci --ignore-scripts --no-audit --no-fund
php tests/run-panel-tests.php all                       # Playwright, con limpieza garantizada
php bin/check-secrets.php                               # antes de cada commit
php bin/build-release.php --output=/ruta/fuera/de/public/adelpos-license.tar.gz
git grep -n -i -E 'aibid|aidex' -- . ':!*.md'           # debe dar 0 resultados
```

---

# Avance — Etapa 2: contrato (licencia, firma y API)

Especificación: `docs/ETAPA_2_CONTRATO_LICENCIA_FIRMA_API.md`. Fuente de verdad: `docs/CONTRATO_LICENCIAS_ADEL_POS.md` V1.0, que no se modifica.

| Fase | Estado |
| --- | --- |
| 0 — Verificación contra el código | **Cerrada.** 164 pruebas, 21 384 aserciones, todas OK. Sin cambios de código. El propietario aprobó las 7 consultas (E2-0.5). |
| 1 — Entornos de firma | **Cerrada.** 176 pruebas, 21 578 aserciones, todas OK. Verificador Go OK. Playwright OK. |
| 2 — Modelo de datos | **Cerrada.** 186 pruebas, 21 918 aserciones, todas OK. Verificador Go OK. Playwright OK. Quedan 14 de las 20 marcas de herencia. |
| 3 — Motor de fechas | **Cerrada.** 258 pruebas, 26 242 aserciones, todas OK. Verificador Go OK. |
| 4 — Contenido y firma de la licencia | Pendiente |
| 5 — API en línea | Pendiente |
| 6 — Archivos sin internet | Pendiente |
| 7 — Archivos del contrato y verificador de referencia | Pendiente |
| 8 — Panel: emisión con el modelo nuevo | Pendiente |
| 9 — Cierre | Pendiente |

---

## Etapa 2 · Fase 0 — Verificación contra el código (sin cambios)

Fecha: 2026-11-16. No se modificó código, pruebas ni configuración. El commit de la fase contiene solo este registro y la especificación de la etapa.

### E2-0.1 Estado de partida

| Comprobación | Resultado |
| --- | --- |
| Commit | `ec61425` «Fase 6: documentación y cierre de la etapa 1», rama `main` |
| Árbol de trabajo | Limpio, salvo `docs/ETAPA_2_CONTRATO_LICENCIA_FIRMA_API.md`, que estaba sin versionar. Se versiona en el commit de esta fase. No entra en el paquete de despliegue, que toma `docs/` por lista explícita. |
| Contrato | SHA-256 `a4c3bfd0ec428b7dc14434e294d6fbb10940c8992718b7d4db2a0703f1f554bb`: el registrado en `DECISIONS.md` e idéntico a la copia de la carpeta superior |
| Suite completa (`phpunit.xml`) | **164 pruebas, 21 384 aserciones, todas OK.** Unitarias 75/20 576, integración 89/808. Sin omisiones ni advertencias. Duración: 1 min 4 s. |
| Verificador Go | «OK: 5 JWS, 3 proofs, 3 offline requests, 3 negative JWS; PHP/Go signatures match.» |
| `generate-fixtures.php --check` | «Verified 5 JWS, 3 proofs, 3 offline requests and 3 negative JWS fixtures.» |
| `bin/check-secrets.php` | Sin hallazgos en 175 archivos |
| Residuos en MySQL | 0 bases y 0 usuarios `adelpos_license_test_*`, antes y después |

Entorno: el mismo de la etapa 1. PHP 8.5.7, PHPUnit 12.5.36, MySQL 8.0.46 en `/tmp/mysql.sock`, Go 1.25.5. Las variables `TEST_*` son las de «Comandos para reproducir la verificación» de la etapa 1.

Playwright no se ejecutó: la Fase 0 no lo pide. Se actualiza y se ejecuta en las fases 8 y 9, igual que en la etapa 1.

**Observación:** en la carpeta temporal del sistema quedan dos registros de Playwright de la etapa 1 (`adelpos-license-panel-*.log`, 66 KB y 77 KB). `tests/run-panel-tests.php` crea ese registro y no lo borra. Se corrigió en la Fase 1 (E2-1.3).

### E2-0.2 Los 20 puntos heredados y la fase que los resuelve

Lista regenerada con el comando de `DECISIONS.md`, sección 6. Da 24 coincidencias: **20 marcas en el código**, las mismas de la etapa 1, y 4 menciones del propio marcador en la documentación (`ARCHITECTURE.md`, `AVANCE.md`, `DECISIONS.md` y `README.md`).

| # | Ubicación | Regla marcada | Fase que la resuelve |
| --- | --- | --- | --- |
| 1 | `database/migrations/001_esquema_inicial.sql:110` | Tolerancia de 15 días (`chk_license_terms`) | **2.** Una migración nueva reemplaza la restricción. La línea del comentario depende de la consulta 1. |
| 2 | `src/Application/LicenseService.php:31` | Tolerancia de 15 días al emitir | **2** |
| 3 | `database/migrations/001_esquema_inicial.sql:127` | Tabla `license_limits` | **4.** Una migración nueva elimina la tabla (consulta 2). La línea del comentario depende de la consulta 1. |
| 4 | `src/Application/LicenseService.php:34` | Inserta `license_limits` con una instalación | **4** |
| 5 | `src/Application/RevisionPublisher.php:23` | Lista de límites en el contenido firmado | **4** |
| 6 | `src/Application/RevisionPublisher.php:34` | Huella del equipo en el contenido firmado | **4** |
| 7 | `src/Domain/Protocol.php:51` | Huella versión `'1'` en la API | **5** |
| 8 | `src/Application/ActivationService.php:75` | Una instalación activa por licencia (en línea) | **5.** Reactivación de la misma instalación y `ACTIVATION_LIMIT` con `details`. |
| 9 | `src/Domain/OfflineRequest.php:29` | Huella versión `'1'` en `.licreq` | **6** |
| 10 | `src/Application/OfflineService.php:79` | Una instalación activa por licencia (sin internet) | **6** |
| 11 | `contracts/v1/generate-fixtures.php:84` | Huella en las licencias de prueba | **7** |
| 12 | `contracts/v1/generate-fixtures.php:94` | Una instalación y lista de límites en las licencias de prueba | **7** |
| 13 | `contracts/v1/generate-fixtures.php:102` | Tolerancia de 15 días en la suscripción de prueba | **7** |
| 14 | `contracts/v1/generate-fixtures.php:143` | Huella en los `.licreq` de prueba | **7** |
| 15 | `src/Application/DocumentService.php:27` | Lista de límites en las constancias | **8.** Deja de leer la tabla en la Fase 4 y muestra plan y cupos en la Fase 8. |
| 16 | `templates/license-detail.php:4` | «Instalaciones: 1 autorizada» | **8** |
| 17 | `templates/license-detail.php:4` | Texto de los 15 días de tolerancia | **8** |
| 18 | `templates/license-form.php:6` | «15 días de tolerancia» | **8** |
| 19 | `templates/license-form.php:8` | «La tolerancia dura 15 días…» | **8** |
| 20 | `templates/license-form.php:13` | «Una instalación por licencia» | **8** |

Por fase: 2 en la Fase 2, 4 en la Fase 4, 2 en la Fase 5, 2 en la Fase 6, 4 en la Fase 7 y 6 en la Fase 8.

**Ninguno pasa completo a la etapa 3 o 4.** Al resolverlos quedan estas partes, que se marcarán en el cierre (Fase 9):

| Marca nueva | Dónde |
| --- | --- |
| `PENDIENTE etapa 3` | Renovación con fecha de pago y regla de tolerancia, conversión de prueba a pago y compra de mantenimiento: operaciones deshabilitadas en `LicenseService`, `OfflineService`, `Kernel` y sus plantillas. |
| `PENDIENTE etapa 3` | Editor de planes y propagación: en esta etapa la tabla `plans` solo se lee. |
| `PENDIENTE etapa 4` | Acción `transfer`, ruta `/v1/activations/transfer`, `TRANSFER_LIMIT` y el cálculo real de `details.transfer_available`. |
| `PENDIENTE etapa 4` | Relación de la transferencia forzada del panel con el autoservicio y las alertas. |

### E2-0.3 Inventario: dónde se construye y se valida cada cosa

| Tema | Archivos | Qué hace hoy |
| --- | --- | --- |
| Contenido de la licencia | `src/Application/RevisionPublisher.php` (`publish`, `commercialChange`), `src/Domain/Jws.php`, `src/Domain/Protocol.php` (`json`, `utc`) | Arma 18 campos desde `licenses`, `activations`, `license_features` y `license_limits`, firma y guarda la revisión. Incrementa `licenses.revision_counter`, que ya es global por licencia. No valida el contenido antes de firmar. |
| Entradas de la API | `src/Http/ApiKernel.php` (rutas, tamaño, tipo de contenido, límites por IP), `src/Domain/Protocol.php` (`input`, `proofMessage`, `uuid`, `decode`), `src/Domain/StrictJson.php`, `src/Application/ActivationService.php`, `src/Domain/ApiProblem.php`, `src/Domain/ApiResponse.php` | Cuatro rutas `POST /v1/activations[/challenge\|/refresh\|/deactivate]`. Campos exactos, sin sobrantes. Reintentos en `license_requests`. |
| Solicitudes sin internet | `src/Domain/OfflineRequest.php` (sobre, campos y firma), `src/Application/OfflineService.php` (`import`, `decide`, `release`), `src/Http/Kernel.php` (rutas `/admin/offline/…` y `/admin/licenses/{id}/release`), `templates/offline-detail.php`, `templates/license-offline.php` | Importa, guarda el original cifrado, aprueba o rechaza y firma. La aprobación de `renew` puede ampliar la vigencia (`renewal_mode=extend`). |
| Llaves de firma | `src/Infrastructure/SigningKeys.php`, `bin/signing-key.php`, `bin/preflight.php` (`--require-signer`), tablas `signing_keys` y `signing_scopes`, `src/Infrastructure/RecoveryVerifier.php` | El entorno de firma **es `APP_ENV`** (`production`, `development` o `testing`). Carpeta `var/keys/<APP_ENV>`. Rechaza las cuatro semillas públicas de prueba solo si `APP_ENV=production`. |
| Configuración | `src/Config.php`, `config/local.example.php`, `bin/configure.php`, `bin/recovery-config.php` | No existe `LICENSE_ENVIRONMENT`. |
| Límites | Tabla `license_limits` (`max_installations=1`, `max_users`, `max_libraries`, `max_documents`), `LicenseService::issue`, `RevisionPublisher`, `DocumentService::generate`, `templates/certificate-pdf.php`, `bin/grants.php` | Solo `max_installations` tiene efecto: la constancia lo imprime. No hay planes ni cupos. |
| Emisión | `src/Application/LicenseService.php` (`issue`), `src/Http/Kernel.php` (`/admin/licenses/create`), `templates/license-form.php` | Tipos `perpetual` y `subscription`. El administrador escribe a mano el vencimiento o la fecha de versiones. Tolerancia fija de 15 días. |
| Renovación y mantenimiento | `LicenseService::change` (`renew`, `maintenance`), `renewTerms`, `recordOfflineRenewal`, `OfflineService::decide` (`renewal_mode=extend`), `Kernel` (`/admin/licenses/{id}/renew\|maintenance`), `templates/license-detail.php` (formulario lateral), `templates/offline-detail.php`, tablas `subscription_periods` y `maintenance_purchases` | Fecha nueva escrita a mano, sin regla de pago ni de tolerancia. |
| Otras operaciones comerciales | `LicenseService::change` (`features`, `reissue`, `revoke`), `LicenseService::administrative` | Se conservan. `revoke` firma `revoked` para la activación vigente. |
| Clientes | `src/Application/CatalogService.php` (`saveCustomer`), `templates/customer-form.php`, `src/Http/View.php` | Sin zona horaria. El panel muestra y lee todas las fechas en `America/Monterrey`, fijo en `View::TIMEZONE`. |
| Archivos del contrato | `contracts/v1/generate-fixtures.php`, `contracts/v1/verify-vectors.go`, `contracts/v1/fixtures/` (10 archivos), `tests/Unit/ProtocolTest.php` | Protocolo heredado con el producto sintético. El verificador Go solo comprueba firmas. No hay JSON Schema ni OpenAPI. |

Tamaño del cambio, sin contar documentación ni archivos generados:

| Rastro | Archivos | Ocurrencias |
| --- | --- | --- |
| `installation_id` | 20 | 63 |
| `fingerprint_version` / `fingerprint_hash` | 16 | 56 |
| `license_limits` y `max_*` | 8 | 35 |
| `entitled_release_until` | 17 | 28 |
| `maintenance_until` | 9 | 24 |
| `LICREQ-V1` | 8 | 8 |
| `lic+jws` | 5 | 6 |
| `LIC-V1` como prefijo de la prueba de posesión | 3 | 3 |
| `server_id`, `status_reason`, `LICENSE_ENVIRONMENT` | 0 | 0 |

Pruebas del motor heredado que se reescriben: `ActivationsTest` (20), `OfflineTest` (18), `AdministrationTest` (15, de las que 4 son de renovación y mantenimiento), `HttpApiTest` (2), `ProtocolTest` (6), `OfflineRequestTest` (4), más los datos de `BackupTest`, `CommercialDeliveryTest`, `ProductionHttpTest` y los scripts de Playwright.

### E2-0.4 Diferencias entre el contrato y el código

**Formatos y firma (§2 y §3)**

| Punto | Contrato | Código actual |
| --- | --- | --- |
| Identidad de la instalación | `server_id`, UUID v4 **en minúsculas** | `installation_id`. Acepta mayúsculas y las usa tal cual en la prueba de posesión (`testUppercaseUuidBytesAreUsedInProofWithoutNormalization`). |
| Encabezado JWS | `typ: adelpos-lic+jws` | `typ: lic+jws`. El orden `alg`, `kid`, `typ` ya coincide. |
| Entornos de firma | `test` y `production`, independientes del entorno de la aplicación | `APP_ENV` hace de entorno de firma. La restricción `chk_signing_environment` solo admite `production`, `development` y `testing`. |
| Semillas públicas de prueba | Rechazadas en `production` | Rechazadas con `APP_ENV=production`. Los nombres de las llaves de prueba cambian con los archivos nuevos. |
| Instantes | Seis decimales y `Z` | Ya coincide (`Protocol::utc`). |
| Base64url | Sin relleno; se rechaza lo no canónico | Ya coincide (`Protocol::decode`). |

**Contenido de la licencia (§4)**

| Campo | Contrato | Código actual |
| --- | --- | --- |
| `environment`, `licensee_name`, `billing_period`, `status_reason`, `period_starts_at`, `updates_until`, `plan` | Obligatorios | No existen |
| `server_id` | Sí | Se llama `installation_id` |
| `fingerprint_version`, `fingerprint_hash`, `maintenance_until`, `entitled_release_until` | No existen | Se firman |
| `license_type` | `perpetual`, `subscription` o `trial` | Sin `trial` |
| `grace_days` | 5 en suscripción; 0 en prueba y perpetua | 15 en suscripción; 0 en perpetua |
| `limits` | `cash_registers`, `order_takers`, `kitchen_displays`, de 0 a 999 | `max_installations`, `max_users`, `max_libraries`, `max_documents` |
| `features` | Solo los módulos habilitados, como `clave → true` | Todos los módulos del producto, con `true` o `false` |
| `license_status` | `active` o `revoked` | Ya coincide, con la misma regla |
| `license_revision` | Contador global por licencia | Ya coincide |
| Orden de los campos | El del ejemplo del contrato | Otro orden |
| Validación antes de firmar | Sí (etapa 2, Fase 4) | No existe |

**Fechas (§5)**

| Punto | Contrato | Código actual |
| --- | --- | --- |
| Quién fija las fechas | El servidor, al activar en línea o al aprobar sin internet | El administrador, a mano, al emitir |
| Licencia emitida sin activar | Sin fechas | Con fechas desde la emisión. `chk_license_terms` exige vencimiento en toda suscripción. |
| Meses de calendario con día ancla | Sí | No hay cálculo. `modify('+1 month')` de PHP no sirve: 31 de enero más un mes da 3 de marzo. |
| Zona horaria | La del cliente | `America/Monterrey` fija en el panel; el cliente no tiene zona |
| Pruebas (15 días, 1 mes, 3 meses) | Sí | No existen |
| Actualizaciones de la perpetua | 0, 6 o 12 meses desde la activación | Fecha escrita a mano |

**API en línea (§8)**

| Punto | Contrato | Código actual |
| --- | --- | --- |
| Rutas | Las cuatro actuales, más `transfer` (etapa 4) | Las cuatro ya coinciden |
| Desafío | `action`, `product_id`, `server_id`, `activation_id` | Igual, con `installation_id`. `transfer` ya se rechaza con `INVALID_REQUEST`. Los 5 minutos y el uso único ya coinciden. |
| Prueba de posesión | Prefijo `ADELPOS-LIC-V1` y `server_id` | Prefijo `LIC-V1` e `installation_id`. La estructura del mensaje ya coincide. |
| Activar: campos | 8 campos | 10: sobran `fingerprint_version` y `fingerprint_hash` |
| `app_version` | Se recibe | Se valida (hasta 100 caracteres) y **no se guarda** |
| La misma instalación vuelve a activar | Devuelve su revisión vigente | Responde `ACTIVATION_LIMIT` |
| Otra instalación | `409 ACTIVATION_LIMIT` con `details.transfer_available` | `409 ACTIVATION_LIMIT`, sin `details` |
| Consultar actualización | Devuelve la revisión vigente, aunque la activación haya terminado | Ya coincide |
| Desactivar: respuesta | `activation_id`, `deactivated_at`, `status`, `license_jws`, `server_time`, `request_id` | Solo los tres primeros |
| Desactivar una activación revocada | No lo define (propuesta V1.1 n.º 5) | `403 REVOKED` |
| Formato de error | Incluye `details` y `request_id` «UUID o null» | Sin `details`. `request_id` nunca es `null`: si falta, el servidor inventa uno. |
| `request_id` reutilizado con otro contenido | `409 REQUEST_CONFLICT` | `409` con código `INVALID_REQUEST` |
| `TRANSFER_LIMIT`, `REQUEST_CONFLICT` | Definidos | No existen en `ApiProblem` |
| HTTP de `INVALID_REQUEST` | 400 | 400, y además 403, 404, 405, 413 y 415 para errores de transporte (propuesta V1.1 n.º 3) |
| Tamaño de 16 KiB, reintentos idénticos, límites de intentos, código comercial fuera de los registros, negativa comercial que consume el desafío | Sí | Ya coincide |

**Archivos sin internet (§9)**

| Punto | Contrato | Código actual |
| --- | --- | --- |
| Sobre | `schema_version`, `payload_b64u`, `signature_b64u` | Ya coincide |
| Contenido | `server_id` y `app_version`; sin huella | `installation_id`, huella y sin `app_version` |
| Firma | Prefijo `ADELPOS-LICREQ-V1` | Prefijo `LICREQ-V1` |
| `created_at` | UTC terminado en `Z` | Acepta también `+00:00` |
| `renew` | Entrega la revisión vigente | Firma una revisión nueva cada vez y permite ampliar la vigencia |
| `activate` | El primer periodo empieza al aprobar | Sin cálculo de periodo |
| `.lic` | El mismo JWS de la API | Ya coincide |
| Original cifrado sin cambios, recibir no concede nada, código comercial oculto, reintentos compartidos con la API | Sí | Ya coincide |

**Planes y archivos del contrato (§11 y §13)**

| Punto | Contrato | Código actual |
| --- | --- | --- |
| Planes `basico`, `elemental` y `premium` | Sí | No existen |
| `license.schema.json` y `openapi.yaml` | Sí | No existen |
| Archivos de prueba | Licencias, casos negativos, fechas, estado temporal, pruebas de posesión y `.licreq`, con `adel_pos` | 5 licencias, 3 pruebas de posesión, 3 `.licreq` y 3 negativos, del protocolo heredado y con `producto_prueba` |
| Verificador Go | Aplica §5.6 y §10 y ejecuta todos los casos | Solo comprueba firmas. Los casos de estado están descritos pero no se ejecutan. |

### E2-0.5 Consultas al propietario

**Respuesta del propietario (2026-11-16): aprobadas las 7 propuestas**, es decir, las dos de la especificación y las propuestas V1.1 n.º 1 a 5. Los criterios de E2-0.6 se aplican sin objeción. Quedaron registradas en `DECISIONS.md`, secciones 8 y 9.

**Sobre la especificación de la etapa**

| # | Consulta | Propuesta |
| --- | --- | --- |
| 1 | **Dos reglas chocan.** La especificación dice que `001_esquema_inicial.sql` no se modifica, y también que el marcador de herencia debe dar cero resultados. Dos de las 20 marcas son comentarios dentro de ese archivo (líneas 110 y 127). | Quitar solo esas dos líneas de comentario cuando una migración nueva reemplace su regla. El esquema no cambia y `InitialSchemaTest` sigue pasando. Cambia el checksum del archivo, sin consecuencias porque no hay instalaciones. La alternativa es dejarlas y excluir ese archivo de la búsqueda de cierre. |
| 2 | **El esquema cambia antes que el código que lo usa.** La Fase 2 elimina la huella y `license_limits`, pero el contenido firmado los usa hasta la Fase 4 (que es donde la especificación resuelve esas marcas), y la Fase 1 necesita cambiar la restricción del entorno de las llaves. Con todo en una sola migración en la Fase 2 habría que adelantar parte de las fases 4 a 6 para que la suite pase. | Tres migraciones: `002` en la Fase 1 (entornos de firma), `003` en la Fase 2 (todo lo nuevo, el renombre a `server_id` y la emisión con el modelo nuevo) y `004` en la Fase 4 (retira la huella, `license_limits` y las dos fechas heredadas, cuando el contenido deja de firmarlas). Cada commit queda con la suite completa pasando. |

**Propuestas V1.1: ambigüedades del contrato**

Ninguna obliga a cambiar el contrato ahora. Si se aprueban, quedan como aclaraciones para una V1.1 y se anotan en `DECISIONS.md` en la Fase 9.

| # | Sección | Qué no queda definido | Propuesta |
| --- | --- | --- | --- |
| 1 | §2, §4 | `product_id` es fijo (`adel_pos`), pero el servidor es de varios productos y sus pruebas usan `producto_prueba`. | El esquema fija `product_id` en `adel_pos` y el servidor no firma otro producto. Las pruebas pasan a `adel_pos`; las de módulos agregan módulos sintéticos a ese producto. El caso «otro producto» lo arma el generador de archivos de prueba. |
| 2 | §4 | `licensee_name` es «nombre o razón social», y el cliente tiene los dos campos. | La razón social si está capturada; si no, el nombre comercial. Máximo 190 caracteres. |
| 3 | §8.7 | La tabla asigna 400 a `INVALID_REQUEST`. No dice qué HTTP llevan una ruta inexistente, otro método, otro tipo de contenido o un cuerpo mayor de 16 KiB. | Conservar el HTTP estándar (404, 405, 415, 413) con `code: INVALID_REQUEST`, y documentarlo en OpenAPI. AD-EL POS decide por `code`. La alternativa es responder siempre 400. |
| 4 | §5.1 | «Si la hora local no existe, se usa el siguiente instante válido» admite dos lecturas. Ejemplo en `America/Tijuana`: 2027-03-14 02:30 no existe. PHP devuelve 03:30 (10:30 UTC); la lectura literal es 03:00 (10:00 UTC). | La lectura literal: el primer instante después del salto, 03:00. Se calcula con las transiciones de la zona, sin depender de PHP. El ancla no cambia. |
| 5 | §8.6 | No dice qué responde `deactivate` si la activación ya terminó. La §8.4 espera que la computadora anterior reciba así su revisión de cierre. Hoy una activación revocada recibe `403 REVOKED`. | Responder `200` con la revisión de cierre que ya existe, sea `deactivated`, `transferred` o `revoked`, sin firmar otra. `REVOKED` queda solo para `activate`. |
| 6 | §13 | Dice que la etapa 2 publica los casos de renovación, cambio de plan, conversión de prueba y mantenimiento. La especificación los pasa a la etapa 3. | Seguir la especificación (**se aplica**, E2-0.6) y corregir esa frase en V1.1. |
| 7 | §8.7 | `INCOMPATIBLE_SCHEMA` no tiene cuándo ocurrir en la API en línea: ninguna petición lleva versión. Solo el sobre `.licreq` la lleva. | Queda definido y se usa al importar un `.licreq` con otra `schema_version` (**se aplica**, E2-0.6). |
| 8 | §8.7 | `request_id` «UUID o null» no dice cuándo es `null`. | El `request_id` del cliente si es un UUID válido; `null` en el desafío y cuando falta o no es válido (**se aplica**, E2-0.6). |
| 9 | §4, §11 | El ejemplo dice «Plan elemental» y la tabla de planes, «Elemental». | «Plan básico», «Plan elemental» y «Plan premium», como en el ejemplo de la §4 (**se aplica**, E2-0.6). |
| 10 | §4 | No dice qué `status_reason` lleva la liberación forzada del panel sin computadora de destino («Recuperar plaza por equipo averiado»). | `transferred`, igual que la transferencia forzada con destino: el propietario retiró la licencia de esa computadora (**se aplica**, E2-0.6). |
| 11 | §5.2 | «Su periodo inicial se puede editar en el panel» no está asignado a ninguna etapa. | `PENDIENTE etapa 3`, junto con la renovación (**se aplica**, E2-0.6). |
| 12 | §9.1 | No define `renew` para una activación terminada, ni `activate` de la misma instalación que ya está activa. | Igual que en línea: `renew` entrega la revisión de cierre, y `activate` de la misma instalación entrega su revisión vigente sin crear otra activación (**se aplica**, E2-0.6). |
| 13 | §2, §8.3, §9.1 | No fija el largo de `app_version` ni si los demás UUID admiten mayúsculas. | `app_version`: de 1 a 100 caracteres, sin caracteres de control, como hoy. `server_id` solo en minúsculas; los demás UUID se aceptan en cualquier caja, como hoy (**se aplica**, E2-0.6). |

Las consultas que necesitaban respuesta eran las dos de la especificación y las propuestas V1.1 n.º 1 a 5. Todas quedaron aprobadas.

### E2-0.6 Criterios que se aplican salvo indicación contraria

1. Las propuestas V1.1 n.º 6 a 13 se aplican como están escritas arriba.
2. **Validación antes de firmar** (Fase 4 y Fase 7). Un validador en código, sin dependencias nuevas en producción. Una biblioteca de JSON Schema solo en `require-dev` comprueba que el validador y `license.schema.json` dan el mismo resultado en todos los archivos de prueba y en las respuestas reales. Así se respeta la preferencia de la especificación por `require-dev`.
3. **Semillas públicas de prueba.** Se rechazan con `LICENSE_ENVIRONMENT=production`, como pide la especificación, y también con `APP_ENV=production`, que es la regla actual. Así `licensing.sicrat.com` tampoco puede firmar con ellas.
4. **Fechas con precisión completa.** La hora ancla se guarda con microsegundos: el vencimiento cae a la misma hora local exacta del inicio.
5. **Fechas de la licencia, no de la activación.** El primer periodo se fija en la primera activación de la licencia. Si se libera y se activa en otra computadora, conserva sus fechas.
6. **El servidor no rechaza por vencimiento.** Una suscripción o prueba vencida se puede activar: el servidor firma su fecha y AD-EL POS calcula el estado. El contrato no tiene un código de error para eso.
7. **Renovación y mantenimiento se deshabilitan en la Fase 2**, con su prueba de rechazo, porque dependen de las columnas que cambian. El panel las oculta en la Fase 8.
8. **Pruebas por fase.** PHPUnit completo y verificador Go en cada commit. Las pruebas del motor heredado se eliminan en la fase que retira su regla y las nuevas llegan en la fase que la implementa; el total baja y vuelve a subir, con cada cambio justificado. Los archivos de `contracts/v1/` siguen siendo los heredados hasta la Fase 7.
9. **Búsqueda de cierre del marcador de herencia.** Se hace fuera de `AVANCE.md`, que conserva el registro de las dos etapas.

### E2-0.7 Riesgos y observaciones

- **La Fase 2 es la más grande.** Cambia la emisión al modelo nuevo y arrastra los 8 archivos de `tests/` que emiten licencias: 7 de integración y `prepare-panel.php`.
- **Zonas horarias.** La lista y las reglas dependen de los datos de zonas de PHP: aquí son los de 2026.1 (419 zonas) y el VPS usa PHP 8.3.6. Los casos de prueba usan `America/Monterrey`, sin cambio de horario, y `America/Tijuana`, con las reglas vigentes de Estados Unidos.
- **Propósito `manifest` de las llaves.** Es heredado y el contrato deja los manifiestos fuera de V1 (§15). Se conserva sin uso.
- **`subscription_periods` y `maintenance_purchases`.** Quedan sin escrituras en esta etapa. Su rediseño es de la etapa 3.
- **YAML.** La prueba que compara `ApiKernel` con `openapi.yaml` necesita leer YAML: será otra dependencia de `require-dev`, que se justificará en `DECISIONS.md` en la Fase 7.

### E2-0.8 Comandos para reproducir

```sh
cd adelpos-license
git status --porcelain                                  # árbol limpio
git log --oneline -1
shasum -a 256 docs/CONTRATO_LICENCIAS_ADEL_POS.md       # a4c3bfd0…f554bb

# Variables TEST_* de «Comandos para reproducir la verificación» de la etapa 1
php vendor/bin/phpunit                                  # 164 pruebas, 21 384 aserciones
php contracts/v1/generate-fixtures.php --check
env GOTOOLCHAIN=local GOPROXY=off GOSUMDB=off go run contracts/v1/verify-vectors.go contracts/v1/fixtures/vectors.json
php bin/check-secrets.php

# Los 20 puntos heredados (más 4 menciones del marcador en la documentación)
git grep -n -o -E "HEREDADO: se reemplaza en etapa 2–4 según CONTRATO_LICENCIAS_ADEL_POS\.md \([^)]*\)"

# Rastros del protocolo heredado, sin documentación ni archivos generados
git grep -c -E "installation_id|fingerprint_(version|hash)|LIC-V1|LICREQ-V1|lic\+jws|license_limits" -- . ':!*.md' ':!contracts/v1/fixtures/*'
```

---

## Etapa 2 · Fase 1 — Entornos de firma (contrato §3)

Fecha: 2026-11-16. Estado: **cerrada**.

### E2-1.1 Qué cambió

| Punto de la especificación | Cómo quedó |
| --- | --- |
| 1. Configuración obligatoria `LICENSE_ENVIRONMENT` | `Config` la exige con valor `test` o `production`, sin valor por omisión. Es independiente de `APP_ENV`. `config/local.example.php` la trae como `test`, junto al dominio de pruebas, y `bin/configure.php` exige `--license-environment`. |
| 2. Cada llave es de un solo entorno | `SigningKeys` usa el entorno de licencias, ya no `APP_ENV`. El registro y la carpeta (`var/keys/<entorno>` por omisión) están separados. El servidor firma solo con la llave activa de su entorno. |
| 2. El arranque y `bin/preflight.php` fallan si la llave activa no corresponde | `App::boot()` se detiene si la llave activa de la base es de otro entorno. Por HTTP responde 503 sin explicar el motivo; en la terminal el mensaje nombra la llave y los dos entornos. `bin/preflight.php` muestra el entorno y revisa además la carpeta. |
| 2. Formato sugerido de `kid` | Sin `--kid`, `generate` asigna `adelpos-test-AAAAMM-n` o `adelpos-prod-AAAAMM-n`. Rechaza un `kid` con la etiqueta del otro entorno. |
| 3. Semillas públicas de prueba | Rechazadas con `LICENSE_ENVIRONMENT=production`, aunque se cambie el nombre en el archivo y en el registro. También con `APP_ENV=production` (criterio 3 de E2-0.6). |
| 4. `bin/signing-key.php` | Cada operación empieza con `Entorno de licencias: <entorno>`. La acción nueva `export` imprime las llaves públicas de licencias del entorno. |

**Migración nueva: `database/migrations/002_entornos_de_firma.sql`.** Cambia la restricción `chk_signing_environment` de `signing_keys` a `test` y `production`, y agrega la misma restricción a `signing_scopes` (`chk_scope_environment`). No crea tablas, así que `bin/grants.php` no cambia. Se detiene si la base tiene llaves de los entornos anteriores.

**Respaldos.** El archivo cifrado guarda `LICENSE_ENVIRONMENT` en su configuración y `license_environment` en su manifiesto. Una restauración conserva el entorno.

Salida real de la exportación, con una base sintética:

```json
{
    "environment": "test",
    "keys": [
        {
            "kid": "adelpos-test-202611-1",
            "environment": "test",
            "public_key": "Spf1kXd0rOukj-5SVljReAPOC4DCZ2TVHisvd5gMIAk"
        },
        {
            "kid": "adelpos-test-202611-2",
            "environment": "test",
            "public_key": "RFWeYgwWKDQBNTnBUuvDzYHxmhGPlJjsbUUDBaaKGLA"
        }
    ]
}
```

Archivos de código modificados: `src/Config.php`, `src/App.php`, `src/Infrastructure/SigningKeys.php`, `src/Infrastructure/Backup.php`, `bin/signing-key.php`, `bin/preflight.php`, `bin/configure.php` y `config/local.example.php`.

### E2-1.2 Decisiones técnicas

Están en `DECISIONS.md`, sección 10. Tres van más allá de la letra de la especificación y el propietario puede revertirlas:

1. **`LICENSE_ENVIRONMENT=production` solo se acepta con `APP_ENV=production`.** La especificación dice que las dos son independientes, y lo son en el caso que describe: `APP_ENV=production` con `LICENSE_ENVIRONMENT=test`. Lo que se impide es lo contrario: firmar licencias de producción desde un servidor en modo de desarrollo.
2. **Una base de datos pertenece a un solo entorno.** No se puede cambiar `LICENSE_ENVIRONMENT` en una instalación que ya tiene una llave activa: el servidor deja de arrancar. Pasar de pruebas a producción es instalar otro servidor, con otra base y otras llaves.
3. **`SIGNING_KEY_DIR` sigue siendo la carpeta exacta.** No se le agrega el entorno, porque los respaldos y la restauración usan esa ruta tal cual. La guía de despliegue pasa a usar `shared/keys/test`. La separación se comprueba con el registro: una carpeta no puede contener llaves registradas en otro entorno.

### E2-1.3 Pruebas

| Momento | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Cierre de la Fase 0 | 164 | 21 384 | OK |
| **Cierre de la Fase 1** | **176** | **21 578** | **OK**: unitarias 79/20 604, integración 97/974, sin omisiones ni advertencias |

**Cambios en el número de pruebas (+12):**

| Cambio | Pruebas | Detalle |
| --- | --- | --- |
| Nuevas | +7 | `SigningEnvironmentsTest` (archivo nuevo) |
| Nuevas | +4 | `ProductionConfigTest`: entorno obligatorio y sin valor por omisión; independencia de `APP_ENV`; producción solo con `APP_ENV=production`; ejemplo y configurador |
| Nueva | +1 | `InitialSchemaTest::testSigningKeysBelongOnlyToTheTestOrProductionLicenseEnvironment` |
| Nueva | +1 | `HttpApiTest::testServerAnswersUnavailableWhenItsActiveKeyBelongsToAnotherLicenseEnvironment` |
| Movida | −1 | `ActivationsTest::testProductionRejectsPublicFixtureKeyEvenWhenRelabeled` pasa a `SigningEnvironmentsTest::testPublicFixtureSeedsAreRejectedForProductionLicensesAndOnProductionServers`, ampliada de un caso a seis |

**Ajustadas, sin cambiar lo que prueban:**
- `InitialSchemaTest`: la equivalencia compara `001_esquema_inicial.sql` sola, y la base vacía se comprueba con todas las migraciones.
- `ProductionConfigTest::testProductionRequiresRealApplicationKeys` y `RulesTest::testDevelopmentHttpCannotBeUsedOnPublicOrigin` indican el entorno de licencias, para seguir fallando por su motivo original. La primera ahora comprueba además el mensaje.
- `BackupTest`: comprueba que el respaldo conserva el entorno de licencias.
- `MySqlFixture`: usa `LICENSE_ENVIRONMENT=test` y limpia las carpetas de llaves de otros entornos.

**Las pruebas que pedía la fase:**

| Prueba pedida | Dónde |
| --- | --- |
| No se puede firmar con una llave de otro entorno | `SigningEnvironmentsTest::testServerSignsOnlyWithTheActiveKeyOfItsLicenseEnvironment`: sin llave propia no firma; el registro no admite cruzar entornos; cambiar la etiqueta en el registro o copiar el archivo a la otra carpeta tampoco sirve |
| `preflight` detecta cuando no coinciden | `SigningEnvironmentsTest::testPreflightDetectsLicenseEnvironmentMismatchAndSharedKeyFolders`, con el comando real. El arranque: `testServerDoesNotBootWhenItsActiveKeyBelongsToAnotherLicenseEnvironment` y, por HTTP, `HttpApiTest` |
| Producción rechaza las semillas de prueba | `SigningEnvironmentsTest::testPublicFixtureSeedsAreRejectedForProductionLicensesAndOnProductionServers`, con las cuatro semillas |
| La exportación no incluye ningún secreto | `SigningEnvironmentsTest::testPublicKeyExportContainsOnlyPublicLicenseKeysOfTheEnvironment`: busca en la salida la llave secreta, la semilla y la referencia de cada archivo privado |

**Comprobación de las pruebas.** Se quitó cada regla por separado, 14 veces, y en las 14 falló alguna prueba: la comprobación del arranque; cada una de las dos condiciones de las semillas; la revisión de la carpeta al generar y en `preflight`; la etiqueta del `kid`; los tres filtros de la exportación; el uso del entorno de licencias en lugar de `APP_ENV`; las dos reglas de `Config`; la restricción de la migración, y el entorno en el respaldo. El código se restauró idéntico después de cada una.

**Otras comprobaciones:**
- Verificador Go: «OK: 5 JWS, 3 proofs, 3 offline requests, 3 negative JWS». Los archivos de `contracts/v1/` siguen siendo los heredados hasta la Fase 7.
- `generate-fixtures.php --check`: OK.
- `bin/check-secrets.php`: sin hallazgos.
- Playwright: panel y dispositivos de confianza OK. No lo pedía la fase; se corrió porque cambió el arranque.
- Residuos: 0 bases y 0 usuarios de prueba; ninguna carpeta ni registro temporal.

**Registro de Playwright (observación de E2-0.1, adelantada de la Fase 8).** `tests/run-panel-tests.php` borra ahora el registro del servidor local cuando las pruebas pasan, y lo conserva solo si fallan. Se eliminaron los dos registros que quedaban de la etapa 1 y el de esta corrida.

### E2-1.4 Documentación

Actualizados solo en lo que cambia esta fase: `README.md` (configuración y llaves de firma), `OPERATIONS.md` (tabla de configuración, llaves y revisión previa), `SECURITY.md` (validación y entornos de firma), `SCHEMA.md` (migraciones y entornos de las llaves), `ARCHITECTURE.md` (firma), `TEST_PLAN.md` (pruebas nuevas) y `DEPLOY_UBUNTU_24_04.md` (el dominio de pruebas usa `--license-environment=test`, la carpeta `shared/keys/test` y la exportación de llaves). `DECISIONS.md` tiene las secciones 8 a 10, nuevas.

### E2-1.5 Pendientes que deja la fase

- **Fase 4:** el campo `environment` todavía no está en el contenido firmado. Al agregarlo, la firma debe rechazar un contenido cuyo entorno no sea el de la llave.
- **Fase 7:** los nombres de las semillas públicas de prueba cambian con los archivos nuevos; hay que actualizar la lista de `SigningKeys` y agregar al verificador Go la confianza por entorno.
- **Etapa 5:** la guía de despliegue solo se ajustó en la configuración y las llaves.

---

## Etapa 2 · Fase 2 — Modelo de datos

Fecha: 2026-11-16. Estado: **cerrada**.

### E2-2.1 Migración `003_contrato_v1.sql`

| Punto de la especificación | Cómo quedó |
| --- | --- |
| 1. Clientes | `customers.time_zone`, con `America/Monterrey` por omisión. `CatalogService` la valida contra la lista de zonas de PHP. |
| 2. Planes | Tabla `plans` con `basico` (1, 0, 0), `elemental` (3, 3, 3) y `premium` (5, 10, 5), llamados «Plan básico», «Plan elemental» y «Plan premium». Sin editor: la cuenta de aplicación solo la lee. |
| 3. Licencias | `license_type` con `trial` (lo limita `chk_license_terms`); `billing_period`, `trial_duration` y `updates_months`, cada uno solo en su tipo; copia del plan (`plan_id`, `plan_name`, `plan_custom`); cupos `cash_registers`, `order_takers` y `kitchen_displays` de 0 a 999; periodo vigente (`period_starts_at`, `expires_at`, `anchor_day`, `anchor_local_time`, `updates_until`); y `grace_days` de 5 en suscripción y 0 en prueba y perpetua. Todo con restricciones `CHECK`. |
| 4. Activaciones | La identidad se llama `server_id`, también en `activation_challenges` y `license_requests`. Columna `app_version`. El estado admite `transferred`. |
| 5. Revisiones | `status_reason` (`transferred`, `deactivated` o `revoked`, solo en revisiones `revoked`) y `environment`, que es llave foránea compuesta a la llave que firmó. |
| 6. Módulos | `license_features` y la selección de módulos se conservan sin cambios. |
| 7. Permisos y documentación | `bin/grants.php`: `plans` de solo lectura y `app_version` entre las columnas actualizables de `activations`. `SCHEMA.md` actualizado. La prueba de permisos cubre las 35 tablas. |

**Lo que no hace todavía, según la decisión 2 del propietario:** la huella (`fingerprint_version`, `fingerprint_hash`), la tabla `license_limits` y las fechas `maintenance_until` y `entitled_release_until` siguen en el esquema hasta la migración `004` de la Fase 4, porque el contenido firmado todavía las usa. `entitled_release_until` pasó a admitir `NULL`, y las dos fechas quedan vacías.

**`001_esquema_inicial.sql`:** se quitó la línea de comentario de herencia de la tolerancia (decisión 1 del propietario). El esquema que produce es el mismo y la prueba de equivalencia sigue pasando.

### E2-2.2 Código

- **Emisión con el modelo del contrato** (`LicenseService::issue`). Recibe el tipo y su condición propia, el plan y, si se quiere, los tres cupos.
  - Sin cupos, la licencia toma los del plan. Con algún número distinto queda personalizada (`plan_custom`), y conserva el plan del que partió.
  - **No fija fechas.** El primer periodo se calcula al activar: el motor es de la Fase 3 y su conexión, de las fases 4 a 6. Hasta entonces una licencia activada firma `expires_at` vacío.
  - Rechaza un dato de otro tipo de licencia en lugar de ignorarlo, por ejemplo un periodo de cobro en una perpetua.
- **Renovación y mantenimiento deshabilitados.** `LicenseService::change` rechaza `renew` y `maintenance` con «…estará disponible próximamente», antes de registrar ninguna operación. `OfflineService` rechaza igual la ampliación de vigencia al aprobar una solicitud. El código anterior se eliminó (`renewTerms`, `recordOfflineRenewal`, la compra de mantenimiento). Quedan dos marcas `PENDIENTE etapa 3`.
- **Zona horaria del cliente** (`CatalogService::saveCustomer`, `Domain\TimeZones`).
- **Dominio:** `Domain\LicenseTerms` reúne tipos, periodos, duraciones, meses de actualizaciones, cupos y tolerancia.
- **Renombre `server_id`** en `ActivationService`, `OfflineService`, `RevisionPublisher`, `DocumentService` y `ReadRepository`. La API y los `.licreq` siguen recibiendo `installation_id` hasta las fases 5 y 6.
- **Revisiones:** cada una guarda el entorno de la llave que la firmó.

### E2-2.3 Panel: ajustes que la emisión nueva hace necesarios

Adelantados de la Fase 8, y solo estos (decisión técnica 3 de la fase, en `DECISIONS.md`):

| Pantalla | Cambio |
| --- | --- |
| Emitir licencia | Tipo (perpetua, suscripción o prueba), su condición y el plan. Ya no pide fechas. |
| Detalle de licencia | El formulario de renovación o mantenimiento se sustituye por «Disponible próximamente». Muestra «Empieza al activar» mientras no hay fechas y la tolerancia real. |
| Listados, resumen, solicitud sin internet, constancia PDF y verificación pública | Reconocen el tipo «Prueba». Una suscripción o prueba sin activar ya no aparece como «Sin vencimiento». |

**Sigue en la Fase 8:** cambiar los cupos desde el formulario con su aviso de licencia personalizada, el selector de zona horaria del cliente, el detalle con plan, cupos y motivo de las revisiones, y el contenido de las constancias.

### E2-2.4 Puntos heredados

Quedan **14** de las 20 marcas. Esta fase resolvió 6:

| Marca | Ubicación |
| --- | --- |
| Tolerancia de 15 días | `001_esquema_inicial.sql` (comentario retirado), `LicenseService`, `templates/license-form.php` (2) y `templates/license-detail.php` |
| Una instalación por licencia, en el formulario | `templates/license-form.php` |

Las cuatro marcas de plantillas estaban clasificadas en la Fase 8 (E2-0.2) y se resolvieron aquí por los ajustes del panel. De las plantillas queda una, «Instalaciones: 1 autorizada», en el detalle de licencia.

### E2-2.5 Pruebas

| Momento | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Cierre de la Fase 1 | 176 | 21 578 | OK |
| **Cierre de la Fase 2** | **186** | **21 918** | **OK**: unitarias 82/20 660, integración 104/1 258, sin omisiones ni advertencias |

**Cambios en el número de pruebas (+10):**

| Cambio | Pruebas | Detalle |
| --- | --- | --- |
| Nuevas | +4 | `ContractDataModelTest` (archivo nuevo): reglas de la base con escrituras directas |
| Nuevas | +4 | `AdministrationTest`: los tres tipos con sus condiciones y los cupos de su plan (11 casos); licencia personalizada; 22 entradas inválidas de emisión; zona horaria del cliente |
| Nuevas | +3 | `RulesTest`: enteros del formulario dentro de su rango, condiciones de licencia del contrato y zonas horarias válidas. No necesitan MySQL |
| Sustituidas | −2, +1 | `AdministrationTest`: las pruebas de renovación y de compra de mantenimiento se sustituyen por `testRenewalAndMaintenanceAreRejectedUntilTheirStage` |

**Sustituidas una por una, porque probaban reglas que ya no existen:**

| Antes | Ahora |
| --- | --- |
| `AdministrationTest::testPerpetualDoesNotIncludeMaintenanceAndKeyIsNeverArchived` | `testPerpetualIsIssuedWithoutDatesAndKeyIsNeverArchived` |
| `AdministrationTest::testTwoProcessesRenewingSameRequestCommitOnlyOnce` | `testTwoProcessesSendingTheSameChangeCommitOnlyOnce`: la misma concurrencia, con un cambio de módulos |
| `ActivationsTest::testExpiredSubscriptionRefreshDoesNotExtendDatesOrFreeSlot` | `testSubscriptionRefreshedLaterKeepsItsRevisionAndSlotAndRenewalIsNotAvailableYet`. La parte de fechas vuelve en la Fase 5, cuando el periodo se calcule al activar. |
| `ActivationsTest::testMissingSigningFileRollsBackActivationAndCommercialRenewal` | `testMissingSigningFileRollsBackActivationAndCommercialChange`: la misma reversión, con un cambio de módulos |
| `OfflineTest::testRenewExtendsOnlyExplicitDatesAndKeepsIdentity` | `testRenewDeliversCurrentRightsAndRecordingARenewalIsNotAvailableYet` |

**Ajustadas:** las entradas de emisión de los 8 archivos que emiten licencias (`MySqlFixture::terms`), `InitialSchemaTest` (tres migraciones, catálogo de planes y 35 tablas) y `ActivationsTest::testAdministrativeRightsAndRevocationPublishAtomically` (usa dos cambios de módulos en lugar de una compra de mantenimiento).

**Las pruebas que pedía la fase:**

| Prueba pedida | Dónde |
| --- | --- |
| La migración se aplica sobre una base vacía y, repetida, no hace nada | `InitialSchemaTest::testEmptyDatabaseGetsOnlyAdelPosWithoutModulesAndRepeatingDoesNothing` |
| Periodo de cobro en una perpetua; meses de actualización en una suscripción; tolerancia que no corresponde al tipo | `ContractDataModelTest::testTermsMustMatchTheLicenseType` |
| Un cupo negativo o mayor que 999 | `ContractDataModelTest::testCountsStayBetweenZeroAndNineHundredNinetyNineAndThePlanMustExist` |
| La prueba de permisos pasa | `InitialSchemaTest::testRuntimeGrantsCoverExactlyTheSchemaTables`, y `ContractDataModelTest` comprueba los permisos nuevos |

**Comprobación de las pruebas.** Se quitó o debilitó cada regla por separado, 38 veces, y en las 38 falló alguna prueba: 24 de la migración (condiciones por tipo, cupos, plan, periodo, motivo y entorno de las revisiones, estados, zona horaria y catálogo inicial), 3 de los permisos y 11 del código (emisión, rechazo de renovación y mantenimiento, zona horaria, tolerancia y entorno de la revisión). El código se restauró idéntico después de cada una.

La primera pasada detectó 37. La que faltaba no era un hueco de las pruebas sino una regla redundante: `chk_license_type` repetía lo que `chk_license_terms` ya exige. Se quitó de la migración en lugar de conservar una regla que ninguna prueba puede distinguir.

**Otras comprobaciones:**
- Verificador Go y `generate-fixtures.php --check`: OK, con los archivos heredados.
- `bin/check-secrets.php`: sin hallazgos.
- Playwright: panel y dispositivos de confianza OK, con los dos scripts adaptados al formulario nuevo y al rechazo de renovación y mantenimiento. Se revisaron las capturas del formulario de emisión y del detalle de licencia.
- Residuos: 0 bases y 0 usuarios de prueba; ningún archivo temporal.

### E2-2.6 Estado intermedio y pendientes

- **Fechas.** Entre esta fase y la 5, una licencia activada no tiene periodo: el contenido firmado lleva `expires_at` vacío también en suscripciones y pruebas. Es un estado de desarrollo, sin despliegue.
- **Contenido firmado.** Sigue siendo el heredado, con huella y `limits` anteriores, hasta la Fase 4.
- **`app_version`.** La columna existe; se empieza a guardar con la API nueva (Fase 5).
- **Pruebas con `adel_pos`.** Las nuevas ya lo usan; las demás cambian en la Fase 4 (decisión 3 del propietario).
- **`PENDIENTE etapa 3`:** renovación con fecha de pago, conversión de prueba a pago, compra de mantenimiento, editor de planes y edición del periodo inicial de una licencia sin activar.

---

## Etapa 2 · Fase 3 — Motor de fechas (contrato §5.1, §5.2, §5.4 y §5.5)

Fecha: 2026-11-16. Estado: **cerrada**.

### E2-3.1 El componente

`src/Domain/CalendarPeriod.php`, puro: no usa la base de datos ni la hora del sistema.

| Punto de la especificación | Cómo quedó |
| --- | --- |
| 1. `addMonths(inicio, meses, zona, día_ancla, hora_ancla)` | Suma meses al mes local del inicio y devuelve el instante UTC del día y la hora ancla. Si el día no existe en ese mes, usa el último. |
| 1. Hora inexistente | El primer instante después del salto (decisión 6 del propietario). En Tijuana, el 14 de marzo de 2027 a las 02:30 da las 10:00:00Z, que son las 03:00 locales. |
| 1. Hora repetida | El primero de los dos instantes. En Tijuana, el 7 de noviembre de 2027 a las 01:30 da las 08:30Z, no las 09:30Z. |
| 2. `addDays(inicio, días, zona)` | Días de calendario a la misma hora local. |
| 3. Primer periodo | `firstPeriod(condiciones, inicio, zona)` devuelve inicio, vencimiento, fin de actualizaciones, día ancla y hora ancla. Suscripción: 1, 3, 6 o 12 meses. Prueba: 15 días, 1 mes o 3 meses. Perpetua: sin vencimiento, con actualizaciones por 0, 6 o 12 meses. |
| 4. Licencia sin activar | Ya estaba desde la Fase 2: no tiene fechas y el panel muestra «Empieza al activar». |
| 5. Formato de los instantes | `CalendarPeriod::utc` escribe seis decimales y termina en `Z`; `CalendarPeriod::sql` da el valor para las columnas `DATETIME(6)`. |

**Reloj inyectable.** El motor no tiene reloj propio: recibe el instante de inicio. Quien lo llame al activar se lo dará desde el reloj de la aplicación, que es el que las pruebas sustituyen.

**Todavía no está conectado.** Nada lo llama al activar: eso es de las fases 4 a 6. El estado intermedio de E2-2.6 sigue igual.

`Domain\LicenseTerms` recibió los meses de cada periodo de cobro y la duración de cada prueba.

### E2-3.2 Casos exactos

Todos los resultados esperados se calcularon a mano con las reglas del contrato, antes de ejecutar el motor. Coincidieron en la primera ejecución.

| Caso pedido | Resultado |
| --- | --- |
| 31 de enero más 1, 2 y 3 meses | 28 de febrero, 31 de marzo y 30 de abril. Encadenando un mes cada vez con el ancla guardada: 28 feb → 31 mar → 30 abr → 31 may. |
| Año bisiesto y no bisiesto | 31 de enero más 1 mes: 29 de febrero en 2028 y 28 de febrero en 2027. También 2000 (bisiesto) y 2100 (no bisiesto). |
| 29 de febrero más 12 meses | 28 de febrero de 2029. Año tras año sigue en 28 hasta 2032, que vuelve al 29. |
| 15 días, 1 mes y 3 meses | Desde el 16 nov 2026 06:30Z: 1 dic, 16 dic y 16 feb 2027, a las 06:30Z. |
| Las cuatro duraciones de suscripción | 16 dic 2026, 16 feb 2027, 16 may 2027 y 16 nov 2027, a las 06:30Z. La mensual es el ejemplo de la sección 4 del contrato. |
| Perpetua con 0, 6 y 12 meses | Sin vencimiento. Actualizaciones hasta el inicio mismo, el 16 may 2027 y el 16 nov 2027. |
| Hora inexistente en `America/Tijuana` | 02:00, 02:30 y 02:59:59.999999 dan las 10:00:00.000000Z. 01:59:59.999999 y 03:00 existen y se conservan. El mes siguiente vuelve a las 02:30. |
| Hora repetida en `America/Tijuana` | 01:00, 01:30 y 01:59:59.999999 dan el primer instante. 00:59:59.999999 y 02:00 ocurren una vez. |
| `America/Monterrey` cerca de la medianoche UTC | Las 03:30Z del 1 de marzo son 28 de febrero en Monterrey: más 1 mes da el 28 de marzo (29 mar 03:30Z), no el 1 de abril. |

Otros casos: cambio de año (31 dic → 31 ene → 28 feb), microsegundos conservados, la hora local que se mantiene a través de un cambio de horario (la hora UTC cambia), y 15 días que caen en una hora inexistente o repetida.

**Una activación dentro de una hora repetida.** Si el servidor confirma la activación en la segunda vuelta de las 01:30, una perpetua con 0 meses termina sus actualizaciones en ese mismo instante. Recalcular la hora local daría el primer instante, una hora antes de la activación, y la base lo rechazaría.

### E2-3.3 Pruebas

| Momento | Pruebas | Aserciones | Resultado |
| --- | --- | --- | --- |
| Cierre de la Fase 2 | 186 | 21 918 | OK |
| **Cierre de la Fase 3** | **258** | **26 242** | **OK**: unitarias 153/24 941, integración 105/1 301, sin omisiones ni advertencias |

**Cambios en el número de pruebas (+72):**

| Cambio | Pruebas | Detalle |
| --- | --- | --- |
| Nuevas | +71 | `tests/Unit/CalendarPeriodTest.php`: 8 pruebas con 71 casos. PHPUnit cuenta cada caso: 42 de meses, 11 de días, 12 de primer periodo y 6 pruebas sin tabla de casos. No necesitan MySQL y tardan medio segundo. |
| Nueva | +1 | `ContractDataModelTest::testTheFirstPeriodOfTheDateEngineFitsTheDatabaseRulesForEveryLicenseType`: 40 primeros periodos calculados por el motor se guardan sin que ninguna restricción los rechace, y se leen idénticos al microsegundo. |

Además de los casos exactos, `CalendarPeriodTest` tiene dos pruebas generales:

- **Contra una definición independiente.** Recorre cada hora local alrededor de los cambios de horario de 2027 en cinco zonas, incluidos un cambio a medianoche (`America/Santiago`) y uno de 30 minutos (`Australia/Lord_Howe`). Compara el motor con la regla escrita de otra forma: el primer instante cuya hora local no es anterior a la buscada. Esa definición no usa las tablas de transiciones.
- **Propiedades.** 900 inicios al azar con semilla fija, en tres zonas: el resultado siempre es posterior al inicio, cae en el mes esperado y en el día ancla o el último del mes, y conserva la hora local salvo dentro de una hora inexistente.

**Comprobación de las pruebas.** Se quitó o debilitó cada regla por separado, 26 veces, y en las 26 falló alguna prueba: el recorte al último día del mes, el mes y el día locales, la hora repetida, la hora inexistente (tres variantes, incluida la forma en que PHP la resuelve por su cuenta), los microsegundos, los días de calendario, la perpetua con 0 meses, el fin de las actualizaciones, el paso a UTC, el formato, las validaciones y las cinco duraciones. El código se restauró idéntico después de cada una.

**Otras comprobaciones:**
- Verificador Go y `generate-fixtures.php --check`: OK, con los archivos heredados.
- `bin/check-secrets.php`: sin hallazgos.
- Playwright: no se ejecutó, porque la fase no toca el panel ni el arranque.
- Residuos: 0 bases y 0 usuarios de prueba; ningún archivo temporal.

### E2-3.4 Decisiones y límites

Están en `DECISIONS.md`, sección 10, Fase 3. Dos conviene tenerlas presentes:

1. **Datos de zonas horarias.** Los resultados dependen de los datos de zonas de PHP: aquí, los de 2026.1. Los casos exactos usan solo `America/Monterrey` y `America/Tijuana`, cuyas reglas son estables. Si México o Estados Unidos cambian sus reglas, cambian los resultados futuros; las fechas ya firmadas no se recalculan.
2. **Límite conocido.** El mes de partida se toma de la fecha local del instante de partida. Solo importaría si un cambio de horario eliminara la última hora del último día de un mes y el ancla cayera ahí. Ninguna zona de México o de Estados Unidos cambia a medianoche. Queda anotado para la etapa 3, que encadena periodos al renovar.

### E2-3.5 Pendientes que deja la fase

- **Fases 4 a 6:** llamar a `firstPeriod` en la primera activación en línea y al aprobar una solicitud sin internet, y guardar el periodo en la licencia.
- **Fase 7:** publicar estos casos como archivos de prueba del contrato, con entrada y resultado esperado.
- **Fase 8:** mostrar las fechas en la zona horaria del cliente; el panel las muestra todavía en `America/Monterrey`.
- **Etapa 3:** renovación (sección 5.3 del contrato), que usa `addMonths` con el ancla guardada.
