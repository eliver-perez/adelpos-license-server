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
| Repaso de formato (regla del propietario) | **Hecho.** 261 pruebas, 26 248 aserciones, todas OK. Verificador Go OK. Playwright OK. |
| 4 — Contenido y firma de la licencia | **Cerrada.** 340 pruebas, 26 412 aserciones, todas OK. Verificador Go OK. Playwright OK. Quedan 7 de las 20 marcas de herencia. |
| 5 — API en línea | **Cerrada.** 344 pruebas, 26 611 aserciones, todas OK. Verificador Go OK. Playwright OK. Quedan 6 de las 20 marcas de herencia. |
| 6 — Archivos sin internet | **Cerrada.** 357 pruebas, 26 674 aserciones, todas OK. Verificador Go OK. Playwright OK. Quedan 5 de las 20 marcas de herencia. |
| 7 — Archivos del contrato y verificador de referencia | **Cerrada.** 369 pruebas, 27 283 aserciones, todas OK. Verificador de referencia en Go OK. Playwright OK. Queda 1 de las 20 marcas de herencia. |
| 8 — Panel: emisión con el modelo nuevo | **Cerrada.** 373 pruebas, 27 330 aserciones, todas OK. Verificador Go OK. Playwright OK. **Cero** marcas de herencia. |
| 9 — Cierre | **Cerrada.** 377 pruebas, 27 440 aserciones, todas OK. Verificador Go OK. Playwright OK. Paquete de despliegue generado. **Etapa 2 terminada.** |

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

---

## Etapa 2 · Repaso de formato — código legible (regla del propietario)

**Regla (2026-11-16):** el código no se ofusca ni se minifica. Se escribe con saltos de línea e indentación con **tabuladores**, para que se entienda al leerlo. Aplica a todo el código propio y a todo lo que se escriba en adelante.

Este repaso no cambia el comportamiento: solo cambia espacios, saltos de línea y la forma de escribir algunas plantillas.

### E2-F.1 Qué se hizo

| Archivos | Cómo | Cómo se comprobó que nada cambió |
| --- | --- | --- |
| 101 archivos PHP (`src`, `bin`, `tests`, `config`, `public`, `contracts/v1`), `app.js`, `app.css` y las pruebas `.cjs` | Formateador Prettier 3.10.1 con `@prettier/plugin-php` 0.26.0: tabuladores, 120 columnas, una sentencia por línea. Configuración en `.prettierrc.json`. | El árbol sintáctico de cada archivo PHP es idéntico antes y después (comparado con `nikic/php-parser`), comentarios incluidos. |
| 107 consultas SQL largas dentro del código | Se partieron por cláusula (`SELECT`, `FROM`, `WHERE`…), una por línea. | El texto de cada consulta es igual salvo espacios en blanco. |
| 11 mensajes largos | Se partieron en cadenas concatenadas. | La salida real de los comandos es idéntica. |
| 30 plantillas del panel (`templates/`) | Indentación del HTML con tabuladores, un atributo por línea en las etiquetas largas y espacios normales en el PHP (`$a === 'b' ? 'c' : 'd'`). Las líneas que juntaban varias sentencias o listas se reescribieron: los datos van en variables al inicio de la plantilla y cada etiqueta PHP lleva una sola sentencia. | Los tokens PHP de cada plantilla son idénticos antes y después del espaciado. Las 258 pruebas y las pruebas del panel con Playwright pasan, y se revisaron las capturas de pantalla. |
| Hoja de estilos de las constancias PDF (`templates/certificate-pdf.php`) | Una declaración CSS por línea. | Las pruebas de constancias comparan el PDF generado. |
| Migraciones `002` y `003` | `003` pasa a tabuladores y a una condición por línea en cada `CHECK`. `002` ya cumplía. | El SQL es igual salvo espacios en blanco, y `InitialSchemaTest` compara el esquema resultante. |

### E2-F.2 Lo que queda fuera

- **`database/migrations/001_esquema_inicial.sql` no se tocó.** La especificación de la etapa 2 dice que no se modifica, y la única excepción aprobada son sus comentarios de herencia. Tiene líneas densas (hasta 238 columnas). **Consulta al propietario:** si lo autoriza, se puede reformatear igual que `003`; no hay instalaciones, así que el cambio de checksum no afecta a nadie.
- **`contracts/v1/verify-vectors.go`:** la Fase 7 lo sustituye por el verificador nuevo, que se escribe ya con `gofmt` y líneas cortas.
- **Archivos de terceros** (`vendor/`, `node_modules/`, Bootstrap en `public/assets/vendor/`), archivos generados (`contracts/v1/fixtures/`) y documentos Markdown.

### E2-F.3 Cómo se mantiene

- `npm run format` aplica el formato y `npm run format:check` lo comprueba.
- **Prueba nueva `tests/Unit/CodeStyleTest.php`** (3 pruebas): ninguna línea indentada con espacios, ninguna termina con espacios, ninguna pasa de 140 columnas; en las plantillas, cada etiqueta PHP de una línea lleva una sola sentencia; y el formateador no encuentra diferencias. Las tres fallan si se quita la regla (comprobado con un cambio temporal).
- Prettier y su complemento de PHP son dependencias de desarrollo de npm. No van en el paquete de despliegue.

### E2-F.4 Resultado

- PHPUnit: **261 pruebas, 26 248 aserciones, todas OK** (258 anteriores + 3 de estilo).
- Verificador Go y `generate-fixtures.php --check`: OK.
- Playwright (`php tests/run-panel-tests.php all`): OK, datos sintéticos eliminados.

---

## Etapa 2 · Fase 4 — Contenido y firma de la licencia (contrato §4)

Desde esta fase, todo lo que el servidor firma tiene el contenido exacto del contrato. La API y los archivos sin internet todavía **reciben** el protocolo heredado (fases 5 y 6).

### E2-4.1 Qué cambió

| Tema | Antes | Ahora |
| --- | --- | --- |
| Encabezado del JWS | `typ: lic+jws` | `{"alg":"EdDSA","kid":"<kid>","typ":"adelpos-lic+jws"}`, exacto |
| Contenido | 18 campos heredados: `installation_id`, huella, `maintenance_until`, `entitled_release_until`, límites anteriores | Los 21 campos de la sección 4, en el orden del contrato (`Domain\LicenseContent::FIELDS`) |
| `environment` | No existía | El entorno configurado, que además debe coincidir con el de la llave que firma |
| `licensee_name` | No existía | Razón social del cliente; si no está capturada, el nombre comercial |
| `plan` y `limits` | Límites heredados | Copia del plan de la licencia (`plan_id`, `name`, `custom`) y los tres cupos |
| `features` | Todos los módulos, con `true` o `false` | Solo los habilitados, como `clave → true`; siempre un objeto JSON (`{}` si no hay) |
| `license_status` y `status_reason` | `active` o `revoked`, sin motivo | `active` solo si la licencia y la activación están vigentes. Si no, `revoked` con su motivo: `deactivated`, `transferred` o `revoked` |
| Fechas | Vacías hasta esta fase | El primer periodo se calcula al activar (en línea) o al aprobar (sin internet), con el motor de la Fase 3 y en la zona horaria del cliente |
| Validación antes de firmar | No existía | `LicenseContent::assertValid()`: si el contenido no cumple, no se firma y la transacción se revierte |
| Producto | Cualquiera del catálogo | Solo `adel_pos`: la emisión rechaza otro producto y el validador impide firmarlo |

**Archivos nuevos:** `src/Domain/LicenseContent.php`, `src/Application/LicenseActivator.php`, `database/migrations/004_retiro_de_lo_heredado.sql` y `tests/Unit/LicenseContentTest.php`.

### E2-4.2 Migración `004_retiro_de_lo_heredado.sql`

- Elimina `activations.fingerprint_version` y `fingerprint_hash`.
- Elimina la tabla `license_limits`. El esquema queda en 33 tablas, más `schema_migrations`.
- Elimina `licenses.maintenance_until` y `entitled_release_until`. Las sustituye `updates_until`, que ya existía.
- `chk_revision_reason`: el motivo es obligatorio en las revisiones `revoked` y queda vacío en las `active`.
- De `001_esquema_inicial.sql` se quitó solo la línea del comentario de herencia de `license_limits` (decisión 1 del propietario). `InitialSchemaTest` sigue comparando el esquema.
- `bin/grants.php` ya no concede permisos sobre `license_limits`.

### E2-4.3 Estados de una activación y motivo de cierre

| Qué ocurre | Estado de la activación | `status_reason` firmado |
| --- | --- | --- |
| La computadora libera la licencia, en línea o sin internet | `deactivated` | `deactivated` |
| El propietario la transfiere a otra computadora desde el panel | `transferred` | `transferred` |
| El propietario la retira sin destino («Recuperar plaza por equipo averiado») | `transferred` | `transferred` |
| El propietario revoca la licencia | `revoked` | `revoked` |

El panel ya muestra «Transferida» en el detalle de la licencia.

### E2-4.4 Marcas de herencia

Quedan **7 de 20**. Se resolvieron 7 en esta fase:

| Archivo | Regla | Cómo quedó |
| --- | --- | --- |
| `RevisionPublisher` (2) | Huella y lista de límites en el contenido | Contenido del contrato |
| `LicenseService` | Fila de `license_limits` al emitir | Eliminada con la tabla |
| `001_esquema_inicial.sql` | Comentario de `license_limits` | Línea quitada; la migración `004` elimina la tabla |
| `DocumentService` | Lista de límites en las constancias | Plan y cupos |
| `ActivationService`, `OfflineService` | Una instalación activa por licencia | Es la regla del contrato (sección 1): una sola computadora principal. La respuesta cuando vuelve a activar la misma computadora cambia en las fases 5 y 6 |

Las 7 que quedan: `Protocol` (Fase 5), `OfflineRequest` (Fase 6), `contracts/v1/generate-fixtures.php` ×4 (Fase 7) y `templates/license-detail.php` (Fase 8).

### E2-4.5 Pruebas

**Resultado:** PHPUnit **340 pruebas, 26 412 aserciones, todas OK**. Verificador Go y `generate-fixtures.php --check` OK (archivos heredados). Playwright OK.

| Lo que pide la especificación | Prueba |
| --- | --- |
| El contenido de cada tipo coincide campo por campo y en orden | `ActivationsTest::testPerpetualContentMatchesTheContractExactlyAndRefreshReusesSignedBytes` y `testSubscriptionTrialAndCustomContentMatchTheContractExactly`: perpetua, suscripción, prueba de 15 días y personalizada con razón social, sin módulos y en `America/Tijuana` (cruza el fin del horario de verano y un mes sin día 31) |
| Revocación, desactivación y transferencia forzada firman `revoked` con su motivo | `ActivationsTest` (desactivación y revocación), `OfflineTest` (desactivación sin internet, transferencia forzada y liberación forzada) y `offline-panel.cjs` |
| Las revisiones aumentan de forma global entre activaciones | `ActivationsTest::testDeactivationReleasesSlotAndOldIdentityKeepsTerminalRevision`: 1, 2 (cierre) y 3 en otra computadora, días después y con las mismas fechas |
| Un contenido inválido no se firma y no deja cambios | `ActivationsTest::testInvalidContentIsNotSignedAndLeavesNoChanges`: ni activación, ni revisión, ni periodo, ni desafío consumido; tampoco en un cambio de módulos ni en una revocación. `LicenseContentTest`: 71 reglas rotas, una por caso |

