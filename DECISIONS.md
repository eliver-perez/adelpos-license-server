# Decisiones — Servidor de licencias de AD-EL POS

Registro de las decisiones que dan forma a este repositorio. El detalle de cada cambio, con sus pruebas, está en [AVANCE.md](AVANCE.md). Las reglas técnicas del producto están en el contrato [docs/CONTRATO_LICENCIAS_ADEL_POS.md](docs/CONTRATO_LICENCIAS_ADEL_POS.md).

**Prioridad:**
1. las decisiones del propietario;
2. el contrato;
3. las especificaciones de cada etapa.

---

## 1. Procedencia

Este servidor deriva del servidor de licencias de **AIDEX**. Es la única mención de ese producto en el código y la documentación, junto con la guía de despliegue, que indica que ambos conviven en el mismo VPS.

| Dato | Valor |
| --- | --- |
| Fecha de la copia | 2026-11-16 |
| Commit de origen | `ef537cde356c101e56349bb45cba6e5769fb681c` (2026-11-03) |
| SHA-256 de `composer.lock` de origen | `7f996783b79412c73ad00d23e5354a5141517ca93da5d294370f353aab9f1730` |
| Primer commit de este repositorio | `3c0c87b` «Copia base desde AIDEX (sin secretos ni artefactos)» |

- **Qué se copió:** solo una lista de permitidos (código, plantillas, migraciones, CLI, despliegue, contrato heredado, pruebas y siete documentos).
- **Qué no se copió:** configuración local, datos (`var/`), dependencias instaladas, logos, documentación histórica ni las pruebas que dependían del cliente de origen.
- **Uso del commit raíz:** conserva la cadena de migraciones original. `tests/Integration/InitialSchemaTest.php` la lee de ahí para comprobar la equivalencia del esquema.

## 2. Contrato

| Documento | Versión | SHA-256 |
| --- | --- | --- |
| `docs/CONTRATO_LICENCIAS_ADEL_POS.md` | V1.0, copiado sin cambios | `a4c3bfd0ec428b7dc14434e294d6fbb10940c8992718b7d4db2a0703f1f554bb` |

Las **decisiones comerciales del propietario** están en la **sección 14 del contrato**:

1. licencia atada al servidor Go del restaurante;
2. sin comunicación periódica obligatoria;
3. suscripciones en meses de calendario;
4. reglas de renovación;
5. tolerancia de 5 días;
6. periodos de prueba;
7. perpetua con 0, 6 o 12 meses de actualizaciones;
8. transferencia por autoservicio;
9. planes básico, elemental y premium;
10. qué se permite al vencer;
11. tabla de estados;
12. `licensing.sicrat.com` solo para pruebas.

**En la etapa 1 no se implementan.** El motor de licencias heredado sigue activo y está marcado para reemplazarse en las etapas 2 a 4 (sección 6).

## 3. Decisiones del propietario durante la etapa 1

Todas son del 2026-11-16.

| # | Decisión |
| --- | --- |
| 1 | Versiones del VPS: **PHP 8.3.6, MySQL 8.0.46 (`8.0.46-0ubuntu0.24.04.5`), Nginx 1.24.0**, Ubuntu 24.04. El código se mantiene compatible con ellas. |
| 2 | Paquete Composer `adelpos/license-server` y paquete npm **`@adelpos/license-server`**: npm no acepta el nombre sin `@`. |
| 3 | Corregir en la Fase 1, en un commit propio, la incompatibilidad de los respaldos con MySQL 8.0 (sección 5, punto 1). |
| 4 | **Ningún nombre del producto anterior** en código, plantillas, pruebas ni configuración, y no dejarlos para fases posteriores. Por eso la marca se adelantó a la Fase 3 y el marcador de herencia no nombra el producto anterior (sección 6). |
| 5 | **Logos** de `logos-adelpos/`, usados **sin modificar sus bytes**:<br>• sin versiones verticales; donde había una, va el horizontal claro sobre fondo claro y el oscuro sobre fondo oscuro;<br>• constancias PDF con el logo claro;<br>• `icon.png` como ícono de pestaña. |
| 6 | En lo no previsto: «haz lo que creas necesario, es un proyecto nuevo». Las decisiones técnicas de la sección 5 se tomaron bajo esta indicación. |

