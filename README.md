# AD-EL POS — Licencias

Servidor de licencias de **AD-EL POS**, el punto de venta para restaurantes. Es un proyecto independiente del punto de venta: emite, firma, revoca y audita licencias, y ofrece un panel privado para el propietario.

- **Panel administrativo:** clientes, productos, catálogo de planes con precios, licencias, solicitudes sin internet, constancias PDF, administradores y auditoría.
- **Ciclo comercial:** monto de alta, renovación, conversión de una prueba, cambio de plan con bonificación, cambios programados, mantenimiento de una perpetua y edición del periodo inicial, cada uno con su pago registrado en MXN.
- **API `/v1`:** activación en línea con desafío y prueba de posesión, consulta de actualización y desactivación.
- **Flujo sin internet:** el panel importa una solicitud `.licreq`, el propietario la aprueba y entrega un archivo `.lic` firmado.

> **Estado: etapa 3 cerrada.** El servidor firma, recibe y devuelve exactamente lo que define el contrato de licencias V1.0 ([docs/CONTRATO_LICENCIAS_ADEL_POS.md](docs/CONTRATO_LICENCIAS_ADEL_POS.md)): contenido de la licencia, entornos de firma `test` y `production`, fechas de calendario, API en línea y archivos sin internet. Desde la etapa 3 administra además la vida comercial completa de una licencia **sin cambiar el contenido firmado**: precios y pagos, editor de planes, renovación, conversión, cambio de plan, mantenimiento y una tarea diaria que aplica las reducciones programadas. La forma exacta está publicada en [contracts/v1/](contracts/v1/README.md). Lo que queda para las etapas 4 y 5 está en [Pendiente para las etapas siguientes](#pendiente-para-las-etapas-siguientes). El avance y las cifras de pruebas están en [AVANCE.md](AVANCE.md).

## Requisitos

| Componente | Versión | Uso |
| --- | --- | --- |
| PHP CLI y PHP-FPM | 8.3 o posterior | Extensiones `sodium`, `pdo_mysql`, `mbstring`, `gd`, `xmlwriter`, `dom` e `iconv`, y Argon2id disponible. `bin/preflight.php` lo comprueba. |
| MySQL | 8.0 (InnoDB) | MariaDB no sirve: el migrador y `bin/preflight.php` lo rechazan. |
| Composer | 2.x | Dependencias fijadas en `composer.lock`. |
| Nginx | 1.24 o posterior | Solo en despliegue. Ejemplos en `deploy/`. |
| Node.js + Playwright, Go, Google Chrome | — | **Solo en desarrollo**, para las pruebas del panel y el verificador del contrato. El servidor no los necesita. |

## Instalación local

1. Dependencias: `composer install`. En producción: `composer install --no-dev --prefer-dist --optimize-autoloader`.
2. Crea una base vacía y dos cuentas de MySQL: una de migración y otra de aplicación con permisos mínimos. Ver [OPERATIONS.md](OPERATIONS.md).
3. Genera la configuración y sus claves. La contraseña de MySQL se pide sin mostrarla:

   ```sh
   php bin/configure.php --env=development --license-environment=test --url=http://127.0.0.1:8088 \
       --dsn='mysql:host=127.0.0.1;dbname=adelpos_license_dev;charset=utf8mb4' --user=adelpos_license_dev_app
   ```

   `--license-environment` es obligatorio: indica con qué llaves se firman las licencias (`test` o `production`) y no depende de `--env`. Ver «Llaves de firma».

   Se crea `config/local.php` con permisos 0600. Ese archivo no se versiona: `.gitignore` lo excluye y la revisión de secretos lo detectaría. `config/local.example.php` muestra las claves disponibles, sin valores secretos. Para guardar la configuración fuera del código, apunta la variable `ADELPOS_LICENSE_CONFIG` a otro archivo. Las variables de entorno con el mismo nombre que una clave tienen prioridad.
4. Aplica el esquema y los permisos:

   ```sh
   php bin/migrate.php --user=adelpos_license_dev_migrate
   php bin/grants.php --database=adelpos_license_dev --user=adelpos_license_dev_app   # imprime los GRANT; aplícalos con una cuenta autorizada
   ```

   El esquema está en `database/migrations/`. `001_esquema_inicial.sql` crea las tablas y el producto `adel_pos` («AD-EL POS»), sin módulos; las migraciones siguientes lo adaptan al contrato: `002_entornos_de_firma.sql` y `003_contrato_v1.sql`, que agrega los planes `basico`, `elemental` y `premium`. El migrador registra cada archivo con su checksum. Si repites la migración no cambia nada, y si un archivo se modifica después de aplicarlo, el migrador se detiene.
5. Crea el primer administrador y comprueba la instalación:

   ```sh
   php bin/bootstrap-admin.php --login=administrador@example.com --name='Administración'
   php bin/preflight.php
   php -S 127.0.0.1:8088 -t public public/router.php
   ```

6. Abre `http://127.0.0.1:8088`, configura la verificación en dos pasos (TOTP) y guarda los códigos de recuperación.

**Notas:**
- La raíz web es siempre `public/`, con su propio origen (sin subdirectorio).
- No hay instalador web ni cuenta predeterminada.
- En un servidor se usa `--env=production`, una URL HTTPS y PHP-FPM; la guía es [DEPLOY_UBUNTU_24_04.md](DEPLOY_UBUNTU_24_04.md). `licensing.sicrat.com` es solo para pruebas: lleva `--env=production` y `--license-environment=test`.
- Con HTTPS, después de la verificación en dos pasos se puede confiar en el navegador durante 30 días. La contraseña sigue siendo obligatoria, y la confianza se revoca en **Mi seguridad**.

### Llaves de firma

Las licencias se firman con llaves Ed25519 que se administran por CLI. La llave privada nunca sale del servidor:

```sh
php bin/signing-key.php --action=generate --user=adelpos_license_dev_migrate     # asigna adelpos-test-AAAAMM-n
php bin/signing-key.php --action=list
php bin/signing-key.php --action=export > llaves-publicas-test.json               # para incluirlas en AD-EL POS
php bin/signing-key.php --action=activate --kid=adelpos-test-202611-1 --reason='Llave pública incluida en AD-EL POS' --trust-confirmed --user=adelpos_license_dev_migrate
php bin/preflight.php --require-signer
```

- **Dos entornos de firma, separados:** `test` y `production` (contrato, sección 3). Los define `LICENSE_ENVIRONMENT`, que es obligatorio y no depende de `APP_ENV`. `LICENSE_ENVIRONMENT=production` solo se permite con `APP_ENV=production`.
- **Cada llave es de un solo entorno.** El servidor firma únicamente con la llave activa del suyo. Si la llave activa de la base es de otro entorno, el servidor no arranca y `bin/preflight.php` lo indica.
- **Carpeta por entorno.** Las llaves privadas se guardan en `SIGNING_KEY_DIR`, por omisión `var/keys/<entorno>`, con permisos 0600 y fuera de `public/`. Una carpeta no puede mezclar llaves de dos entornos.
- **Identificador (`kid`).** Si no se indica `--kid`, se asigna el siguiente con el formato `adelpos-test-AAAAMM-n` o `adelpos-prod-AAAAMM-n`. Un `kid` no puede llevar la etiqueta del otro entorno.
- **Exportación.** `--action=export` imprime en JSON las llaves públicas de licencias del entorno: `kid`, `environment` y `public_key`. No contiene nada privado. Cada comando muestra el entorno en el que opera.
- **Semillas públicas de prueba.** Las llaves de los archivos de prueba del contrato se rechazan con `LICENSE_ENVIRONMENT=production` y en cualquier servidor con `APP_ENV=production`, aunque se les cambie el nombre.
- Sin una llave activa, las operaciones que necesitan firmar responden 503 y se revierten; el panel sigue disponible.

## Pruebas

```sh
composer test:unit        # pruebas unitarias
composer test:contract    # recalcula y compara contracts/v1/fixtures sin sobrescribirlos (ver contracts/v1/README.md)

# Suite completa contra un MySQL 8 aislado, con una cuenta que pueda crear y borrar bases y usuarios de prueba:
env TEST_MYSQL_DSN='mysql:unix_socket=/tmp/mysql.sock;charset=utf8mb4' TEST_MYSQL_USER=root TEST_MYSQL_PASSWORD='…' \
    TEST_MYSQL_BIN=/ruta/mysql TEST_MYSQLDUMP_BIN=/ruta/mysqldump \
    TEST_FPM_BIN=/ruta/php-fpm TEST_NGINX_BIN=/ruta/nginx TEST_OPENSSL_BIN=/ruta/openssl TEST_PDFTOTEXT_BIN=/ruta/pdftotext \
    php vendor/bin/phpunit

# Panel con Playwright (Chrome del equipo; CHROME_PATH para otra ruta):
npm ci --ignore-scripts --no-audit --no-fund
env TEST_MYSQL_DSN=… TEST_MYSQL_USER=… TEST_MYSQL_PASSWORD=… php tests/run-panel-tests.php all

# Verificador de referencia del contrato, en Go y sin dependencias externas:
go run -C contracts/v1/verify . ../fixtures/vectors.json
```

### Estilo del código

El código no se ofusca ni se minifica: se escribe con saltos de línea e indentación con tabuladores.

```sh
npm run format         # aplica el formato a PHP, JavaScript y CSS propios
npm run format:check   # solo comprueba; es lo que ejecuta la prueba CodeStyleTest
```

Las plantillas de `templates/` se cuidan a mano: una sentencia por etiqueta PHP y los datos en variables al inicio del archivo.

- **Bases de prueba:** cada prueba crea su propia base y su propio usuario `adelpos_license_test_*` con los permisos reales de la aplicación, y los elimina al terminar. No las apuntes a una base real.
- **Producto de prueba:** el contrato fija el producto `adel_pos`, así que las pruebas lo usan y le agregan los módulos sintéticos `prueba_a` a `prueba_e`.
- **Variables `TEST_*_BIN`:** solo hacen falta si esas herramientas no están en el `PATH`.
- **Sin `TEST_MYSQL_DSN`** se omite la integración, y esa corrida no valida MySQL.
- **Detalle:** ver [TEST_PLAN.md](TEST_PLAN.md).

## Revisión de secretos antes de cada commit

Antes de cada commit se ejecuta:

```sh
php bin/check-secrets.php
```

Revisa lo que entraría al repositorio: el contenido del índice de git y los archivos nuevos que no están ignorados. Termina con error (código 1) si encuentra:

- un valor no vacío en `DB_PASSWORD` o en cualquier clave `*_KEY`;
- una llave privada PEM, o una semilla o llave secreta Ed25519;
- archivos bajo `var/keys/`, o archivos `*.key` o `*.pem`.

Muestra la ubicación de cada hallazgo, nunca su valor. Las llaves de los archivos de prueba del contrato son públicas a propósito, así que se aceptan, pero solo si su semilla es exactamente la derivada del prefijo público de prueba. No necesita `composer install`. `tests/Unit/SecretsCheckTest.php` comprueba cada caso.

Para que git la ejecute automáticamente en tu copia (opcional; el gancho no se versiona):

```sh
printf '#!/bin/sh\nexec php bin/check-secrets.php\n' > .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit
```

## Paquete de despliegue

```sh
php bin/build-release.php --output=/ruta/nueva/adelpos-license.tar.gz
```

**Qué incluye:**
- solo los archivos versionados en git de `bin/`, `src/`, `public/`, `templates/`, `database/` y `deploy/`;
- `composer.json`, `composer.lock` y `config/local.example.php`;
- la documentación de operación, `DECISIONS.md` y `docs/CONTRATO_LICENCIAS_ADEL_POS.md`;
- del contrato, solo su forma publicada: `contracts/v1/license.schema.json` y `contracts/v1/openapi.yaml`.

**Qué genera:** `RELEASE.json`, con el SHA-256 de cada archivo, y el archivo `.sha256` del paquete.

**Qué nunca incluye:** `config/local.php`, `var/`, llaves, pruebas, los archivos de prueba del contrato (`contracts/v1/fixtures/`), su generador y el verificador en Go, `vendor/` ni archivos sin versionar. El comando aborta si alguno aparece en la lista. En el servidor se ejecuta `composer install --no-dev` con el `composer.lock` incluido.

## Pendiente para las etapas siguientes

La etapa 3 no dejó ninguna marca `PENDIENTE etapa 3`. Lo que todavía no existe está marcado en el código con `PENDIENTE etapa N:` y el servidor lo rechaza con un mensaje claro.

| Qué falta | Etapa |
| --- | --- |
| Transferencia por autoservicio (`transfer`, `TRANSFER_LIMIT` y `transfer_available`), confirmación por la computadora anterior y alertas. Hoy las transferencias las hace solo el propietario desde el panel | 4 |
| Pantallas definitivas del panel (las del ciclo comercial son mínimas), diseño final de las constancias, `limit_req` de Nginx y despliegue en `licensing.sicrat.com` | 5 |
| Avisos a los clientes por cambios del catálogo (decisión 9 del propietario); pasarela de pagos, facturación e impuestos | Sin etapa asignada |

## Contrato

| Dónde | Qué es |
| --- | --- |
| [docs/CONTRATO_LICENCIAS_ADEL_POS.md](docs/CONTRATO_LICENCIAS_ADEL_POS.md) | El contrato V1.0. Es la fuente de verdad y no se modifica |
| [contracts/v1/license.schema.json](contracts/v1/license.schema.json) | Contenido de la licencia firmada, en JSON Schema 2020-12 |
| [contracts/v1/openapi.yaml](contracts/v1/openapi.yaml) | API en línea, en OpenAPI 3.1 |
| [contracts/v1/fixtures/](contracts/v1/fixtures/) | Archivos de prueba compartidos, con llaves públicas de prueba |
| [contracts/v1/verify/](contracts/v1/verify/) | Verificador de referencia en Go, base de la integración de AD-EL POS |

Las ambigüedades encontradas al implementarlo están registradas como propuestas para una V1.1 en [DECISIONS.md](DECISIONS.md), sección 9.

## Documentación

| Documento | Contenido |
| --- | --- |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Componentes y flujos |
| [SCHEMA.md](SCHEMA.md) | Esquema de base de datos |
| [SECURITY.md](SECURITY.md) | Modelo de seguridad y controles |
| [OPERATIONS.md](OPERATIONS.md) | Operación diaria, llaves, respaldos y recuperación |
| [TEST_PLAN.md](TEST_PLAN.md) | Plan de pruebas y cómo reproducirlas |
| [DEPLOY_UBUNTU_24_04.md](DEPLOY_UBUNTU_24_04.md) | Despliegue en Ubuntu 24.04 con Nginx y PHP-FPM |
| [DECISIONS.md](DECISIONS.md) | Decisiones del propietario y técnicas, y procedencia |
| [AVANCE.md](AVANCE.md) | Avance de las etapas 1 y 2, cifras de pruebas y verificación |
| [docs/CONTRATO_LICENCIAS_ADEL_POS.md](docs/CONTRATO_LICENCIAS_ADEL_POS.md) | Contrato de licencias V1.0 entre este servidor y AD-EL POS |
| [contracts/v1/README.md](contracts/v1/README.md) | Esquema, OpenAPI, archivos de prueba y verificador de referencia |

## Procedencia

Este repositorio deriva del servidor de licencias de AIDEX. Se copió sin secretos ni artefactos, y en la etapa 1 se renombró y limpió por completo. La fecha de la copia, el commit de origen y el SHA-256 de su `composer.lock` están en [DECISIONS.md](DECISIONS.md).