**Pruebas nuevas (79):** `LicenseContentTest` (74 casos), 3 en `ActivationsTest`, `AdministrationTest::testOnlyAdelPosLicensesCanBeIssued` y `ProtocolTest::testJwsHasTheExactContractHeaderAndAVerifiableSignature`.

**Pruebas sustituidas:**

- `ActivationsTest::testActivationHasExactRightsAndRefreshReusesSignedBytes` comprobaba 18 campos heredados; ahora compara el contenido completo del contrato.
- `ProtocolTest::testJwsPublisherMatchesAllSharedFixturesByteForByte` comparaba el JWS con los archivos heredados. Queda `testProofMessageMatchesTheSharedFixtures` hasta la Fase 5; la comparación byte a byte vuelve en la Fase 7 con los archivos nuevos.
- En `OfflineTest` la huella ya no forma parte de la identidad de una instalación: la identidad es `server_id` más la llave pública.

**Las pruebas pasan a `adel_pos`:** `MySqlFixture` ya no crea `producto_prueba`; agrega a `adel_pos` los módulos sintéticos `prueba_a` a `prueba_e`.

**Comprobación de las reglas:** se quitaron una a la vez **24 reglas** de esta fase (encabezado, validación previa, motivo de cierre, razón social, módulos deshabilitados, contador global, primer periodo, zona del cliente, producto fijo, restricciones de la migración `004` y siete reglas del validador). Las 24 hicieron fallar alguna prueba. La primera corrida dejó una sin detectar: «la revisión es `active` aunque la licencia esté revocada». Se agregó `testARevisionIsActiveOnlyWhileLicenseAndActivationAreBothInForce`.

### E2-4.6 Decisiones y pendientes

Las decisiones técnicas están en `DECISIONS.md`, sección 10, Fase 4. Una va más allá de la especificación y el propietario puede revertirla: **la emisión rechaza productos distintos de `adel_pos`**.

- **Fase 5:** `server_id` y `ADELPOS-LIC-V1` en la API; la API deja de recibir la huella; la misma computadora que vuelve a activar recibe su revisión vigente.
- **Fase 6:** `.licreq` con `server_id` y `app_version`; `renew` deja de firmar una revisión nueva.
- **Fase 7:** conectar `license.schema.json` a la validación y publicar los archivos de prueba nuevos.
- **Fase 8:** mostrar en el panel el motivo de cierre de cada revisión, y el plan y los cupos en el detalle.

---

## Etapa 2 · Fase 5 — API en línea (contrato §8)

La API en línea habla el protocolo del contrato y ya no acepta el anterior.

### E2-5.1 Qué cambió

| Tema | Antes | Ahora |
| --- | --- | --- |
| Identidad de la instalación | `installation_id`, en cualquier caja | `server_id`, UUID versión 4 en minúsculas |
| Prueba de posesión | Prefijo `LIC-V1` | `ADELPOS-LIC-V1`, con `server_id` |
| Activar | 10 campos, con huella | Los 8 campos de la sección 8.3; la huella se rechaza |
| La misma computadora vuelve a activar | `409 ACTIVATION_LIMIT` | `200` con su revisión vigente; sin otra activación ni otras fechas |
| Otra computadora | `409 ACTIVATION_LIMIT` sin detalles | `409 ACTIVATION_LIMIT` con `details.transfer_available: false` |
| Desactivar | Respuesta de 3 campos | `activation_id`, `deactivated_at`, `status`, `license_jws` (revisión de cierre), `server_time` y `request_id` |
| Desactivar una activación terminada | `403 REVOKED` si estaba revocada | `200` con su revisión de cierre, sea cual sea el motivo |
| Formato de error | Sin `details`; `request_id` inventado | `{ "error": { "code", "message", "request_id", "details" } }`; `request_id` del cliente o `null`; `details` siempre objeto |
| `request_id` reutilizado con otro contenido | `409 INVALID_REQUEST` | `409 REQUEST_CONFLICT`, también entre la API y una solicitud sin internet |
| Códigos | Faltaban `TRANSFER_LIMIT` y `REQUEST_CONFLICT` | Los 10 de la tabla del contrato, con su HTTP (`ApiProblem::STATUS`) |
| `app_version` | No se guardaba | Se guarda en la activación en cada operación autenticada |
| `transfer` | — | Rechazado con `INVALID_REQUEST` hasta la etapa 4 |

**Se conserva sin cambios:** el máximo de 16 KiB, los reintentos por `product_id` y `request_id` con la respuesta exacta, los límites de intentos, el desafío de 5 minutos y un solo uso, que una negativa comercial autenticada consume el desafío, y que el código comercial nunca se guarda ni se registra.

### E2-5.2 Marcas

- **Herencia:** quedan **6 de 20**. Se resolvió la de `Protocol` (huella en la API). Quedan `OfflineRequest` (Fase 6), `generate-fixtures.php` ×4 (Fase 7) y `templates/license-detail.php` (Fase 8).
- **`PENDIENTE etapa 4`** (nuevas, 3): la acción `transfer` del desafío (`Protocol`), la ruta `/v1/activations/transfer` (`ApiKernel`) y el cálculo de `transfer_available` (`ActivationService`).
- **`PENDIENTE etapa 3`** (2, sin cambio): renovación en `LicenseService` y en `OfflineService`.

### E2-5.3 Pruebas

**Resultado:** PHPUnit **344 pruebas, 26 611 aserciones, todas OK**. Verificador Go y `generate-fixtures.php --check` OK (archivos heredados). Playwright OK.

| Lo que pide la especificación | Prueba |
| --- | --- |
| Cada ruta, con éxito y con sus errores | `ActivationsTest`, y por HTTP real `HttpApiTest::testOnlineLifecycleThroughRealHttpDoesNotCreatePanelSessions` y `testHttpValidationTlsAndRateLimitsUseStableJsonErrors` |
| Reintento idéntico y `request_id` reutilizado con otro contenido | `testIdenticalReplaySurvivesChallengeCleanupAndCredentialRetirement` (`REQUEST_CONFLICT`) y `OfflineTest::testOnlineAndOfflineShareRequestIdNamespaceInBothDirections` |
| Desafío vencido, ya usado, de otra acción y de otra instalación | `testChallengeExpiresAtExactBoundaryAndInvalidProofDoesNotConsumeIt`, `testCommercialRefusalIsPersistedAndDoesNotConsumeAnotherRequest` y `testBoundKeyAndChallengeActionCannotBeSubstituted` |
| Prueba de posesión de otra llave | `testBoundKeyAndChallengeActionCannotBeSubstituted` |
| Reactivación desde la misma instalación | `testTheSameInstallationActivatesAgainAndReceivesItsCurrentRevision`: mismos bytes firmados, una activación, una revisión, mismas fechas; con derechos cambiados recibe la revisión vigente |
| Una segunda instalación recibe `ACTIVATION_LIMIT` | La misma prueba (otra llave con la misma identidad, y la misma llave con otra identidad) y `testCommercialRefusalIsPersisted…` (cuerpo exacto del error) |
| Desactivar y volver a activar en otra instalación | `testDeactivationReleasesSlotAndOldIdentityKeepsTerminalRevision` |
| Consulta y desactivación de una activación terminada | La anterior y `testEndedActivationsReceiveTheirClosingRevisionAndOnlyActivateAnswersRevoked` (transferida, revocada y desactivada) |
| Concurrencia | `testTwoProcessesWithDistinctRequestsCannotOccupyTwoSlots` (dos computadoras, exactamente una activación), `testTwoProcessesWithIdenticalRequestReturnIdenticalBytes` y `OfflineTest::testOnlineAndOfflineRaceCannotOccupyTwoSlots` |
| Ni respuestas ni registros contienen el código comercial | `testIdenticalReplaySurvives…`, `testUnknownKeyIsUniformAndArchivedWithoutTheSecret` y `HttpApiTest` (registro del servidor) |
| El protocolo anterior ya no se acepta | `testThePreviousProtocolIsNoLongerAccepted` y `ProtocolTest::testRequestsHaveExactlyTheFieldsOfTheContract` (27 peticiones inválidas) |

**Pruebas nuevas (6):** 3 en `ActivationsTest` y 3 en `ProtocolTest` (mensaje exacto de la prueba de posesión, campos exactos de cada petición, y códigos, HTTP y formato de los errores).

**Pruebas sustituidas (2):**

- `ActivationsTest::testUppercaseUuidBytesAreUsedInProofWithoutNormalization` aceptaba la identidad en mayúsculas. Ahora `testServerIdMustBeLowercaseAndOtherUuidsKeepTheirReceivedBytesInTheProof` comprueba que se rechaza y que los demás UUID conservan sus bytes.
- `ProtocolTest::testProofMessageMatchesTheSharedFixtures` y `testSchemaDoesNotCoerceOrAllowUnknownFields` comparaban con los archivos heredados y con el protocolo anterior. La comparación con archivos vuelve en la Fase 7.

**Ajustes en pruebas existentes:** las peticiones en línea de `ActivationsTest`, `HttpApiTest`, `ContractDataModelTest`, `OfflineTest` y `prepare-panel.php` usan `server_id` y ya no envían huella. Dos pruebas de `OfflineTest` usaban la misma computadora por las dos vías y esperaban un rechazo; ahora usan otra computadora, porque la misma recibe su revisión vigente. `panel.cjs` y `ProductionHttpTest` esperan `request_id: null`.

**Comprobación de las reglas:** se quitaron una a la vez **19 reglas**. La primera corrida detectó 16. Las otras tres llevaron a mejorar las pruebas o el código:

- *Aceptar la acción `transfer`:* seguía rechazada por otra regla (falta de `activation_id`). Se agregó el caso con `activation_id`.
- *La misma llave con otra identidad recibe la licencia:* faltaba ese caso en la prueba.
- *Límite de 16 KiB:* estaba escrito dos veces, en `ApiKernel` y en `StrictJson`. Ahora `ApiKernel::MAX_BODY_BYTES` es el único valor.

Las 19 quedan detectadas.

### E2-5.4 Pendientes que deja la fase

- **Fase 6:** `.licreq` con `server_id` y `app_version`, sin huella y con `ADELPOS-LICREQ-V1`; `renew` y la reactivación sin internet entregan la revisión vigente sin firmar otra.
- **Fase 7:** `openapi.yaml` con estas rutas, peticiones, respuestas y errores, y la prueba que lo compara con `ApiKernel::ROUTES`.
- **Etapa 4:** `transfer`, `TRANSFER_LIMIT` y `transfer_available`.

---

## Etapa 2 · Fase 6 — Archivos sin internet (contrato §9)

Las solicitudes `.licreq` usan el formato del contrato y el servidor ya no acepta el anterior. El `.lic` sigue siendo el mismo JWS de la API en línea.

### E2-6.1 Qué cambió

| Tema | Antes | Ahora |
| --- | --- | --- |
| Contenido del `.licreq` | `installation_id` y huella | `server_id` (UUID v4 en minúsculas) y `app_version`; la huella se rechaza |
| Firma | Prefijo `LICREQ-V1` | `ADELPOS-LICREQ-V1\n` + `payload_b64u` |
| `license_key` | Opcional en cualquier acción | Opcional y solo en `activate` |
| `created_at` | `Z` o `+00:00` | Solo `Z`, con 0 a 9 decimales |
| `activate` | Sin cálculo de periodo hasta la Fase 4 | El primer periodo empieza al aprobar (Fase 4). La misma computadora que vuelve a pedirlo recibe su revisión vigente |
| `renew` | Firmaba una revisión nueva cada vez | Entrega la revisión vigente, sin firmar otra |
| `deactivate` | Liberaba y firmaba el cierre | Igual; si la activación ya terminó, entrega el cierre que existe |
| Activación terminada o licencia revocada | `renew` y `deactivate` se rechazaban | Reciben su revisión de cierre |