## 4. Esquema de base de datos

- **Esquema inicial en una sola migración:** `database/migrations/001_esquema_inicial.sql`. Las migraciones `002` en adelante son de la etapa 2 (sección 10). Equivale al esquema final de la cadena heredada 001-007, sin sus datos ni sus pasos de historia: catálogo del producto anterior, migración de identidad y relleno de datos.
- **Endurecimiento:** `license_credentials.credential_encrypted` y `code_format` son `NOT NULL`. **Motivo:** no existen credenciales anteriores al formato 3-4-4-4, que exige la copia protegida del código. Es la única diferencia que admite la prueba de equivalencia.
- **Datos iniciales:**
  - el producto `adel_pos` («AD-EL POS»), **sin módulos**, que coincide con `features: {}` del contrato;
  - la fila de `security_state` y la cabeza de auditoría.

  Los planes iniciales se crean en la etapa 2 (migración `003`); su editor y sus reglas, en la etapa 3.
- **Sin cambios en el migrador.** Conserva el control por checksum, el bloqueo y la verificación de integridad. El nombre del bloqueo respeta el límite de 64 caracteres de MySQL.

## 5. Decisiones técnicas de la etapa 1

| # | Decisión | Motivo |
| --- | --- | --- |
| 1 | Los respaldos ya no pasan `--no-login-paths` a `mysql`/`mysqldump`; los ejecutan con `MYSQL_TEST_LOGIN_FILE` apuntando a una ruta inexistente y sin otras variables de entorno. | Los clientes de MySQL 8.0 no aceptan esa opción. Se comprobó con 8.0.46 que leen `~/.mylogin.cnf` aun con `--defaults-file` y que esta variable lo impide. |
| 2 | Nombres de bloqueo de MySQL de hasta 64 caracteres: el hash de migraciones va truncado a 32 hexadecimales, y el bloqueo de restauración usa un hash del nombre de la base. | Con el prefijo `adelpos-license-` el nombre anterior superaba el límite. |
| 3 | Contextos HKDF unificados bajo `adelpos-license/…/v1`. `Crypto` reserva el propósito `offline-evidence`. | Sin esa reserva, un registro con ese propósito derivaría la misma llave que la evidencia sin internet. |
| 4 | `bin/check-secrets.php` revisa el índice de git y los archivos nuevos. Acepta las semillas públicas de prueba solo si derivan exactamente del prefijo público. | Requisito de la etapa: revisión antes de cada commit. Los archivos de prueba del contrato contienen semillas públicas a propósito. |
| 5 | `bin/build-release.php` empaqueta solo archivos versionados de su lista. Aborta si aparece configuración local, `var/`, `.DS_Store` o una llave. | Antes recorría las carpetas del disco y habría incluido archivos locales; además buscaba documentos inexistentes. |
| 6 | Constancias PDF con el logo claro: su SHA-256 se verifica y se reduce **en memoria** a 812 px. Si el archivo falta, se muestra el nombre en texto. | El PNG completo agregaba unos 300 KB a cada constancia guardada (324 KB contra 74.5 KB). El archivo original no se modifica. |
| 7 | Folio de constancias con prefijo `ADELPOS-`. | El prefijo anterior era la abreviatura del producto de origen. |
| 8 | Las pruebas y los archivos de prueba del contrato usan un producto sintético: `producto_prueba`, con módulos `prueba_a` a `prueba_e` y una dependencia `prueba_e → prueba_a`. | El catálogo del producto anterior desaparece. Los nombres conservan el orden alfabético de los anteriores. |
| 9 | Se quitaron de `CatalogService` las reglas fijas del catálogo anterior. | Solo aplicaban a un producto que ya no existe. |
| 10 | Se eliminaron las pruebas de rutas de actualización de la historia anterior. Las sustituye `InitialSchemaTest`, que incluye pruebas genéricas del migrador. | Con un esquema inicial único esas rutas no existen. El detalle está en AVANCE.md, Fase 3. |
| 11 | Textos visibles en español claro: «sin internet», «en línea» y «verificación en dos pasos», y todas las operaciones de auditoría con etiqueta (`AuditLabelsTest`). | Requisito de la etapa. |
| 12 | Pruebas del panel con Playwright, usando el Chrome del equipo, instalado con `npm ci --ignore-scripts`. `tests/run-panel-tests.php` garantiza la limpieza. | Ejecutarlas sin dejar bases, usuarios ni configuraciones de prueba. |
| 13 | Se corrigió la prueba de renovación sin internet: el panel lee las fechas en `America/Monterrey` y firma en UTC. | Defecto heredado: la conversión entró después de la última actualización de la prueba. El código no cambió. |

