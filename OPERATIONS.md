# Operación del servidor de licencias de AD-EL POS

Cómo configurar, operar, respaldar y verificar el servidor. La instalación paso a paso en Ubuntu 24.04 está en [DEPLOY_UBUNTU_24_04.md](DEPLOY_UBUNTU_24_04.md); las decisiones, en [DECISIONS.md](DECISIONS.md); el contrato con AD-EL POS, en [docs/CONTRATO_LICENCIAS_ADEL_POS.md](docs/CONTRATO_LICENCIAS_ADEL_POS.md).

> **Estado: etapa 5 cerrada, versión 1.0.0.** El servidor cumple el contrato V1.0 completo, administra el ciclo comercial, las transferencias y las alertas, y tiene su panel definitivo ([docs/PANEL.md](docs/PANEL.md)). Lo que queda para después está en [ARCHITECTURE.md](ARCHITECTURE.md), sección 14.

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
| `LICENSE_ENVIRONMENT` | **Obligatoria.** `test` o `production`: el entorno de firma de las licencias. No depende de `APP_ENV`. `licensing.sicrat.com` usa `test` con `APP_ENV=production`. `production` solo se permite con `APP_ENV=production` |
| `APP_URL` | Origen del sitio, sin subdirectorio. Pruebas: `https://licensing.sicrat.com` |
| `DB_DSN`, `DB_USER`, `DB_PASSWORD` | Conexión de la cuenta de aplicación (`adelpos_license_app`, permisos restringidos) |
| `MFA_KEY`, `CREDENTIAL_KEY`, `AUDIT_KEY`, `SESSION_KEY`, `OPERATION_KEY` | Cinco claves independientes de 32 bytes. **Se conservan en toda actualización y restauración** |
| `ADMIN_NETWORKS` | Redes CIDR desde las que se permite el panel, separadas por comas. Vacío: cualquier red (sigue exigiendo contraseña y verificación en dos pasos) |
| `SIGNING_KEY_DIR` | Directorio absoluto de las llaves privadas de firma, fuera de `public/`. Por omisión, `var/keys/<LICENSE_ENVIRONMENT>`. Cada entorno de licencias usa su propia carpeta |

Crear una configuración nueva:

```sh
php bin/configure.php --env=production --license-environment=test --url=https://licensing.sicrat.com \
  --dsn='mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=adelpos_license;charset=utf8mb4' \
  --user=adelpos_license_app --output=/srv/adelpos-license/shared/config.php \
  --signing-dir=/srv/adelpos-license/shared/keys/test
```

Pide la contraseña de la base sin mostrarla, genera las cinco claves y escribe el archivo con permisos 0600. Nunca reemplaza un archivo existente. `--license-environment` se indica siempre; el comando no asume ninguno.

Una base de datos pertenece a un solo entorno de licencias. Para pasar de pruebas a producción se instala otro servidor, con otra base y otras llaves; no se cambia `LICENSE_ENVIRONMENT` en una instalación que ya firma.

## 3. Base de datos

| Paso | Comando |
| --- | --- |
| Aplicar migraciones (cuenta de migración, contraseña sin eco) | `php bin/migrate.php --user=adelpos_license_migrate` |
| Generar los GRANT de la cuenta de aplicación | `php bin/grants.php --database=adelpos_license --user=adelpos_license_app` |