**Se conserva sin cambios:** el sobre (`schema_version`, `payload_b64u`, `signature_b64u`), el original guardado cifrado y sin cambios, que recibir la solicitud no concede nada sin la aprobación, el código comercial que nunca se muestra, los reintentos compartidos con la API en línea y la transferencia forzada desde el panel.

### E2-6.2 Marcas

- **Herencia:** quedan **5 de 20**. Se resolvió la de `OfflineRequest`. Quedan `generate-fixtures.php` ×4 (Fase 7) y `templates/license-detail.php` (Fase 8).
- **`PENDIENTE etapa 3`** (sin cambio): registrar una renovación al aprobar un `renew` (`OfflineService`).

### E2-6.3 Pruebas

**Resultado:** PHPUnit **357 pruebas, 26 674 aserciones, todas OK**. Verificador Go y `generate-fixtures.php --check` OK (archivos heredados). Playwright OK.

| Lo que pide la especificación | Prueba |
| --- | --- |
| Las tres acciones, de punta a punta | `OfflineTest` (importar, aprobar y leer el `.lic`) y `offline-panel.cjs` (subir, revisar, aprobar y descargar desde el navegador) |
| Firma inválida | `OfflineRequestTest::testTamperedSignatureAndDuplicateOuterKeys` y `OfflineTest::testModifiedSignedBytesConflictAndInvalidSignatureReservesNothing` |
| Solicitud con huella o con el prefijo anterior | `OfflineTest::testRequestsOfTheReplacedFormatAreRejected` (6 casos; nada queda reservado) y `OfflineRequestTest::testTheReplacedFormatIsRejected` |
| El periodo empieza al aprobar, con reloj congelado | `OfflineTest::testThePeriodStartsWhenTheRequestIsApprovedAndTheStoredCopyNeverChanges`: se importa un día y se aprueba tres días después; el periodo, el ancla y la fecha de activación son los de la aprobación |
| Solicitud repetida | `testImportIsPendingEncryptedAndDuplicateFormattingIsHarmless` y `testApprovalIsIdempotentAndOppositeOrSecondDecisionCannotChangeIt` |
| La copia guardada no cambia | La prueba del periodo: el original descifrado es idéntico byte por byte después de aprobar. `testEvidenceTamperingAndSqlMutationAreRejected` |

**Pruebas nuevas (13):** 4 en `OfflineTest` (la misma computadora que vuelve a activar, periodo al aprobar, formato anterior rechazado, licencia revocada) y 9 en `OfflineRequestTest` (formato anterior y 8 casos más de contenido inválido).

**Pruebas que cambiaron de resultado esperado:**

- `testRenewDelivers…` y `testPerpetualRenew…`: `renew` devolvía la revisión 2; ahora devuelve la misma revisión 1, byte a byte, y no se firma nada.
- `testBoundIdentityChecksAllSignedFieldsAndRechecksAtApproval`: aprobar el `renew` de una activación terminada se rechazaba; ahora entrega su revisión de cierre `transferred`.
- `OfflineRequestTest` ya no lee los archivos heredados de `contracts/v1/fixtures/`: son del formato anterior. La lectura de los archivos nuevos vuelve en la Fase 7.

**Comprobación de las reglas:** se quitaron una a la vez **15 reglas** (prefijo de la firma, código comercial fuera de `activate`, fecha con desfase o con más de 9 decimales, versión sin límite, identidad en cualquier caja, `renew` que firma, misma computadora, otra llave, licencia revocada, activación terminada y versión guardada). Las 15 hicieron fallar alguna prueba.

### E2-6.4 Pendientes que deja la fase

- **Fase 7:** archivos de prueba `.licreq` de las tres acciones con el formato nuevo, y el verificador de referencia.
- **Fase 8:** el detalle de la licencia en el panel (plan, cupos, fechas y motivo de cierre).
- **Etapa 3:** registrar una renovación al aprobar una solicitud `renew`.

---

## Etapa 2 · Fase 7 — Archivos del contrato y verificador de referencia (contrato §13)

La carpeta `contracts/v1/` se reemplazó por completo. Ya no queda ningún archivo del protocolo anterior.

### E2-7.1 Qué hay ahora en `contracts/v1/`

| Archivo | Contenido |
| --- | --- |
| `license.schema.json` | Contenido de la licencia (sección 4) en JSON Schema 2020-12: los 21 campos obligatorios, las condiciones por tipo y por estado, los formatos de UUID, instantes y base64url, y los cupos de 0 a 999. Permite campos adicionales |
| `openapi.yaml` | API en línea (sección 8) en OpenAPI 3.1: las 4 rutas, sus peticiones, respuestas, errores y 17 ejemplos. El contenido de la licencia hace referencia a `license.schema.json` |
| `fixtures/vectors.json` | Todos los casos, con su resultado esperado |
| `fixtures/licenses/*.lic` | Las 11 licencias válidas, tal como viajan |
| `fixtures/requests/*.licreq` | Las 3 solicitudes sin internet |
| `generate-fixtures.php` | Generador, con `--check` |
| `verify/` | Verificador de referencia en Go |
| `README.md` | Qué es cada cosa y cómo se usa |

**Se eliminaron:** `verify-vectors.go` y los 10 archivos de prueba heredados (`perpetual.lic`, `renewed.lic`, `activate.licreq`, `deactivate-response.json`…).

### E2-7.2 Archivos de prueba

| Grupo | Casos |
| --- | --- |
| Licencias válidas | 11: perpetua con 0, 6 y 12 meses; suscripción mensual (el ejemplo del contrato) y anual; prueba de 15 días; personalizada; cierres `deactivated`, `transferred` y `revoked`; y una firmada con otra llave de confianza |
| Casos negativos | 17, cada uno con su motivo de rechazo |
| Regla de la revisión | 6: primera licencia, revisión mayor, misma revisión idéntica, misma revisión con otros bytes, revisión menor y otra licencia |
| Fechas | 24: 12 de meses de calendario, 2 de días y 10 de primer periodo |
| Estado temporal | 13: límites exactos de vigente, tolerancia y vencida; reloj atrasado; licencias revocadas |
| Pruebas de posesión | 4 acciones (`transfer` como vector criptográfico) |
| Solicitudes `.licreq` | 3 acciones |

Los casos de renovación, cambio de plan, conversión de prueba y compra de mantenimiento se agregan en la etapa 3 (propuesta V1.1 n.º 6).

### E2-7.3 Verificador de referencia en Go

`contracts/v1/verify/` aplica las reglas de las secciones 5.6 y 10 del contrato: encabezado exacto, `kid` de confianza por entorno, firma, contenido de la sección 4, entorno igual al de la llave, identidad y llave de la instalación, revisión que nunca retrocede y estado temporal con `ahora_efectivo`. Ejecuta todos los casos de licencias, negativos, revisión, estado, pruebas de posesión y `.licreq`:

```
OK: 11 licencias, 17 casos negativos, 6 casos de revisión, 13 estados temporales, 4 pruebas de posesión y 3 solicitudes .licreq.
```

Acertó todos los casos en su primera ejecución, sin ajustar los archivos de prueba.

### E2-7.4 Marcas de herencia

Queda **1 de 20**: `templates/license-detail.php` («Instalaciones: 1 autorizada»), que se resuelve en la Fase 8. Las 4 marcas de `generate-fixtures.php` desaparecieron con el generador anterior.

### E2-7.5 Dependencias nuevas

Dos, **solo de desarrollo**: `opis/json-schema` 2.6.2 y `symfony/yaml` 7.4.23, con sus dependencias `opis/string`, `opis/uri` y `symfony/polyfill-ctype`. No van al servidor. La justificación está en `DECISIONS.md`, sección 10, Fase 7, decisión 1.

### E2-7.6 Pruebas

**Resultado:** PHPUnit **369 pruebas, 27 283 aserciones, todas OK**. Verificador en Go OK. `generate-fixtures.php --check` OK. Playwright OK.

| Lo que pide la especificación | Prueba |
| --- | --- |
| Cada archivo de prueba cumple o incumple el esquema según lo esperado | `ContractFilesTest::testValidLicensesComply…` y `testNegativeLicensesFailTheSchemaAndTheValidatorExactlyWhenExpected` |
| Las respuestas reales de la API cumplen el esquema y el OpenAPI | `HttpApiTest` valida cada respuesta por HTTP real; `ActivationsTest` y `OfflineTest` validan cada licencia firmada contra `license.schema.json` |
| Las rutas de `ApiKernel` coinciden con las de `openapi.yaml` | `testOpenApiRoutesAreExactlyTheRoutesOfTheApi`, y también los campos de cada petición y los códigos de error |
| El verificador Go pasa sobre todos los casos | `testGoReferenceVerifierRunsEveryFixtureCase` |
| `generate-fixtures.php --check` da OK | `testFixturesAreExactlyWhatTheirGeneratorWrites` |

**Pruebas nuevas (12):** las de `ContractFilesTest`. Vuelven, con los archivos nuevos, las dos comparaciones que se habían retirado en las fases 4 y 6: el servidor firma byte a byte los mismos JWS que los archivos de prueba, y acepta sus `.licreq`.

**Conexión con la validación previa a la firma (Fase 4):** de los 71 casos inválidos de `LicenseContentTest`, el esquema rechaza 60. Los otros 11 son reglas que un esquema JSON no puede expresar y que el servidor sí comprueba; están marcados uno por uno y la prueba falla si el esquema empieza a rechazarlos o deja de rechazar alguno de los 60.

**Comprobación de las reglas:** se quitaron una a la vez **24 reglas**: 5 del esquema, 6 del OpenAPI, 3 del código y del generador, y 10 del verificador en Go. La primera corrida detectó 22. Las otras dos llevaron a mejoras:

- *Una respuesta del OpenAPI con menos campos obligatorios:* las respuestas reales seguían cumpliendo. Ahora `HttpApiTest` exige que cada respuesta traiga exactamente los campos obligatorios del OpenAPI.
- *El verificador acepta una llave conocida de otro entorno:* no se notaba porque cada entorno tenía su propia lista de llaves. Se agregó el caso de una versión de producción que incluye por error una llave de pruebas.

Las 24 quedan detectadas.

### E2-7.7 Pendientes que deja la fase

- **Fase 9:** incluir `openapi.yaml` y `license.schema.json` en el paquete de despliegue, como documentación.
- **Etapa 3:** casos de prueba de renovación y cambio de plan.
- **Etapa 4:** agregar `transfer` a `openapi.yaml`.

---

## Etapa 2 · Fase 8 — Panel: emisión con el modelo nuevo

Es el ajuste mínimo para emitir y consultar con el contrato. Las pantallas definitivas son de la etapa 5.

### E2-8.1 Qué cambió