## 6. Herencia temporal

El motor de licencias heredado sigue activo hasta las etapas 2 a 4. No se cambió su lógica. Donde la regla está fija en el código lleva el marcador:

```
HEREDADO: se reemplaza en etapa 2–4 según CONTRATO_LICENCIAS_ADEL_POS.md (<regla>)
```

Las reglas marcadas son cuatro:
- una instalación por licencia;
- tolerancia de 15 días;
- huella del equipo versión `'1'`;
- lista de límites.

La especificación pedía un marcador que nombraba el producto de origen; se cambió por la decisión 4 de la sección 3. La lista de los 20 puntos marcados está en AVANCE.md, Fase 4, y se regenera con:

```sh
git grep -n -o -E "HEREDADO: se reemplaza en etapa 2–4 según CONTRATO_LICENCIAS_ADEL_POS\.md \([^)]*\)"
```

## 7. Archivos de marca

Copiados sin modificar desde `logos-adelpos/` a `public/assets/brand/`. `ProductionHttpTest` comprueba que se sirven con estos SHA-256:

| Archivo | Uso | SHA-256 |
| --- | --- | --- |
| `adel-pos-tema-claro.png` | Fondos claros y constancias PDF | `fece00410137a618703c5921d2ce2aac5ef06227836b75505201e58125e05434` |
| `adel-pos-tema-oscuro.png` | Fondos oscuros: barra lateral y panel de acceso | `82e1a563a24ebe59fa3676fbb8cae430da4ddc263034583a0f40bbd985e29116` |
| `icon.png` | Ícono de pestaña | `241504ff0356a39044862474bf3b071d82418a20763f001ba5e2f6287804456c` |

Observación: `adel-pos-tema-oscuro.png` tiene píxeles blancos sueltos alrededor de «ADEL». Se usa tal cual.

## 8. Decisiones del propietario durante la etapa 2

Todas son del 2026-11-16, al cerrar la Fase 0. El detalle de cada consulta está en AVANCE.md, sección E2-0.5.

| # | Decisión |
| --- | --- |
| 1 | **Marcas de herencia dentro de `001_esquema_inicial.sql`:** se quitan solo esas dos líneas de comentario cuando una migración nueva reemplace su regla. El esquema no cambia. Es la única excepción a «la migración 001 no se modifica». |
| 2 | **Tres migraciones en lugar de una:** `002` en la Fase 1 (entornos de firma), `003` en la Fase 2 (modelo nuevo) y `004` en la Fase 4 (retira la huella, `license_limits` y las fechas heredadas, cuando el contenido deja de firmarlas). |
| 3 | **`product_id` fijo:** el esquema de la licencia fija `adel_pos` y el servidor no firma otro producto. Las pruebas pasan a `adel_pos`. |
| 4 | **`licensee_name`:** la razón social del cliente si está capturada; si no, el nombre comercial. |
| 5 | **HTTP de los errores de transporte:** se conservan 404, 405, 413 y 415 con `code: INVALID_REQUEST`, documentados en OpenAPI. |
| 6 | **Hora que no existe por un cambio de horario:** se usa el primer instante después del salto (lectura literal de la sección 5.1). |
| 7 | **Desactivar una activación que ya terminó:** responde 200 con su revisión de cierre, sea cual sea el motivo. `REVOKED` queda solo para `activate`. |
| 8 | Los criterios de AVANCE.md, sección E2-0.6, se aplican sin objeción. |