- El esquema se crea con las migraciones de `database/migrations/`, en orden. `001_esquema_inicial.sql` solo inserta el producto `adel_pos` («AD-EL POS»), sin módulos; `002_entornos_de_firma.sql` deja los entornos de firma `test` y `production`. Las tablas y columnas están en [SCHEMA.md](SCHEMA.md).
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
php bin/signing-key.php --action=generate --user=adelpos_license_migrate
php bin/signing-key.php --action=list
php bin/signing-key.php --action=export > llaves-publicas.json
php bin/signing-key.php --action=activate --kid=adelpos-test-202611-1 --reason="Pública distribuida" --trust-confirmed --user=adelpos_license_migrate
php bin/preflight.php --require-signer
```

Cada comando muestra primero el entorno de licencias en el que opera (`Entorno de licencias: test`).

- **Entornos.** Cada llave pertenece a un solo entorno de licencias (`test` o `production`), el de `LICENSE_ENVIRONMENT` al generarla. El registro (`signing_keys`, `signing_scopes`) y la carpeta están separados por entorno. El servidor solo firma con la llave activa de su entorno.
- `generate` crea una llave privada aleatoria en `SIGNING_KEY_DIR` (0600) y muestra solo la pública. Queda en estado `staged`. Sin `--kid` asigna el siguiente identificador del entorno: `adelpos-test-AAAAMM-n` o `adelpos-prod-AAAAMM-n`. Rechaza un `kid` con la etiqueta del otro entorno y una carpeta que ya tenga llaves del otro entorno.
- `export` imprime las llaves públicas de licencias del entorno, en el formato que AD-EL POS incluye en su versión. Contiene las preparadas, la activa y las anteriores, y nada privado:

  ```json
  {
      "environment": "test",
      "keys": [
          { "kid": "adelpos-test-202611-1", "environment": "test", "public_key": "<32 bytes en base64url>" }
      ]
  }
  ```
- `activate` exige `--trust-confirmed`: confirma que la pública ya se distribuyó en AD-EL POS por un canal autenticado. Las llaves anteriores quedan para verificación (`verify_only`) y no se borran.
- **Rotación:** generar otro `kid`, distribuir la nueva pública junto con las anteriores y solo después activarla.
- Las llaves derivadas de las semillas públicas de prueba (`ADELPOS LICENSE PUBLIC TEST ONLY V1 …`) se rechazan con `LICENSE_ENVIRONMENT=production` y en cualquier servidor con `APP_ENV=production`.
- **Si la llave activa es de otro entorno,** el servidor no arranca: el panel y la API responden 503 y los comandos se detienen con un mensaje que nombra la llave y los dos entornos. Se corrige `LICENSE_ENVIRONMENT` o se apunta a la base de ese entorno.

## 6. El panel en el día a día

El panel sigue [docs/PANEL.md](docs/PANEL.md). La navegación tiene tres grupos: **Operación** (Resumen, Licencias, Clientes, Alertas y Sin internet), **Catálogo** (Planes y Productos) y **Control** (Auditoría, Administradores, solo para el superadministrador, y Mi seguridad). Quien puede escribir tiene el botón **Emitir licencia** en la barra superior de todas las pantallas.

| Tarea | Dónde | Qué hacer |
| --- | --- | --- |
| Ver qué necesita atención | **Resumen** | Licencias vigentes por tipo, en tolerancia, vencidas en los últimos 30 días y alertas sin revisar; **Por vencer** en 7, 15 o 30 días de calendario, en la zona de cada cliente; **Cobros del periodo** (mes en curso y anterior, informativos, en MXN); alertas y actividad recientes. Cada número abre su lista ya filtrada |
| Buscar una licencia | **Licencias** | Busca por nombre o razón social del cliente, referencia comercial, identificador de la licencia o `server_id` de cualquier computadora que la haya tenido. Filtra por tipo, estado, plan, personalizadas o con transferencia pendiente, y ordena; 20 por página |
| Cobrar una renovación | Detalle de la licencia → **Renovar** | Revisa la vista previa (fechas nuevas y monto sugerido) y confirma con el monto cobrado |
| Atender una solicitud sin internet | **Sin internet** (con el número de pendientes en el menú) | Importa el `.licreq`, revisa la computadora y la licencia, aprueba o rechaza y descarga el `.lic` |
| Atender una alerta | **Alertas** o el botón de notificaciones | Sigue la tabla de alertas de esta sección y después «Marcar como revisada» |
| Consultar o corregir un cliente | **Clientes** → el cliente | Sus datos, sus licencias con su estado y sus pagos. «Editar» cambia los datos y la zona horaria |
| Entregar una constancia | Detalle → **Constancias** | De licenciamiento, o de entrega para activación (con nueva autenticación). No llevan montos |
| Revisar quién hizo qué | **Auditoría** | Busca por la operación, en español (por ejemplo, «renovada»), o por el identificador del objeto; cada evento dice quién y cuándo |

- **El detalle de una licencia** tiene siete secciones: Resumen, Pagos, Computadoras y transferencias, Revisiones firmadas, Sin internet, Constancias e Historial. Su barra de acciones muestra solo lo que aplica a esa licencia y a tu rol: Renovar, Convertir, Cambiar de plan, Mantenimiento, Editar periodo inicial, Descargar .lic vigente, Constancias, Revelar código, Reemplazar clave y Revocar.
- **Estados**, con las reglas que aplica AD-EL POS (contrato §5.6) y la hora del servidor: **Sin activar**, **Vigente** (o **Prueba vigente**), **En tolerancia**, **Vencida** y **Revocada**. Cada uno tiene su color y su texto; nunca depende solo del color.
- **Antes de cada operación comercial** el panel muestra una vista previa con lo que va a cambiar; las irreversibles piden marcar «Entiendo» y, las sensibles, tu contraseña y un código.
- **Si un formulario no se puede completar,** vuelve a su pantalla con lo que escribiste (salvo contraseñas y códigos) y un mensaje que nombra el campo. Si la licencia cambió mientras tanto, la pantalla lo avisa: revisa los datos nuevos y repite la operación.
- **En el teléfono** el panel funciona igual: el menú se abre con el botón de la barra superior y las tablas se leen por filas.

### Licencias y operación sin internet
Todo se hace en el panel:
- **Licencias:** emitir (muestra el código 3-4-4-4 una sola vez), cambiar módulos, reemplazar la clave, revocar y generar constancias en PDF.
  - **Al emitir** se elige el tipo y su condición: perpetua con 0, 6 o 12 meses de actualizaciones; suscripción mensual, trimestral, semestral o anual; o prueba de 15 días, 1 mes o 3 meses. También el plan, que llena los cupos de cajas, comanderos y pantallas de cocina (KDS). Si se cambia algún cupo, el panel avisa que la licencia queda **personalizada** y deja de seguir a su plan.
  - **Solo se emiten licencias de AD-EL POS** (`adel_pos`): es el único producto que el contrato permite firmar.
  - **Las fechas empiezan al activar.** Una licencia emitida no tiene vencimiento asignado; el panel muestra «Empieza al activar». El primer periodo empieza cuando el servidor confirma la primera activación en línea, o cuando se aprueba la primera solicitud sin internet. Si después se activa en otra computadora, conserva sus fechas.
  - **El detalle de la licencia** muestra tipo, plan, cupos, inicio, vencimiento y fin de actualizaciones, en la zona horaria del cliente, y el motivo de cierre de cada revisión firmada: licencia liberada, transferida a otra computadora o revocada por el propietario.
  - **Una computadora principal a la vez.** Para pasarla a otra computadora, la primera libera la licencia, AD-EL POS la transfiere por autoservicio, o el propietario la transfiere desde el panel.
  - **Transferencias** (etapa 4), en la sección «Computadoras y transferencias» del detalle: el historial (vía, origen y destino, fecha, estado y forma de confirmación) y la transferencia por autoservicio pendiente, si la hay.
  - **Al emitir se registra el pago de alta,** en MXN y tal como se cobró. El panel sugiere el precio del catálogo del plan y del plazo; si el monto es otro, pide el motivo. Una licencia personalizada, o un plan sin precio, pide el monto a mano. Una prueba vale 0.
- **Ciclo comercial** (etapa 3), desde el detalle de la licencia. Cada operación muestra primero una revisión con las fechas nuevas y el monto sugerido, y después se confirma con el monto cobrado:
  - **Renovar** una suscripción activada: si paga antes de que termine la tolerancia, el periodo nuevo empieza en el vencimiento; si no, en la fecha de pago. Conserva sus cupos y puede cambiar la duración.
  - **Convertir** una prueba en suscripción o perpetua, con cualquier plan: desde la fecha de pago, sin bonificación.
  - **Cambiar de plan o de cupos:** subir aplica de inmediato con un periodo nuevo desde la fecha de pago y una bonificación por lo que faltaba de todos los periodos ya pagados, también uno renovado por adelantado; bajar queda programado para el vencimiento y se puede cancelar en «Cambio programado». Una perpetua solo sube, pagando la diferencia de los precios perpetuos. En una prueba es libre.
  - **Comprar mantenimiento** de una perpetua activada: 12 meses desde la fecha posterior entre el pago y el fin de las actualizaciones.
  - **Editar el periodo inicial** de una licencia sin activar: tipo, condición, plan y cupos. No registra un pago.
  - Cada pago queda en la tabla **Pagos** del detalle, que no se puede editar ni borrar.
- **Planes** (solo el superadministrador): crear, editar nombre, cupos y precios en MXN, y archivar. Al guardar cupos nuevos se elige si aplican solo a licencias nuevas o también a las existentes que siguen el plan; en las existentes solo suben, y el panel muestra cuántas licencias se actualizaron y cuántas revisiones se firmaron. Un plan archivado ya no se ofrece al emitir.
- **Clientes:** cada cliente tiene una zona horaria, que se elige de una lista en su ficha (por omisión `America/Monterrey`). Con ella se calculan y se muestran las fechas de sus licencias. Cambiarla no modifica fechas ya firmadas.
- **Sin internet:**
  - importar un `.licreq` (máximo 64 KiB);
  - revisar la identidad del equipo;
  - elegir la licencia, aprobar o rechazar;
  - descargar el `.lic`.

  Importar no concede nada: solo la aprobación entrega un archivo.
  - **Activar:** el propietario elige la licencia. Si esa misma computadora ya la tiene activa, recibe su archivo vigente.
  - **Renovar (`renew`):** entrega el archivo vigente de la licencia, tal como está firmado (sirve después de cualquier cambio), o registra ahí mismo la renovación con su pago y entrega la revisión nueva, si la computadora sigue activa.
  - **Desactivar:** libera la licencia y entrega la revisión de cierre.
  - Una computadora cuya activación ya terminó, o cuya licencia fue revocada, recibe su revisión de cierre: así se entera sin internet.
- **Recuperación de equipo averiado:** retira la licencia de esa computadora con autorización excepcional; queda firmada como transferida. La firma no demuestra que se hayan borrado copias del equipo anterior.

La API en línea solo atiende `POST` a `/v1/activations/challenge`, `/v1/activations`, `/v1/activations/transfer`, `/v1/activations/refresh` y `/v1/activations/deactivate`, con JSON de hasta 16 KiB. Su especificación es `contracts/v1/openapi.yaml`.

### Transferencias por autoservicio y alertas

AD-EL POS puede mover la licencia a otra computadora sin soporte. Mientras la computadora anterior no consulte, la transferencia queda **pendiente de confirmar** y otra transferencia por autoservicio recibe «Comunícate con soporte». La anterior la confirma sola en cuanto tenga internet y consulte, o con una solicitud sin internet de `renew` o `deactivate`.

**Las alertas** aparecen en el botón de notificaciones de la barra superior (con el número sin revisar), en el resumen y en «Alertas». No se envían correos.

| Alerta | Qué pasó | Cómo atenderla |
| --- | --- | --- |
| Transferencia por autoservicio bloqueada | Otra computadora intentó transferir mientras había una transferencia pendiente. El detalle dice qué computadora lo intentó, su versión de AD-EL POS, cuántas veces y desde cuándo está pendiente | Llamar al cliente. Si cambió de computadora porque la anterior se dañó o ya no existe, **liberar el contador** (abajo) y pedirle que vuelva a transferir. Si nadie reconoce el intento, no liberar nada: la anterior sigue esperando su confirmación |
| Transferencias frecuentes | La licencia suma dos o más transferencias en los 12 meses anteriores (en la zona del cliente), de autoservicio o del propietario | Revisar el historial de transferencias del detalle. Varias computadoras en poco tiempo pueden ser un cambio de equipo legítimo o una licencia que circula entre restaurantes |

Después de atenderla, **«Marcar como revisada»** (operador o superadministrador; la cuenta de consulta solo las ve). Queda quién y cuándo. Si la situación se repite, se abre otra alerta.

**Liberar el contador de autoservicio** (solo el superadministrador, en «Computadoras y transferencias» del detalle, con motivo y confirmando la identidad): úsalo solo cuando la computadora anterior **nunca** va a volver a conectarse. No retira la licencia de la anterior: si conserva una copia sin internet, sigue funcionando hasta su vencimiento, o para siempre si es perpetua. Si regresa y consulta, recibe su cierre.

## 7. Mantenimiento diario

`deploy/adelpos-license-maintenance.sh` se instala como `/usr/local/sbin/adelpos-license-maintenance` y lo ejecuta el servicio y temporizador `adelpos-license-maintenance` (03:15 UTC, con `flock`). Hace cuatro cosas:

1. `bin/cleanup.php`: borra sesiones, contadores de intentos y desafíos vencidos. Conserva la historia comercial y la idempotencia.
2. `bin/apply-scheduled-changes.php`: aplica los cambios de plan programados cuyo instante ya llegó (reducciones al vencer lo pagado). Firma una revisión nueva para cada activación vigente y deja el cambio en el historial y en la auditoría, atribuido al sistema. Cada cambio se aplica una sola vez, aunque dos ejecuciones coincidan; se puede ejecutar a mano en cualquier momento.
3. `bin/audit-verify.php`: verifica la cadena de auditoría y escribe un anclaje (`.anchor.json`).
4. `bin/backup.php --action=create`: crea el respaldo cifrado (`.adelposbackup`) y su recibo (`.receipt.json`).

La tarea no borra respaldos antiguos. La retención y la copia externa las define el operador ([DEPLOY_UBUNTU_24_04.md](DEPLOY_UBUNTU_24_04.md), paso 12). `bin/verify-deployment.php` revisa que el temporizador esté habilitado y que la última ejecución haya terminado bien.

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

  También muestra el entorno de licencias y se detiene si la llave activa es de otro entorno o si la carpeta de llaves contiene llaves de otro entorno.

  Con `--require-signer` verifica además la llave de firma activa del entorno.
- **Verificación del despliegue.** `sudo env ADELPOS_LICENSE_CONFIG=… php8.3 bin/verify-deployment.php` comprueba, sin modificar nada, PHP, permisos, la cuenta de MySQL con sus permisos exactos, migraciones, llave activa, tarea diaria y la respuesta HTTPS del dominio (certificado, redirección, cabeceras, ocultos y API). Cada Advertencia o Error dice qué hacer; termina con código 1 si hay errores. Se ejecuta al instalar, después de cada actualización y cuando algo falle.

## 10. Paquete de despliegue

`licensing.sicrat.com` se despliega clonando el repositorio en una etiqueta ([DEPLOY_UBUNTU_24_04.md](DEPLOY_UBUNTU_24_04.md)). El paquete sirve para archivar una versión con el SHA-256 de cada archivo, o para instalarla donde no se quiera un clon.

```sh
php bin/build-release.php --output=/ruta/existente/adelpos-license.tar.gz
```

- **Qué incluye:**
  - solo los archivos versionados en git de `bin/`, `src/`, `public/`, `templates/`, `database/` y `deploy/`;
  - `composer.json` y `composer.lock`;
  - `config/local.example.php`;
  - la documentación, incluidos `docs/PANEL.md` y `docs/INTEGRACION_ADEL_POS.md`;
  - del contrato, `contracts/v1/license.schema.json` y `contracts/v1/openapi.yaml`, como documentación.
- **Qué deja fuera:** pruebas, los archivos de prueba del contrato con sus llaves públicas de prueba, su generador y el verificador en Go, `vendor/`, `var/`, configuración local y archivos sin versionar.
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
  - `bin/verify-deployment.php`, que resume casi todo lo de esta lista;
  - temporizadores (`adelpos-license-maintenance.timer`, `certbot.timer`);
  - espacio libre;
  - antigüedad de respaldos y anclajes externos;
  - caducidad TLS;
  - sincronización del reloj (NTP);
  - errores 5xx y respuestas 429 de Nginx (`limiting requests` en su registro de errores).
- **Sin internet:** un equipo desconectado conserva sus derechos según su última licencia firmada; ninguna revocación o transferencia lo alcanza al instante.