| Pantalla | Cambio |
| --- | --- |
| Ficha del cliente | Selector de **zona horaria**, con `America/Monterrey` por omisión |
| Emitir licencia | El plan llena los cupos de cajas, comanderos y pantallas de cocina. Se pueden ajustar; al cambiar uno aparece el aviso **«Licencia personalizada»** |
| Detalle de la licencia | Tipo con su condición («Suscripción trimestral», «Perpetua · 6 meses de actualizaciones», «Prueba de 1 mes»), plan y etiqueta «Personalizada», los tres cupos, inicio del periodo, vencimiento, tolerancia, fin de actualizaciones y zona horaria del cliente. Sin activar: «Empieza al activar» |
| Revisiones firmadas | Cada revisión de cierre dice su motivo: «Revocada · licencia liberada», «Revocada · transferida a otra computadora» o «Revocada · revocada por el propietario» |
| Instalaciones | El estado «Transferida» (desde la Fase 4) |
| Solicitud sin internet | Tipo, plan y fechas de la licencia en la zona del cliente; identidad `server_id` y versión de AD-EL POS (desde la Fase 6) |
| Constancias PDF | Modalidad, plan (y si es personalizada), cupos, inicio del periodo, vencimiento y fin de actualizaciones, en la zona del cliente |
| Renovación y mantenimiento | Sin cambio: «Disponible próximamente», y el servidor las rechaza |

Todas las fechas de una licencia se muestran en la zona horaria de su cliente, con el nombre de la zona escrito. Ejemplo real de la prueba: `16/05/2027 · 19:13 America/Tijuana`.

### E2-8.2 Marcas de herencia

**Quedan 0 de 20.** La última, «Instalaciones: 1 autorizada» en `templates/license-detail.php`, se sustituyó por los cupos del contrato y por «Computadoras principales: 1 a la vez».

### E2-8.3 Pruebas

**Resultado:** PHPUnit **373 pruebas, 27 330 aserciones, todas OK**. Verificador en Go OK. Playwright OK (panel y dispositivos de confianza).

| Lo que pide la especificación | Prueba |
| --- | --- |
| Emitir los tres tipos | `panel.cjs`: perpetua, prueba de 1 mes y suscripción trimestral, y su tipo en el detalle. `AdministrationTest` (desde la Fase 2) |
| Personalizar un plan | `panel.cjs`: el plan llena los cupos, cambiar uno muestra el aviso, volver al valor del plan lo quita, y la licencia emitida aparece como «Plan elemental · Personalizada» con 4, 3 y 3. `AdministrationTest` (Fase 2) para la regla del servidor |
| Validaciones del formulario | `panel.cjs`: mínimo 0, máximo 999 y obligatorio en el formulario; `422` del servidor con «Cajas debe ser un número entero entre 0 y 999» al enviarlo directamente |
| Rechazo de renovación y mantenimiento | `panel.cjs` y `ActivationsTest`, sin cambio |
| Detalle antes y después de activar | `panel.cjs`: antes, «Empieza al activar»; después, inicio y fin de actualizaciones con `America/Tijuana`, y los estados firmados de las cuatro revisiones |
| Playwright: panel y dispositivos de confianza | `php tests/run-panel-tests.php all` |

**Pruebas nuevas (4):** `ViewTest` (3: fechas con su zona, nombre de cada tipo de licencia y motivo de cierre) y `CommercialDeliveryTest::testCertificateShowsTermsPlanLimitsAndDatesInTheCustomerTimeZone`, que lee el texto del PDF antes y después de activar, y con un cliente en `America/Tijuana`.

**Comprobación de las reglas:** se quitaron una a la vez **14 reglas**: 9 con PHPUnit y 5 con Playwright (aviso de personalizada, llenado de cupos, motivo de cierre, selector de zona y etiqueta «Personalizada»). La primera corrida detectó 13. La que faltaba, quitar la fila «Plan» de la constancia, pasaba porque la prueba buscaba el nombre del plan y no su etiqueta; ya comprueba las dos. Las 14 quedan detectadas.

**Corrección encontrada con las pruebas:** `View::revisionStatus` usaba un valor vacío como índice de un arreglo, algo que PHP 8.5 marca como obsoleto. Corregido.

### E2-8.4 Pendientes que deja la fase

- **Etapa 3:** editar el periodo inicial de una suscripción sin activar (propuesta V1.1 n.º 11), renovación, mantenimiento y editor de planes.
- **Etapa 5:** pantallas definitivas y diseño final de las constancias.

---

## Etapa 2 · Fase 9 — Cierre

### E2-9.1 Cifras por fase

| Fase | Commit | Pruebas | Aserciones | Reglas comprobadas quitándolas una a la vez |
| --- | --- | --- | --- | --- |
| 0 — Verificación contra el código | `792e9f3` | 164 | 21 384 | — |
| 1 — Entornos de firma | `6b9a5a1` | 176 | 21 578 | Ver E2-1.3 |
| 2 — Modelo de datos | `d087f05` | 186 | 21 918 | 38 |
| 3 — Motor de fechas | `e8af6c8` | 258 | 26 242 | Ver E2-3.3 |
| Repaso de formato | `349b4f7` | 261 | 26 248 | 2 (la prueba de estilo) |
| 4 — Contenido y firma | `bdf7773` | 340 | 26 412 | 24 |
| 5 — API en línea | `6ba5409` | 344 | 26 611 | 19 |
| 6 — Archivos sin internet | `da21c61` | 357 | 26 674 | 15 |
| 7 — Archivos del contrato y verificador | `1553559` | 369 | 27 283 | 24 |
| 8 — Panel | `f4da774` | 373 | 27 330 | 14 |
| 9 — Cierre | este commit | **377** | **27 440** | — |

La etapa 1 cerró con 164 pruebas y 21 384 aserciones. La etapa 2 cierra con **213 pruebas más**.

En las fases 4 a 8, quitar reglas una a una encontró **7 reglas que ninguna prueba vigilaba** (1 en la Fase 4, 3 en la 5, 2 en la 7 y 1 en la 8). Las siete tienen ahora su prueba, y una llevó además a simplificar el código: el límite de 16 KiB estaba escrito dos veces.

### E2-9.2 Pruebas eliminadas, sustituidas y nuevas

Ninguna prueba se eliminó sin sustituto. El detalle está en la sección de pruebas de cada fase:

| Fase | Sustituidas | Motivo | Diferencia neta de pruebas |
| --- | --- | --- | --- |
| 1 | Ver E2-1.3 | Entorno de firma independiente de `APP_ENV` | +12 |
| 2 | Ver E2-2.5 | Emisión con el modelo del contrato; renovación y mantenimiento deshabilitados | +10 |
| 3 | — | — | +72 (casos del motor de fechas) |
| Formato | — | — | +3 |
| 4 | 2, en su mismo lugar | Contenido firmado heredado; comparación con archivos heredados | +79 |
| 5 | 2 | Identidad en mayúsculas; protocolo anterior | +4 (6 nuevas menos 2 sustituidas) |
| 6 | 3 (cambian de resultado esperado) | `renew` ya no firma; una activación terminada recibe su cierre | +13 |
| 7 | — (vuelven las dos comparaciones con archivos de prueba) | — | +12 |
| 8 | — | — | +4 |
| 9 | — | — | +4 (`StageClosureTest`) |

### E2-9.3 Qué se hizo en esta fase

- **Marcas.** La búsqueda del marcador de herencia da cero resultados fuera de `AVANCE.md` y de la especificación de la etapa, que lo nombra. Lo que pasa a otra etapa está marcado con `PENDIENTE etapa N:` en 8 archivos: etapa 3 (renovación, mantenimiento, periodo inicial y editor de planes), etapa 4 (transferencia por autoservicio y alertas) y etapa 5 (diseño final de las constancias).
- **Documentos.** `README.md`, `ARCHITECTURE.md`, `SCHEMA.md`, `SECURITY.md`, `OPERATIONS.md` y `TEST_PLAN.md` describen la etapa 2 cerrada. Las secciones de herencia se sustituyeron por «Pendiente para las etapas siguientes».
- **`DECISIONS.md`.** Sección 6 cerrada, sección 9 con las propuestas V1.1 nuevas y sección 10 con las decisiones técnicas de cada fase y las dos dependencias de desarrollo.
- **Paquete de despliegue.** Incluye `contracts/v1/license.schema.json` y `contracts/v1/openapi.yaml` como documentación. No incluye archivos de prueba, llaves de prueba, generador ni verificador. Generado y revisado: 133 archivos más `RELEASE.json`.
- **Panel.** El alias interno de la identidad de la computadora dejó de llamarse como en el protocolo anterior.
- **Prueba nueva `StageClosureTest`:** el contrato conserva su SHA-256, no queda marcador de herencia, no queda protocolo anterior en el código del servidor y toda marca de pendiente nombra su etapa.
- **Datos sintéticos.** No queda ninguna base ni usuario `adelpos_license_test_*` en MySQL, ni archivos temporales de las pruebas.

### E2-9.4 Consultas al propietario

Se trabajaron las fases 4 a 9 sin detenerse, como se pidió. Estas decisiones se tomaron con el criterio indicado y **quedan a su aprobación**. Ninguna modifica el contrato y todas se pueden revertir.

**Propuestas V1.1 nuevas** (ambigüedades del contrato encontradas al implementar):

| N.º | Sección | Qué no queda definido | Criterio aplicado | Alternativa |
| --- | --- | --- | --- | --- |
| 14 | §8.6 | El contrato fija `"status": "deactivated"` en la respuesta de desactivar. No dice qué lleva si la activación ya había terminado por transferencia o revocación. | Siempre `deactivated`; el motivo real va en `status_reason` de la licencia firmada de esa misma respuesta. | Devolver el estado real (`transferred` o `revoked`), lo que obligaría a AD-EL POS a manejar tres valores. |
| 15 | §9.1 | No dice si `renew` o `deactivate` sin internet se atienden cuando la licencia fue revocada. | Se atienden y entregan la revisión de cierre. | Rechazarlas: la computadora sin internet nunca se enteraría de la revocación. |
| 16 | §9.1, §3 | Formato de `created_at` en un `.licreq`. | Solo UTC terminado en `Z`, con 0 a 9 decimales. | Aceptar también `+00:00`, como antes. |
| 17 | §4 | El contenido dice `schema_version: "1.0"`; el cliente acepta cualquier `1.x`. | El esquema publicado fija `1.0`; el verificador de referencia acepta `1.x`. | Que el esquema acepte `1.x`. |
| 18 | §3 | El servidor emite 6 decimales; el cliente acepta de 0 a 9. | El esquema admite de 0 a 9; el servidor se exige 6 antes de firmar. | Que el esquema exija 6. |
| 19 | §8.7 | HTTP de una petición que llega sin TLS. | `403 INVALID_REQUEST`, como ya estaba. | `400`. |
| 20 | §8.3 | La misma llave con otro `server_id`. | Es otra instalación: `ACTIVATION_LIMIT`. | — |

**Decisiones que van más allá de la especificación:**

| # | Decisión | Por qué | Cómo revertirla |
| --- | --- | --- | --- |
| A | La emisión rechaza productos distintos de `adel_pos` (Fase 4). | El contrato solo permite firmar ese producto: una licencia de otro no podría activarse. | Quitar la comprobación de `LicenseService::issue`; el validador seguiría impidiendo la firma. |
| B | `license_key` en un `.licreq` solo se acepta en `activate` (Fase 6). | Es lo que dice el contrato; antes se aceptaba en cualquier acción. | — |
| C | Dos dependencias nuevas de desarrollo: `opis/json-schema` y `symfony/yaml` (Fase 7). | La especificación las prevé para comparar el esquema y el OpenAPI con el código. No van al servidor. | — |

**Pendiente de su autorización:**