## 9. Propuestas V1.1 del contrato

El contrato V1.0 no se modificó. Estas aclaraciones quedan propuestas para una V1.1; la lista completa, con su texto, está en AVANCE.md, sección E2-0.5.

| N.º | Sección | Aclaración | Estado |
| --- | --- | --- | --- |
| 1 | §2, §4 | `product_id` fijo en `adel_pos` también en el servidor | Aprobada (decisión 3) |
| 2 | §4 | Origen de `licensee_name` | Aprobada (decisión 4) |
| 3 | §8.7 | HTTP de los errores de transporte | Aprobada (decisión 5) |
| 4 | §5.1 | Hora inexistente por cambio de horario | Aprobada (decisión 6) |
| 5 | §8.6 | `deactivate` sobre una activación terminada | Aprobada (decisión 7) |
| 6 a 13 | §13, §8.7, §4, §11, §5.2, §9.1, §2 | Casos de renovación en la etapa 3, uso de `INCOMPATIBLE_SCHEMA`, `request_id` nulo, nombres de los planes, motivo de la liberación forzada, edición del periodo inicial, casos sin internet y formatos menores | Aplicadas sin objeción (decisión 8) |

## 10. Decisiones técnicas de la etapa 2

### Fase 1 — Entornos de firma

| # | Decisión | Motivo |
| --- | --- | --- |
| 1 | `LICENSE_ENVIRONMENT` no tiene valor por omisión. Sin ella no arranca el servidor ni ningún comando. | Nunca se firma en un entorno que nadie eligió. |
| 2 | `LICENSE_ENVIRONMENT=production` solo se acepta con `APP_ENV=production`. La combinación inversa sí se permite: `licensing.sicrat.com` usa `APP_ENV=production` con `LICENSE_ENVIRONMENT=test`. | Las llaves de producción no deben usarse con las facilidades de desarrollo (HTTP local, cookie sin prefijo, sin revisión de permisos). No lo pedía la especificación. |
| 3 | Las semillas públicas de prueba se rechazan con `LICENSE_ENVIRONMENT=production` y también con `APP_ENV=production`. | La segunda condición era la regla de la etapa 1; así tampoco el servidor de pruebas desplegado puede firmar con llaves que cualquiera conoce. |
| 4 | Una base de datos pertenece a un solo entorno de licencias. Si la llave activa es de otro, el arranque se detiene. Por HTTP responde 503 sin explicar el motivo; el mensaje completo solo aparece en la terminal. | Evita que una licencia emitida en pruebas reciba después una firma de producción. El motivo no se muestra a quien no administra el servidor. |
| 5 | `SIGNING_KEY_DIR` sigue siendo la carpeta exacta de las llaves; no se le agrega el entorno. Por omisión es `var/keys/<entorno>`. La separación se comprueba con el registro: `generate` y `bin/preflight.php` rechazan una carpeta que contenga llaves registradas en otro entorno. | Los respaldos y la restauración dependen de esa ruta exacta (`keys/` dentro del archivo cifrado). La comprobación no necesita abrir ningún archivo privado. |
| 6 | Sin `--kid`, `generate` asigna `adelpos-test-AAAAMM-n` o `adelpos-prod-AAAAMM-n`. Se aceptan otros identificadores, salvo los que lleven la etiqueta del otro entorno. | El formato es el sugerido por la especificación; lo que se impide es una etiqueta engañosa. |
| 7 | La exportación es un documento JSON con `environment` y `keys`, y cada llave con `kid`, `environment` y `public_key`. Incluye las llaves preparadas, la activa y las anteriores, solo de propósito `license`. | AD-EL POS debe confiar en una llave antes de que se active y seguir verificando licencias firmadas con las anteriores. |
| 8 | `002_entornos_de_firma.sql` no convierte llaves de los entornos anteriores (`development`, `testing`): se detiene si existen. | No hay instalaciones con datos, y un archivo de llave declara su entorno: no se puede renombrar sin invalidarlo. |
| 9 | Sin dependencias nuevas de Composer en esta fase. | — |