| # | Asunto |
| --- | --- |
| D | `database/migrations/001_esquema_inicial.sql` es el único archivo propio que no cumple la regla de código legible: tiene líneas de hasta 238 columnas e indentación con espacios. La especificación prohíbe modificarlo. Si lo autoriza, se reformatea igual que la `003`; no hay instalaciones, así que el cambio de checksum no afecta a nadie. |

### E2-9.5 Criterios de aceptación

| Criterio | Estado | Evidencia |
| --- | --- | --- |
| Todo lo que el servidor firma, recibe y devuelve coincide con el contrato V1.0. El contrato no se modificó | Cumplido | `ContractFilesTest`, `ActivationsTest`, `HttpApiTest` y `OfflineTest`. SHA-256 del contrato sin cambio (`StageClosureTest`) |
| No queda rastro del protocolo anterior | Cumplido | `StageClosureTest::testThePreviousProtocolIsGoneFromTheServerCode`. Los prefijos y campos anteriores solo aparecen en pruebas y casos negativos que comprueban su rechazo, y en las migraciones `003` y `004`, que retiran sus columnas |
| Entornos de firma `test` y `production`, separados y comprobados. Producción rechaza las llaves de prueba | Cumplido | `SigningEnvironmentsTest` y `ContractFilesTest::testEveryFixtureKeyIsRejectedByTheServerInProduction` |
| El primer periodo se calcula con el motor de fechas, al activar en línea o al aprobar sin internet, con casos exactos | Cumplido | `CalendarPeriodTest`, `ActivationsTest` (contenido exacto de cada tipo), `OfflineTest::testThePeriodStartsWhenTheRequestIsApproved…` y los 24 casos de fechas de `fixtures/` |
| API, archivos sin internet, errores y reintentos probados, incluida la concurrencia | Cumplido | Secciones E2-5.3 y E2-6.3 |
| Existen `license.schema.json`, `openapi.yaml`, los archivos de prueba y el verificador Go, y coinciden con el código | Cumplido | `ContractFilesTest` (12 pruebas) y el verificador en Go |
| El panel emite los tres tipos con plan o personalizada. Renovación y mantenimiento deshabilitados, con prueba | Cumplido | `panel.cjs` y `AdministrationTest` |
| Cero marcadores de herencia. Los pendientes quedan marcados con su etapa | Cumplido | `StageClosureTest` |
| La suite completa, Playwright, la revisión de secretos y el paquete de despliegue pasan | Cumplido | Ver E2-9.7 |

### E2-9.6 Pendientes para las etapas 3 a 5

**Etapa 3 — Renovación, mantenimiento y planes**

- Renovación con fecha de pago y la regla de tolerancia (contrato, sección 5.3). `CalendarPeriod::addMonths` ya admite el ancla guardada.
- Conversión de una prueba en pago y compra de mantenimiento de una perpetua.
- Registrar una renovación al aprobar una solicitud `renew` sin internet.
- Editar el periodo inicial de una suscripción sin activar.
- Editor de planes y su propagación a las licencias existentes; una reducción nunca se aplica a licencias vigentes.
- Rediseño de `subscription_periods` y `maintenance_purchases`, hoy sin escrituras.
- Casos de prueba de renovación y cambio de plan en `contracts/v1/fixtures/`.
- Límite conocido del motor de fechas (DECISIONS.md, sección 10, Fase 3, decisión 8), que importa al encadenar periodos.

**Etapa 4 — Transferencia por autoservicio y alertas**

- Ruta `POST /v1/activations/transfer` y acción `transfer` del desafío.
- `TRANSFER_LIMIT` y el cálculo real de `transfer_available`.
- Confirmación de la transferencia por la computadora anterior.
- Alertas del panel: transferencia pendiente y más de una transferencia en 12 meses.
- Agregar `transfer` a `openapi.yaml`.

**Etapa 5 — Panel definitivo y despliegue**

- Pantallas definitivas y diseño final de las constancias.
- `limit_req` de Nginx para la API.
- Despliegue en `licensing.sicrat.com` con `APP_ENV=production` y `LICENSE_ENVIRONMENT=test`.

**Integración de AD-EL POS (fuera de este repositorio)**

- `contracts/v1/verify/` es la base del verificador del servidor Go del restaurante.
- Las llaves públicas del entorno se exportan con `php bin/signing-key.php --action=export`.

### E2-9.7 Comandos para reproducir la verificación

```sh
# Dependencias (las de npm y las dos de desarrollo de Composer no van al servidor)
composer install
npm ci --ignore-scripts

# Suite completa contra MySQL 8.0.46 aislado (377 pruebas, 27 440 aserciones)
env TEST_MYSQL_DSN='mysql:unix_socket=/tmp/mysql.sock;charset=utf8mb4' TEST_MYSQL_USER=root \
    TEST_MYSQL_PASSWORD='<contraseña de root de pruebas>' \
    TEST_MYSQL_BIN=/ruta/mysql TEST_MYSQLDUMP_BIN=/ruta/mysqldump \
    TEST_FPM_BIN=/ruta/php-fpm TEST_NGINX_BIN=/ruta/nginx TEST_OPENSSL_BIN=/ruta/openssl \
    TEST_PDFTOTEXT_BIN=/ruta/pdftotext \
    php vendor/bin/phpunit

# Archivos del contrato
php contracts/v1/generate-fixtures.php --check
env GOTOOLCHAIN=local GOPROXY=off GOSUMDB=off go run -C contracts/v1/verify . ../fixtures/vectors.json

# Formato del código
npm run format:check

# Panel y dispositivos de confianza con Playwright (mismas variables TEST_*)
php tests/run-panel-tests.php all

# Antes de cada commit
php bin/check-secrets.php

# Paquete de despliegue (el destino no debe existir)
php bin/build-release.php --output=/ruta/nueva/adelpos-license.tar.gz

# Cierre: marcas de herencia (solo deben aparecer AVANCE.md y la especificación de la etapa)
git grep -l "HEREDADO"
git grep -n "PENDIENTE etapa" -- src bin templates
```

**Resultado de la verificación final (2026-11-16):**

| Comprobación | Resultado |
| --- | --- |
| PHPUnit | 377 pruebas, 27 440 aserciones, todas OK |
| `generate-fixtures.php --check` | Comprobado: 11 licencias, 17 casos negativos, 6 de revisión, 24 de fechas, 13 estados temporales, 4 pruebas de posesión y 3 solicitudes `.licreq` |
| Verificador de referencia en Go | OK en todos los casos |
| `npm run format:check` | Sin diferencias |
| Playwright | Panel y dispositivos de confianza OK; datos sintéticos eliminados |
| `bin/check-secrets.php` | Sin hallazgos |
| `bin/build-release.php` | Paquete de 133 archivos más `RELEASE.json`; del contrato, solo el esquema y el OpenAPI |
| Contrato | SHA-256 `a4c3bfd0ec428b7dc14434e294d6fbb10940c8992718b7d4db2a0703f1f554bb`, sin cambio |

---

# Avance — Etapa 3: planes y ciclo comercial

Especificación: [docs/ETAPA_3_PLANES_Y_CICLO_COMERCIAL.md](docs/ETAPA_3_PLANES_Y_CICLO_COMERCIAL.md). Fuente de verdad: el contrato V1.0, que no se modifica. Las decisiones del propietario para la etapa están en `DECISIONS.md`, sección 11.

El propietario pidió trabajar las fases 0 a 9 sin detenerse entre ellas. Lo que el contrato o la especificación no resuelven se decide con el criterio más conservador y coherente con el contrato, se aplica y queda como propuesta V1.1 nueva (desde la n.º 23) en las consultas del cierre.

## Etapa 3 · Fase 0 — Verificación y preparación

Fecha: 2026-11-17. Estado: **cerrada**.

### E3-0.1 Estado de partida

| Comprobación | Resultado |
| --- | --- |
| Commit | `72b9098` «Etapa 2, Fase 9: cierre de la etapa». Árbol limpio, salvo la especificación de esta etapa, que llegó sin versionar y se agrega en este commit. |
| PHPUnit, PHP 8.5.7 | 377 pruebas, 27 440 aserciones, todas OK (1 min 46 s) |
| `generate-fixtures.php --check` | Comprobado: 11 licencias, 17 casos negativos, 6 de revisión, 24 de fechas, 13 estados temporales, 4 pruebas de posesión y 3 solicitudes |
| Verificador de referencia en Go 1.25.5 | OK en todos los casos |
| Contrato | SHA-256 `a4c3bfd0ec428b7dc14434e294d6fbb10940c8992718b7d4db2a0703f1f554bb`, sin cambio |
| `bin/check-secrets.php` y `npm run format:check` | Sin hallazgos; sin diferencias |

### E3-0.2 PHP 8.3 real

**Instalación.** Homebrew trae `php@8.3` en su repositorio principal, así que no hizo falta el tap `shivammathur/php`. Se instaló como *keg-only* en `/opt/homebrew/opt/php@8.3`: no reemplaza al `php` 8.5 que usa el resto del equipo.

- Se descartó un contenedor: desde Docker en macOS no se alcanza el socket `/tmp/mysql.sock`, y las cuentas que crean las pruebas son `@'localhost'`.
- Para instalarlo, Homebrew actualizó 27 bibliotecas compartidas (curl, openldap, sqlite, pcre2, krb5, libzip, freetype y otras) e instaló `openssl@4`, `icu4c@79` y `aspell`. No se actualizaron los programas que dependen de ellas. Después se comprobó que PHP 8.5.7, PHP-FPM 8.5.7, Nginx 1.31.3, OpenSSL 3.6.3 y pdftotext 26.08.0 siguen funcionando, y la suite volvió a pasar en 8.5.

| Dato | Valor |
| --- | --- |
| Versión | PHP 8.3.36 (cli y fpm-fcgi), compilado el 2026-10-19, Zend OPcache 8.3.36 |
| Extensiones que exige `composer.json` | gd, mbstring, PDO, pdo_mysql 8.3.36, sodium (libsodium 1.0.22) y xmlwriter: todas presentes |
| Otras extensiones de la prueba | intl (ICU 79.1), openssl, zip, curl, json, ctype, tokenizer, dom |
| Datos de zonas horarias | 2026.1, 419 zonas: los mismos que PHP 8.5.7 |
| PHP-FPM de las pruebas con Nginx | `TEST_FPM_BIN=/opt/homebrew/opt/php@8.3/sbin/php-fpm` |
| Resultado | **377 pruebas, 27 440 aserciones, todas OK** (1 min 29 s), incluidos el motor de fechas (`CalendarPeriodTest`), los archivos del contrato (`ContractFilesTest`), las pruebas con Nginx y PHP-FPM reales y los respaldos con `mysqldump` 8.0.46 |
| `generate-fixtures.php --check` con PHP 8.3 | Comprobado, mismos 81 casos |

El VPS usará PHP 8.3.6 (decisión 1 de la etapa 1); aquí es 8.3.36, la misma versión menor. **Nada falló en PHP 8.3**, así que no hubo commit de corrección.

### E3-0.3 Migración inicial reformateada (decisión D)

- `001_esquema_inicial.sql` se reescribió con la regla de código legible: una columna o restricción por línea, tabuladores, y cada `CHECK` con una condición por línea. Las líneas pasaron de hasta 238 columnas a menos de 120.
- **No cambió el orden** de tablas, columnas, índices ni llaves foráneas. MySQL nombra las llaves foráneas sin nombre (`admin_sessions_ibfk_1`…) según su orden, así que cambiarlo habría cambiado el esquema.
- `InitialSchemaTest` sigue pasando: el esquema que produce es idéntico al de la cadena heredada, salvo el endurecimiento ya registrado.
- `CodeStyleTest` ya no excluye este archivo. **Regla comprobada:** con una línea indentada con espacios dentro de la `001`, la prueba falla y nombra la línea.
- Cambia el checksum del archivo. No hay instalaciones, así que no afecta a nadie.

### E3-0.4 `DECISIONS.md`

- Sección 9: las propuestas V1.1 n.º 14 a 20 quedan aprobadas, y se agregan la **n.º 21** (un cambio de catálogo nunca reduce licencias existentes, ni al renovar: sustituye una frase de §11) y la **n.º 22** (reglas de precios, conversión, cambio de plan, bonificación y mantenimiento).
- Sección 10, repaso de formato: la decisión 4 registra la autorización de la decisión D.
- Sección 11, nueva: las 10 decisiones del propietario para esta etapa.

### E3-0.5 Marcas `PENDIENTE etapa 3` y la fase que las resuelve

```sh
git grep -n "PENDIENTE etapa 3" -- src bin templates
```

| Archivo | Qué queda pendiente | Fase |
| --- | --- | --- |
| `src/Application/LicenseService.php:10` | Renovación con fecha de pago y tolerancia | 3 |
| | Conversión de prueba a pago | 4 |
| | Compra de mantenimiento y edición del periodo inicial | 6 |
| | Editor de planes y propagación | 2 |
| `src/Application/OfflineService.php:109` | Registrar una renovación al aprobar un `renew` sin internet | 3 |
| `src/Http/Kernel.php:491` | Rutas de renovación y mantenimiento, hoy rechazadas | 3 y 6 |
| `templates/license-detail.php:184` | Formularios de renovación y mantenimiento («Disponible próximamente») | 6 y 9 |

Los pendientes de etapa 3 que `AVANCE.md` (E2-9.6) y `DECISIONS.md` registran fuera del código:

| Pendiente | Fase |
| --- | --- |
| Rediseño de `subscription_periods` y `maintenance_purchases`, hoy sin escrituras | 1 |
| Límite conocido del motor de fechas al encadenar periodos (`DECISIONS.md`, sección 10, Fase 3, decisión 8) | 3 |
| Casos de renovación, conversión, cambio de plan y mantenimiento en `contracts/v1/fixtures/` (propuesta V1.1 n.º 6) | 8 |
| Edición del periodo inicial de una licencia sin activar (propuesta V1.1 n.º 11) | 6 |

### E3-0.6 Plan de la etapa

| Fase | Migración | Contenido |
| --- | --- | --- |
| 1 | `005` | Precios opcionales en `plans`; tabla de pagos de solo inserción que reemplaza a `subscription_periods` y `maintenance_purchases`; monto de alta al emitir |
| 2 | `006` | Estado y versión de los planes; editor solo para el superadministrador; propagación de aumentos en una sola transacción |
| 3 | — | Renovación (§5.3), su vía sin internet y el límite del motor de fechas |
| 4 | — | Conversión de prueba a cualquier tipo y plan |
| 5 | `007` | Cambio de plan con bonificación y cambios programados |
| 6 | — | Mantenimiento anual y edición del periodo inicial |
| 7 | — | `bin/apply-scheduled-changes.php` en la tarea diaria |
| 8 | — | Archivos del contrato nuevos |
| 9 | — | Panel, Playwright, documentación y paquete |

### E3-0.7 Pruebas de la fase

| Comprobación | Resultado |
| --- | --- |
| PHPUnit, PHP 8.5.7 | 377 pruebas, 27 440 aserciones, todas OK |
| PHPUnit, PHP 8.3.36 | 377 pruebas, 27 440 aserciones, todas OK |
| Playwright (panel y dispositivos de confianza) | OK |
| Archivos del contrato y verificador Go | Sin cambios; OK |

### E3-0.8 Comandos para reproducir

```sh
# PHP 8.3 sin reemplazar al PHP del equipo (Homebrew, keg-only)
HOMEBREW_NO_AUTO_UPDATE=1 HOMEBREW_NO_INSTALL_UPGRADE=1 HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK=1 \
    brew install php@8.3

# Suite completa en PHP 8.3 (variables TEST_* de E2-9.7, con el PHP-FPM de 8.3)
env TEST_FPM_BIN=/opt/homebrew/opt/php@8.3/sbin/php-fpm ... \
    /opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit
/opt/homebrew/opt/php@8.3/bin/php contracts/v1/generate-fixtures.php --check
```

---

## Etapa 3 · Fase 1 — Precios y registro de pagos

Fecha: 2026-11-17. Estado: **cerrada**.

### E3-1.1 Qué cambió

- **Migración `005_precios_y_pagos.sql`:**
  - seis precios opcionales en `plans` (mensual, trimestral, semestral, anual, perpetua y mantenimiento anual), `DECIMAL(10,2)`, vacíos o no negativos;
  - tabla `license_payments`, de solo inserción, con concepto, plazo, fecha de pago, monto cobrado en MXN, precio sugerido, bonificación, motivo, referencia, periodo que cubre, administrador y fecha de registro;
  - `licenses.period_payment_id`: el pago que cubre el periodo vigente;
  - se eliminan `subscription_periods` y `maintenance_purchases`, que nunca tuvieron escrituras.
- **`Domain\Money`:** montos en centavos enteros; lectura estricta de lo que escribe el administrador y formato `$1,500.50 MXN`.
- **`Application\Payments`:** registra cada pago en la transacción de su operación, valida el motivo frente al monto sugerido, lee la fecha de pago en la zona del cliente y audita el pago (`payment.recorded`).
- **Emisión (`LicenseService::issue`):** pide el monto de alta y registra su pago. Sugiere el precio del catálogo del plan y del plazo; una licencia personalizada no tiene precio; una prueba vale 0.
- **`CalendarPeriod::localInstant()`:** fecha y hora locales a UTC con las reglas de la sección 5.1 (hora repetida y hora inexistente).
- **Permisos y auditoría:** `license_payments` con `SELECT, INSERT`; `bin/preflight.php` comprueba en producción que no se pueda modificar ni borrar; etiqueta «Pago registrado».
- **Panel:** el formulario de emisión tiene la sección «Pago de alta», con el monto sugerido, la fecha de pago y el motivo, que solo se habilita si el monto cambia. El detalle de la licencia muestra la tabla «Pagos».

### E3-1.2 Pruebas

| Archivo | Pruebas | Qué comprueba |
| --- | --- | --- |
| `tests/Unit/MoneyTest.php` (nueva) | 3 | Montos escritos a centavos exactos; negativos, comas, signo de pesos, más de dos decimales y montos mayores que el máximo rechazados; formato y valores guardados |
| `tests/Integration/PaymentsTest.php` (nueva) | 5 | Precios opcionales y no negativos; monto sugerido y modificable con motivo, personalizada y sin precio, prueba en 0; fecha de pago en la zona del cliente, nunca futura; solo inserción y reglas de la base; idempotencia y auditoría de cada pago |
| `AdministrationTest` | — (cambian 2 aserciones) | Las que contaban filas de `subscription_periods` y `maintenance_purchases` cuentan ahora los pagos de alta |
| `OfflineTest` | — (cambia 1 aserción) | Igual: entregar los derechos vigentes no registra ningún pago además del de alta |
| `InitialSchemaTest` | — | Lista de migraciones con la `005`; 33 tablas con `schema_migrations` |
| `tests/panel.cjs` | — | Monto sugerido con el precio del catálogo, motivo al cambiarlo, prueba en 0, personalizada sin precio y la tabla de pagos del detalle |

`MySqlFixture::terms()` envía ahora un monto de 0: el catálogo inicial no tiene precios, así que no hay nada sugerido.

### E3-1.3 Reglas comprobadas quitándolas una a la vez

Se quitaron **23 reglas**, una a la vez, con `PaymentsTest`, `MoneyTest` o `AdministrationTest`:

- precios no negativos; monto no negativo y monto máximo al escribirlo;
- motivo obligatorio si el monto cambia, y rechazado si no cambia;
- prueba con monto 0; fecha de pago no futura y leída en la zona del cliente;
- monto obligatorio al emitir; licencia personalizada sin precio de catálogo;
- el pago de alta paga el periodo; auditoría del pago; permisos de solo inserción;
- en la base: lo sugerido igual al catálogo menos la bonificación, el motivo en los dos sentidos, la prueba sin costo, MXN, montos no negativos, concepto cerrado, plazo de mantenimiento, periodo completo y el pago del periodo de la misma licencia.

**22 se detectaron a la primera.** La de montos no negativos en la base no: el `-1.00` de la prueba lo rechazaba también otra restricción. Se agregaron tres casos negativos que cumplen las demás reglas, y ahora se detecta.

### E3-1.4 Resultado

| Comprobación | Resultado |
| --- | --- |
| PHPUnit, PHP 8.5.7 | 385 pruebas, 27 603 aserciones, todas OK |
| PHPUnit, PHP 8.3.36 | 385 pruebas, 27 603 aserciones, todas OK |
| Playwright | Panel y dispositivos de confianza OK |
| `npm run format:check` y `bin/check-secrets.php` | Sin diferencias; sin hallazgos |

### E3-1.5 Pendientes que deja la fase

- Los precios todavía no se editan en el panel: el editor de planes es de la Fase 2.
- La regla «la fecha de pago no es anterior al último pago de la licencia» se ejercita en la Fase 3, cuando una licencia ya tiene un pago anterior.

---

## Etapa 3 · Fase 2 — Editor de planes y propagación de aumentos (contrato §11 y decisión 9)

Fecha: 2026-11-17. Estado: **cerrada**.

### E3-2.1 Qué cambió

- **Migración `006_editor_de_planes.sql`:** `plans.state` (`active` o `archived`), `plans.row_version` y el formato del identificador en la base.
- **`Application\PlanService`**, solo para el superadministrador:
  - crea un plan con su identificador, nombre, cupos y precios;
  - edita nombre, cupos y precios, con bloqueo optimista por `row_version`;
  - archiva un plan o lo vuelve a ofrecer. Un plan nunca se borra: la cuenta de la aplicación no tiene `DELETE` sobre `plans`.
- **Propagación:** al guardar se elige «solo licencias nuevas» o «también las existentes que siguen este plan». Con la segunda, solo suben los cupos que subieron en el catálogo, y solo en licencias no personalizadas ni revocadas: perpetuas, sin activar, y suscripciones y pruebas vigentes o en tolerancia. Se firma una revisión para cada activación vigente. Todo va en una transacción: si algo falla a la mitad, no cambia nada.
- **Nombre del plan:** `RevisionPublisher` toma el nombre del catálogo cada vez que firma, por cualquier motivo. Renombrar un plan no firma por sí solo.
- **Emisión:** un plan archivado ya no se ofrece ni se acepta.
- **Panel:** «Planes» en la navegación, con la lista de planes (cupos, precios y estado), el formulario del plan, la elección de a qué licencias aplica y el resultado: «Licencias actualizadas: N. Revisiones firmadas: M.».
- **Auditoría:** `plan.created`, `plan.updated` (cada campo con su valor anterior y nuevo, los cupos que subieron y el resultado), `plan.archived`, `plan.restored`, y en el historial de cada licencia, «Aumento del catálogo aplicado».

### E3-2.2 Pruebas