### Fase 2 — Modelo de datos

| # | Decisión | Motivo |
| --- | --- | --- |
| 1 | La emisión pasa al modelo del contrato en esta fase: tipo, su condición propia (periodo, duración de la prueba o meses de actualizaciones), plan y cupos. No fija fechas. | El esquema nuevo no admite una licencia sin plan ni condiciones, y el contrato fija las fechas al activar (sección 5.2). |
| 2 | Renovación y mantenimiento se rechazan desde esta fase. Su código se eliminó; no quedó desactivado. Lo mismo la ampliación de vigencia al aprobar una solicitud sin internet. | Dependían de las fechas escritas a mano. La especificación pide que no sigan funcionando con las reglas anteriores. |
| 3 | El panel recibe ya los ajustes que la emisión nueva hace necesarios: formulario con tipo, condición y plan; detalle sin el formulario de renovación, con «Disponible próximamente»; etiqueta «Prueba»; y «Empieza al activar» donde antes había una fecha. El resto de la Fase 8 no cambia: cupos personalizados en el formulario, selector de zona horaria, detalle completo y constancias. | Un formulario que pidiera fechas que el servidor ignora, o un botón que siempre falla, sería peor que adelantar esos ajustes. Así Playwright sigue pasando en cada fase. |
| 4 | Las restricciones `CHECK` de las condiciones y del periodo comparan primero con `IS NULL` o `IS NOT NULL`. | En MySQL una restricción con resultado nulo se da por válida: sin esa comparación, una perpetua sin meses de actualizaciones pasaría. |
| 5 | Los cupos son `SMALLINT` con signo y una restricción de 0 a 999. | Así un número negativo lo rechaza la misma restricción que uno mayor que 999, y no un error de rango del tipo. |
| 6 | `plans` solo tiene identificador, nombre y los tres cupos, y la cuenta de aplicación solo lo lee. | El editor y la propagación son de la etapa 3. |
| 7 | La licencia guarda una copia del plan (`plan_id`, `plan_name`, `plan_custom`) y además una llave foránea al plan. | El contrato firma números, no paquetes: la copia no cambia si el catálogo cambia. La llave foránea impide borrar un plan en uso y permitirá encontrar las licencias que siguen un plan. |
| 8 | El periodo se guarda completo o vacío. La hora ancla lleva microsegundos. La licencia no guarda zona horaria: se usa la del cliente. | Contrato, secciones 5.1 y 5.2, y criterio 4 de AVANCE.md E2-0.6. |
| 9 | `activations.state` admite `transferred`. `license_revisions.status_reason` queda opcional hasta la Fase 4. El entorno de la revisión es llave foránea compuesta `(kid, environment)` a la llave de firma. | El contenido heredado no conoce el motivo hasta la Fase 4. La llave foránea garantiza que el entorno guardado es el de la llave que firmó. |
| 10 | La identidad se llama `server_id` en `activations`, `activation_challenges` y `license_requests`. La API sigue recibiendo `installation_id` hasta la Fase 5. | El renombre de la base no depende del protocolo. |
| 11 | La zona horaria del cliente debe estar en la lista de zonas de PHP. No se aceptan abreviaturas ni alias antiguos (`CST`, `US/Pacific`). Sin dato, se usa `America/Monterrey` al registrar y se conserva al actualizar. | Especificación, Fase 2, punto 1. |
| 12 | Las pruebas existentes siguen con el producto sintético; las nuevas usan `adel_pos`. El paso general a `adel_pos` (decisión 3 del propietario) se hace en la Fase 4. | Es la fase en la que el servidor empieza a negarse a firmar otro producto. |
| 13 | Se quitó de `001_esquema_inicial.sql` el comentario de herencia de la tolerancia (decisión 1 del propietario). El de `license_limits` se quita en la Fase 4. | La migración `003` reemplaza esa regla. |
| 14 | Sin dependencias nuevas de Composer en esta fase. | — |