| Archivo | Pruebas | Qué comprueba |
| --- | --- | --- |
| `tests/Integration/PlanCatalogTest.php` (nueva) | 8 | Crear, editar y archivar, y que un plan archivado no se ofrece; formato del identificador y estado en la base; permisos (operador y consulta reciben 403); propagación solo a nuevas y también a existentes, con la licencia sin activar que firma los números nuevos al activarse; cambio mixto (sube cajas y baja comanderos); una licencia con un número mayor que el del plan no baja; personalizadas, revocadas y vencidas sin tolerancia no cambian, con el límite exacto de la tolerancia; reintento idéntico; fallo a la mitad sin la llave de firma y reintento completo; nombre firmado al volver a firmar |
| `tests/Installation.php` (nuevo) | — | Instalación sintética que activa en línea y lee el contenido firmado; la usan las pruebas de esta etapa |
| `ContractDataModelTest` | — (cambia 1 aserción) | La cuenta de la aplicación ya inserta y actualiza planes; borrar sigue prohibido |
| `tests/panel.cjs` | — | Las páginas del catálogo responden, y la cuenta de consulta las ve pero no puede crear planes |

### E3-2.3 Reglas comprobadas quitándolas una a la vez

Se quitaron **24 reglas** con `PlanCatalogTest`: permisos de crear, editar y archivar; propagar solo si se elige; solo aumentos del catálogo; nunca bajar una licencia; excluir personalizadas, revocadas y vencidas; límite exclusivo de la tolerancia; incluir sin activar y perpetuas; firmar al propagar; historial por licencia; formulario obsoleto; auditoría con valores anteriores y nuevos; plan archivado al emitir y en la lista; nombre firmado al volver a firmar; identificador en el servicio y en la base; estado en la base; sin permiso de borrar; y todo o nada al fallar.

**23 se detectaron.** «Solo aumentos del catálogo» no: la comprobación de cada licencia la cubría, y al revés. Se agregaron dos aserciones que las distinguen: la auditoría registra solo los cupos que subieron, y una licencia con un número mayor que el del plan no baja. Ahora se detectan las dos.

### E3-2.4 Resultado

| Comprobación | Resultado |
| --- | --- |
| PHPUnit, PHP 8.5.7 | 393 pruebas, 27 697 aserciones, todas OK |
| PHPUnit, PHP 8.3.36 | 393 pruebas, 27 697 aserciones, todas OK |
| Playwright | Panel y dispositivos de confianza OK |

### E3-2.5 Pendientes que deja la fase

- Los cambios de plan programados (Fase 5) que apunten a un plan con un aumento también deben subir sus números: se agrega en la Fase 5, cuando existan.
- El recorrido completo del editor con Playwright (editar y propagar) es de la Fase 9.

---

## Etapa 3 · Fase 3 — Renovación de suscripciones (contrato §5.3)

Fecha: 2026-11-17. Estado: **cerrada**.

### E3-3.1 Qué cambió

- **`CalendarPeriod::renewal()`:** la regla de la fecha base de §5.3, con la tolerancia en horas exactas y exclusiva.
- **Límite del motor de fechas, resuelto.** Se buscó en los datos de zonas horarias un cambio de horario que hiciera inexistente la hora ancla del último día de un mes y la llevara al mes siguiente. Hay cinco casos reales (Singapur y Kuala Lumpur en 1981, Sofía en 1979, Kanton y Kiritimati en 1994). `addMonths` cuenta ahora desde el mes del ancla cuando el instante de partida es ese ancla resuelto, y el periodo encadenado ya no se salta un mes.
- **`Application\CommercialService`:** renovación con vista previa (`quoteRenewal`) y confirmación (`renew`). Registra el pago (`renewal`, con el periodo que cubre), cambia las fechas y, si se elige, la duración, firma una revisión nueva para la activación vigente y deja el cambio en el historial (`license.renewed`).
- **Sin internet:** al aprobar un `renew`, «Registrar la renovación con su pago y entregarla». Resuelve el `PENDIENTE etapa 3` de `OfflineService`.
- **Panel:** tarjeta «Renovar la suscripción» en el detalle de una suscripción activada; pantalla «Renovar suscripción» con vencimiento actual, fin de la tolerancia, fecha de pago, periodo nuevo y monto sugerido; y los campos de la renovación en la aprobación sin internet.
- `LicenseService::PENDING` ya no incluye la renovación; la ruta `/renew` va a `CommercialService`.

### E3-3.2 Pruebas

| Archivo | Pruebas | Qué comprueba |
| --- | --- | --- |
| `tests/Unit/RenewalDatesTest.php` (nueva) | 5 | Los cuatro ejemplos de §5.3; el límite exacto de la tolerancia (un microsegundo antes, en el límite) y las 120 horas exactas a través de un cambio de horario; renovación encadenada con día ancla 31; cambio de duración; zona del cliente con cambio de horario; y el periodo encadenado que no se salta un mes en Singapur, Kuala Lumpur y Sofía |
| `tests/Integration/RenewalTest.php` (nueva) | 7 | Los cuatro ejemplos y el límite con la licencia activada, recibidos por la computadora en línea en la misma activación; cupos, marca de personalizada, cambio de duración y pago con su periodo; monto sugerido de la duración elegida y vista previa sin escrituras; cadena de día ancla 31; zona de Tijuana con cambio de horario y pago tardío; rechazos (perpetua, prueba, sin activar, revocada, fecha futura o anterior al último pago, sin monto, cuenta de consulta); reintento idéntico y **dos operadores a la vez** en dos procesos reales: uno renueva y el otro recibe 409 |
| `tests/Concurrency.php` y `tests/commerce-worker.php` (nuevos) | — | Procesos separados que se bloquean sobre la misma licencia; los usan las pruebas de concurrencia de esta etapa |
| `OfflineTest` | 1 sustituida | `testRenewDeliversCurrentRightsOrRecordsARenewalWithItsPayment`: sin monto no se registra; con monto se registra y se entrega la revisión 2; una desactivación no puede registrar una renovación; una computadora que liberó la licencia recibe su cierre, no una renovación |
| `ActivationsTest` | 1 sustituida | La renovación firma una revisión nueva y la consulta en línea la entrega |
| `AdministrationTest` | 1 sustituida | Solo el mantenimiento sigue rechazado; la renovación dejó de ser un cambio genérico |
| `StageClosureTest` | — | `OfflineService` ya no tiene marca de etapa 3 |
| `panel.cjs`, `offline-panel.cjs` | — | Renovar una perpetua se rechaza con el mensaje nuevo; la aprobación sin internet entrega la revisión vigente o registra la renovación trimestral y entrega la revisión 2 |

### E3-3.3 Reglas comprobadas quitándolas una a la vez

Se quitaron **26 reglas**, todas detectadas: tolerancia exclusiva y en horas exactas; base y ancla según la tolerancia; mes del ancla al encadenar; duración elegida y duraciones válidas; cupos y marca de personalizada conservados; rechazo de perpetua, prueba, sin activar y revocada; firma de la revisión; pago del periodo y periodo que cubre; precio de la duración elegida; personalizada sin precio; monto obligatorio; versión de la licencia; permisos; fecha no anterior al último pago; y, sin internet, solo con `renew`, registrar la renovación, entregar la revisión nueva y exigir la computadora activa (esta última tuvo su prueba después de quitarla: no la había).

### E3-3.4 Resultado

| Comprobación | Resultado |
| --- | --- |
| PHPUnit, PHP 8.5.7 | 405 pruebas, 27 823 aserciones, todas OK |
| PHPUnit, PHP 8.3.36 | 405 pruebas, 27 823 aserciones, todas OK |
| Playwright | Panel, sin internet y dispositivos de confianza OK |

### E3-3.5 Pendientes que deja la fase

- Renovación con un cambio programado pendiente (Fase 7) y renovación con plan nuevo después del vencimiento (Fase 5).
- El recorrido de renovar desde el detalle con Playwright es de la Fase 9.

---

## Etapa 3 · Fase 4 — Conversión de prueba a pago (contrato §5.4 y decisión 5)

Fecha: 2026-11-17. Estado: **cerrada**.

### E3-4.1 Qué cambió

- **`CommercialService::convert()` y `quoteConversion()`:** de una prueba a una suscripción con cualquier periodo o a una perpetua con 0, 6 o 12 meses de actualizaciones, con cualquier plan o cupos personalizados, durante la prueba o después de vencida. El pago se registra con el concepto `conversion`, sin bonificación.
  - **Prueba activada:** el periodo nuevo empieza en la fecha de pago, los días de prueba no se acumulan, la tolerancia pasa a ser la del tipo nuevo y se firma una revisión nueva para la misma activación.
  - **Prueba sin activar:** cambian sus condiciones y las fechas se fijan al activar, como en cualquier licencia.
- **Panel:** tarjeta «Convertir en licencia de pago» en el detalle de una prueba, con tipo, periodo o meses de actualizaciones, plan y cupos; pantalla «Convertir la prueba en licencia de pago» con el periodo nuevo y el monto sugerido. Fragmento `target-fields.php` para elegir plan y cupos, que reutilizan las fases 5 y 6.

### E3-4.2 Pruebas

| Archivo | Pruebas | Qué comprueba |
| --- | --- | --- |
| `tests/Integration/ConversionTest.php` (nueva) | 4 | Prueba activada a suscripción durante la prueba: misma activación, revisión 2, periodo desde el pago, tolerancia de 5 días, plan nuevo, pago de conversión con su periodo; vista previa sin escrituras, personalizada sin precio y 6 meses por omisión; prueba vencida a perpetua con 0, 6 y 12 meses; prueba sin activar a suscripción personalizada y a perpetua, con las fechas al activar; rechazos (no es prueba, tipo de destino, sin plan, plan inexistente o archivado, pago antes del inicio de la prueba, sin monto, cupo fuera de rango, revocada) |

### E3-4.3 Reglas comprobadas quitándolas una a la vez

Se quitaron **16 reglas** con `ConversionTest`: solo una prueba, no revocada, destino suscripción o perpetua, plan archivado, plan existente y bloqueado, pago no anterior al inicio de la prueba, periodo desde la fecha de pago, fechas al activar si no está activada, tolerancia del tipo nuevo, marca de personalizada, personalizada sin precio, firma para la misma activación, pago del periodo, periodo que cubre el pago, monto obligatorio y 6 meses por omisión.

**14 se detectaron a la primera.** «Personalizada sin precio» no: el plan de la prueba no tenía precios, y además la vista previa calculaba la sugerencia por separado. Se unificó el cálculo y se agregó un caso con un plan con precio. «6 meses por omisión» tampoco: se agregó la aserción. Ahora se detectan las dos.

### E3-4.4 Resultado

| Comprobación | Resultado |
| --- | --- |
| PHPUnit, PHP 8.5.7 | 409 pruebas, 27 865 aserciones, todas OK |
| PHPUnit, PHP 8.3.36 | 409 pruebas, 27 865 aserciones, todas OK |
| Playwright | Panel, sin internet y dispositivos de confianza OK |

### E3-4.5 Pendientes que deja la fase

- El recorrido de convertir una prueba con Playwright es de la Fase 9.

---

## Etapa 3 · Fase 5 — Cambio de plan con bonificación (decisiones 6, 7 y 8)

Fecha: 2026-11-17. Estado: **cerrada**.

### E3-5.1 Qué cambió

- **`Domain\Credit`:** la bonificación exacta, al centavo, con enteros.
- **Migración `007_cambios_programados.sql`:** tabla `license_scheduled_changes`, con un pendiente como máximo por licencia.
- **`Application\ScheduledChanges`:** programa, sustituye, cancela y aplica un cambio programado (al llegar su instante).
- **`CommercialService::changePlan()`, `quotePlanChange()` y `cancelScheduledChange()`.** Se clasifica cupo por cupo:

  | Licencia | Cambio | Qué pasa |
  | --- | --- | --- |
  | Prueba | Cualquiera | Inmediato, sin cobro, sin cambiar fechas |
  | Perpetua | Sube | Inmediato; se sugiere la diferencia de los precios perpetuos; `updates_until` no cambia |
  | Perpetua | Baja o mixto | Se rechaza |
  | Suscripción vigente | Aumento o mixto | Periodo nuevo desde la fecha de pago, con bonificación; aumentos inmediatos; reducciones programadas para el vencimiento anterior |
  | Suscripción vigente | Solo reducción | Programado para el vencimiento, sin cobro |
  | Suscripción pagada después del vencimiento | Cualquiera | Renovación con plan nuevo, sin bonificación, con los números nuevos |

- **Editor de planes:** un aumento del catálogo también sube los cambios programados hacia ese plan.
- **Panel:** tarjeta «Cambiar de plan o de cupos» en el detalle; pantalla de revisión con días restantes, monto pagado, bonificación, precio nuevo, monto sugerido y fecha de las reducciones; tarjeta «Cambio programado» con «Cancelar el cambio programado».
- **Auditoría:** «Plan cambiado», «Cambio de plan programado», «Cambio programado cancelado» y «Cambio programado aplicado», y `AuditLabelsTest` las vigila.

### E3-5.2 Pruebas

| Archivo | Pruebas | Qué comprueba |
| --- | --- | --- |
| `tests/Unit/CreditTest.php` (nueva) | 2 | Bonificación de 900.00 y 850.00 con 16 de 31 días; inicio y fin del periodo; redondeo de la mitad hacia arriba; segundos completos; pago antes del periodo o después del vencimiento; el monto más alto con un año bisiesto; montos y periodos inválidos |
| `tests/Integration/PlanChangeTest.php` (nueva) | 8 | Aumento con bonificación exacta con el monto pagado registrado (438.71) y con el precio de catálogo (464.52); monto modificado con motivo; bonificación mayor que el precio nuevo, cobro 0; cambio mixto con aumento inmediato, número mayor conservado y reducción programada al vencimiento anterior, que sube con la propagación y se aplica antes del siguiente cambio; reducción pura programada sin cobro, sustituida, cancelada y única en la base; pago en tolerancia y después de ella tratados como renovación; perpetua que sube con la diferencia y rechaza bajar, el cambio mixto y otro plan con los mismos números; prueba sin cobro en los dos sentidos; personalizada sin precio y que pierde la marca al elegir un plan; rechazos; **dos operadores a la vez** |
| `AuditLabelsTest` | — | También las operaciones del historial comercial |

Los montos esperados de las bonificaciones se calcularon por separado, con enteros, fuera del código del servidor.

### E3-5.3 Reglas comprobadas quitándolas una a la vez

Se quitaron **28 reglas**, todas detectadas a la primera: redondeo, límites y segundos completos de la bonificación; monto pagado como base y precio del catálogo sin pago; sugerido nunca menor que 0; destino personalizado sin precio; periodo desde la fecha de pago; en el mixto, el número mayor y la reducción al vencimiento anterior; reducción programada y sin cobro; un solo pendiente (en el servicio y en la base); cancelar; pago después del vencimiento como renovación, con los números nuevos; perpetua que no baja, que tiene que subir algo y que paga la diferencia; prueba sin cobro; aplicar antes el cambio vencido; propagación a lo programado; mismo plan y cupos; sin activar; revocada; y el pago del cambio como pago del periodo. Además, dos etiquetas de auditoría: la segunda no se detectaba por un límite de la prueba de etiquetas, que se corrigió.

### E3-5.4 Resultado

| Comprobación | Resultado |
| --- | --- |
| PHPUnit, PHP 8.5.7 | 419 pruebas, 27 951 aserciones, todas OK |
| PHPUnit, PHP 8.3.36 | 419 pruebas, 27 951 aserciones, todas OK |
| Playwright | Panel, sin internet y dispositivos de confianza OK |

### E3-5.5 Pendientes que deja la fase

- La tarea programada que aplica los cambios al llegar su instante, y su relación con la renovación, son de la Fase 7.
- El recorrido de subir de plan con bonificación y de programar y cancelar una reducción con Playwright es de la Fase 9.

---

## Etapa 3 · Fase 6 — Mantenimiento de la perpetua y periodo inicial

Fecha: 2026-11-17. Estado: **cerrada**.

### E3-6.1 Qué cambió

- **`CalendarPeriod::maintenance()`** y **`CommercialService::buyMaintenance()` / `quoteMaintenance()`:** 12 meses de actualizaciones desde la fecha posterior entre el pago y el fin vigente, con el precio de mantenimiento anual del plan, el pago `maintenance` y una revisión nueva.
- **`CommercialService::editInitialTerms()`:** tipo, periodo o duración, meses de actualizaciones, plan y cupos de una licencia sin activar (propuesta V1.1 n.º 11). Sin pago y sin firma.
- **`LicenseTerms::fromInput()`:** la lectura del tipo y su condición, compartida con la emisión.
- **Panel:** tarjetas «Comprar 12 meses de actualizaciones» (perpetua activada) y «Editar antes de activar» (licencia sin activar); pantallas «Comprar mantenimiento» y «Editar el periodo inicial». Se retiró «Disponible próximamente» y la nota equivalente del formulario de emisión.
- **Marcas:** ya no queda ninguna `PENDIENTE etapa 3` en `src`, `bin` ni `templates`; `StageClosureTest` lo comprueba.

### E3-6.2 Pruebas

| Archivo | Pruebas | Qué comprueba |
| --- | --- | --- |
| `tests/Integration/MaintenanceTest.php` (nueva) | 5 | Mantenimiento antes de que terminen las actualizaciones y encadenado dos veces, con su pago y la misma activación; después de que terminaron (perpetua de 0 meses), desde la fecha de pago con su ancla; personalizada sin precio; rechazos en suscripción, prueba, perpetua sin activar, revocada y sin monto; edición del periodo inicial (a perpetua personalizada y a prueba), con la auditoría de cada campo, sin pagos, con las fechas al activar, y rechazos (ya activada, sin cambios, dato de otro tipo, plan archivado, revocada); y el motor de fechas del mantenimiento, con el pago justo en el fin y el 29 de febrero |
| `AdministrationTest` | 1 sustituida | La renovación y el mantenimiento ya no son cambios genéricos: responden 404 |
| `AuditLabelsTest` | — | Etiquetas de las operaciones nuevas; se retiran las dos del motor anterior |
| `StageClosureTest` | — | Solo quedan marcas de las etapas 4 y 5 |
| `tests/panel.cjs` | — | Una licencia sin activar ofrece editar su periodo inicial y no ofrece renovar ni mantenimiento; el servidor rechaza el mantenimiento de una perpetua sin activar |

### E3-6.3 Reglas comprobadas quitándolas una a la vez

Se quitaron **18 reglas**, todas detectadas: base posterior entre pago y fin vigente, el pago justo en el fin, el ancla del pago y que se guarde, solo 12 meses, solo perpetuas, activadas y no revocadas, precio de mantenimiento del plan y personalizada sin precio, firma de la revisión, plazo que cubre el pago, monto obligatorio; y en el periodo inicial: solo sin activar, no revocada, algo debe cambiar, tolerancia del tipo nuevo y auditoría con valores anteriores y nuevos. El script de mutaciones aprendió a buscar a partir de un ancla, porque la conversión y el mantenimiento comparten fragmentos idénticos.

### E3-6.4 Resultado

| Comprobación | Resultado |
| --- | --- |
| PHPUnit, PHP 8.5.7 | 424 pruebas, 27 997 aserciones, todas OK |
| PHPUnit, PHP 8.3.36 | 424 pruebas, 27 997 aserciones, todas OK |
| Playwright | Panel, sin internet y dispositivos de confianza OK |

**Falla intermitente observada.** En la primera corrida de esta fase, `TrustedDevicesHttpTest` falló una vez en cada versión de PHP: en 8.5 al iniciar sesión con TOTP (línea 99) y en 8.3 al levantar Nginx y PHP-FPM (línea 24). Es una prueba de la etapa 1 que no cambió, con Nginx, PHP-FPM y TLS reales. La siguiente corrida completa pasó en las dos versiones, y la prueba sola también. Queda vigilada en las fases siguientes (ver E3-9).

### E3-6.5 Pendientes que deja la fase

- El recorrido de mantenimiento con Playwright es de la Fase 9.

---

## Etapa 3 · Fase 7 — Tarea programada de reducciones

Fecha: 2026-11-17. Estado: **cerrada**.

### E3-7.1 Qué cambió

- **`bin/apply-scheduled-changes.php`** y **`ScheduledChanges::applyAllDue()`:** aplican los cambios programados cuyo instante ya llegó; cambian plan y cupos, firman una revisión para la activación vigente y registran la auditoría. Idempotente y seguro ante dos ejecuciones a la vez.
- **Tarea diaria:** `deploy/adelpos-license-maintenance.sh` ejecuta el comando después de la limpieza (`OPERATIONS.md`, sección 7).
- **Renovación:** después del instante aplica la reducción en su misma revisión; antes, firma los números mayores y la tarea firma la reducción al llegar el instante. Sugiere el precio del plan de destino del cambio pendiente.
- **`SECURITY.md`:** limitación aceptada para las computadoras sin internet.

### E3-7.2 Pruebas

| Archivo | Pruebas | Qué comprueba |
| --- | --- | --- |
| `tests/Integration/ScheduledChangesTest.php` (nueva) | 6 | Con reloj congelado: un microsegundo antes, justo en el instante y después; auditoría del sistema e historial de quien programó; **dos ejecuciones simultáneas** en procesos reales que aplican el cambio una sola vez; renovación antes del instante (números mayores, y la tarea firma la reducción después); renovación después del instante (una sola revisión con la reducción); licencia vencida que recibe el cambio y cuya renovación sugiere el precio del plan nuevo; cambio cancelado que ya no se aplica; y el comando real, con su archivo de configuración y el reloj real, ejecutado dos veces, y su presencia en la tarea diaria |

### E3-7.3 Reglas comprobadas quitándolas una a la vez

Se quitaron **12 reglas**, todas detectadas: instante incluido en la tarea y al aplicar, nada antes del instante, una sola vez, firma de la reducción, la renovación tardía aplica la reducción y en la misma revisión, precio del plan nuevo al renovar, se aplica aunque haya vencido, auditoría del sistema, evento de cada ejecución y el comando en la tarea diaria.

### E3-7.4 Resultado

| Comprobación | Resultado |
| --- | --- |
| PHPUnit, PHP 8.5.7 | 430 pruebas, 28 041 aserciones, todas OK |
| PHPUnit, PHP 8.3.36 | 430 pruebas, 28 041 aserciones, todas OK |
| Playwright | Panel, sin internet y dispositivos de confianza OK |

Las corridas de esta fase coincidieron con dos emuladores de Android del equipo (carga promedio de hasta 250): la suite tardó cerca de una hora por versión en lugar de dos minutos y medio, y pasó completa. La falla intermitente de E3-6.4 no se repitió.
