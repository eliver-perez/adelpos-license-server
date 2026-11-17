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

## 6. Herencia temporal (cerrada en la etapa 2)

En la etapa 1 el motor de licencias recibido siguió activo, sin cambios de lógica. Cada regla fija en el código llevaba un comentario marcador de herencia, con la forma «(marcador): se reemplaza en etapa 2–4 según CONTRATO_LICENCIAS_ADEL_POS.md (regla)». La especificación pedía un marcador que nombraba el producto de origen; se cambió por la decisión 4 de la sección 3.

Las reglas marcadas eran cuatro: una instalación por licencia, tolerancia de 15 días, huella del equipo versión `'1'` y lista de límites. Sumaban 20 puntos, enumerados en AVANCE.md (etapa 1, Fase 4, y etapa 2, sección E2-0.2).

**La etapa 2 resolvió los 20.** La búsqueda del marcador ya no da resultados en el código, las plantillas, las migraciones, las pruebas ni los documentos de operación. Solo lo conservan AVANCE.md, que guarda el registro de las dos etapas, y la especificación de la etapa 2, que lo nombra.

Lo que queda para otras etapas se marca con `PENDIENTE etapa N:` y se lista con:

```sh
git grep -n "PENDIENTE etapa" -- src bin templates
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
| 9 | **Código legible (2026-11-16):** el código no se ofusca ni se minifica. Se escribe con saltos de línea e indentación con tabuladores. Aplica a todo el código propio, existente y nuevo. |

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

**Propuestas encontradas al implementar las fases 4 a 8 de la etapa 2.** Se aplicaron con el criterio indicado para no detener el trabajo. **El propietario las aprobó el 2026-11-16** (sección 11, decisión 1). El detalle está en AVANCE.md, sección E2-9.4.

| N.º | Sección | Qué no queda definido | Criterio aplicado |
| --- | --- | --- | --- |
| 14 | §8.6 | La respuesta de desactivar fija `"status": "deactivated"`. No dice qué valor lleva cuando la activación ya había terminado por una transferencia o una revocación. | Siempre `deactivated`. El motivo real está en `status_reason` de la licencia firmada que viaja en la misma respuesta. |
| 15 | §9.1 | No dice si una solicitud `renew` o `deactivate` se atiende cuando la licencia fue revocada. | Se atiende y entrega la revisión de cierre: es la única forma de que una computadora sin internet se entere. Amplía la propuesta n.º 12. |
| 16 | §9.1, §3 | `created_at` de una solicitud: no fija los decimales ni si admite `+00:00`. | Solo UTC terminado en `Z`, con 0 a 9 decimales. Es un dato informativo: ninguna fecha de la licencia sale de él. |
| 17 | §4 | El contenido lleva `schema_version: "1.0"` y el cliente debe aceptar cualquier versión que empiece con `1.`. No dice cuál de las dos reglas va en el esquema publicado. | El esquema fija `1.0`, que es lo que el servidor firma. El verificador de referencia acepta cualquier `1.x`. |
| 18 | §3 | El servidor emite instantes con 6 decimales y el cliente acepta de 0 a 9. No dice cuál describe el esquema. | El esquema admite de 0 a 9; el servidor se exige 6 exactos antes de firmar. |
| 19 | §8.7 | La tabla no incluye el HTTP de una petición que llega sin TLS. La decisión 5 del propietario enumeró 404, 405, 413 y 415. | Se conserva `403` con `INVALID_REQUEST`, que ya existía. En producción Nginx no deja pasar HTTP. |
| 20 | §8.3 | Define «la misma instalación» como el mismo `server_id` y la misma llave. La especificación aclara el caso del mismo `server_id` con otra llave; no el de la misma llave con otro `server_id`. | Los dos casos son otra instalación: reciben `ACTIVATION_LIMIT`. |

**Propuestas de la etapa 3.** Las dos primeras son decisiones del propietario que amplían o precisan el contrato (sección 11). Se incorporan al contrato `_v2` al cerrar la etapa 4.

| N.º | Sección | Qué cambia | Estado |
| --- | --- | --- | --- |
| 21 | §11 | **Sustituye** la frase «aplica a licencias nuevas o en su siguiente renovación». Al renovar, la licencia conserva sus números: un cambio en el catálogo **nunca reduce** las licencias existentes, ni siquiera al renovar. Los aumentos del catálogo se aplican a las existentes solo si el propietario lo elige al guardar. Cómo avisar al cliente de los cambios de catálogo se decide después. | Decisión del propietario (sección 11, decisión 9) |
| 22 | §5.3, §5.4, §5.5, §11 | Reglas comerciales: precios opcionales en MXN y registro del monto cobrado, sin IVA ni facturas; mantenimiento de una perpetua solo por 12 meses; una prueba se convierte en cualquier tipo y plan; subir de plan con bonificación (suscripción) o pagando la diferencia (perpetua); bajar de plan al vencer lo pagado, y una perpetua no baja; cambios mixtos cupo por cupo. | Decisión del propietario (sección 11, decisiones 3 a 8) |

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

### Fase 3 — Motor de fechas

| # | Decisión | Motivo |
| --- | --- | --- |
| 1 | `Domain\CalendarPeriod` no tiene reloj propio: recibe el instante de inicio. Quien lo llama lo toma del reloj de la aplicación, que es el que se sustituye en las pruebas. | Un componente puro se prueba con casos exactos. El «reloj inyectable» de la especificación es el de la aplicación. |
| 2 | La hora local se convierte a UTC con las transiciones de la zona (`DateTimeZone::getTransitions`), no con el ajuste automático de PHP. | Para una hora que no existe, PHP la adelanta lo que dura el salto (02:30 pasa a 03:30). La decisión 6 del propietario es el primer instante después del salto (03:00). |
| 3 | En una hora inexistente el resultado es el instante exacto del cambio, sin fracción de segundo. El ancla no cambia, y el periodo siguiente vuelve a su hora. | Es el primer instante válido. Lo contrario recorrería la hora ancla para siempre. |
| 4 | Los meses se suman al mes **local** del instante de partida, y el día y la hora salen del ancla. | El día que cuenta es el de la zona del cliente: las 03:30Z del 1 de marzo son todavía 28 de febrero en Monterrey. |
| 5 | En una perpetua con 0 meses, `updates_until` es el instante de inicio tal cual, sin pasar por la hora local. | Si la activación ocurre en la segunda vuelta de una hora repetida, recalcular esa hora local daría el primer instante, una hora **antes** de la activación. |
| 6 | La prueba de «15 días» suma 15 fechas de calendario a la misma hora local. | Contrato, sección 5.1. A través de un cambio de horario no son 15 × 24 horas. |
| 7 | Los casos exactos usan `America/Monterrey` y `America/Tijuana`. Las demás zonas (`America/Santiago`, `Australia/Lord_Howe`, `Europe/Madrid`) se comprueban contra una definición independiente de la regla, con los datos de zonas de la máquina. | Las reglas de Monterrey y Tijuana son estables. Los resultados de otras zonas dependen de la versión de los datos de zonas horarias instalada. |
| 8 | Límite conocido: el mes de partida se toma de la fecha local del instante de partida. Si un cambio de horario hiciera inexistente la última hora del último día de un mes y el ancla cayera ahí, el periodo siguiente se contaría desde el mes siguiente. | Ninguna zona de México ni de Estados Unidos cambia de horario a medianoche. Se registra para la etapa 3, que encadena periodos al renovar. |
| 9 | Sin dependencias nuevas de Composer en esta fase. | — |

### Fase 4 — Contenido y firma de la licencia

| # | Decisión | Motivo |
| --- | --- | --- |
| 1 | `Domain\LicenseContent` reúne los campos del contenido, su orden y sus reglas. `RevisionPublisher` arma el contenido y lo pasa por `LicenseContent::assertValid()` antes de firmar. Si no cumple, lanza una excepción que nombra el campo (nunca su valor) y la transacción se revierte. | Especificación, Fase 4, punto 5: validar en código hasta que exista el esquema JSON. En la Fase 7 una prueba comprueba que este validador y `license.schema.json` dan el mismo resultado. |
| 2 | El validador comprueba además lo que un esquema JSON no puede expresar: `updates_until = expires_at` en suscripción y prueba, vencimiento posterior al inicio del periodo y actualizaciones de la perpetua no anteriores a la activación. | Contrato, secciones 5.4 y 5.5. |
| 3 | `Application\LicenseActivator` es el único lugar que crea una activación. Lo usan la API en línea y la aprobación sin internet. Ahí se fija el primer periodo de la licencia, con un solo instante para el inicio del periodo y la fecha de activación. | Contrato, sección 5.2. Así las dos vías firman exactamente lo mismo. |
| 4 | Las fechas son de la licencia: si ya tiene periodo, una activación posterior en otra computadora no lo recalcula. | Criterio 5 de AVANCE.md, sección E2-0.6. |
| 5 | El motivo de cierre sale del estado en que terminó la activación: `deactivated`, `transferred` o `revoked`. La transferencia forzada y la liberación forzada del panel dejan la activación en `transferred`. | Especificación, Fase 4, punto 3, y propuesta V1.1 n.º 10. |
| 6 | La emisión rechaza cualquier producto que no sea `adel_pos`. El catálogo de productos se conserva. | Decisión 3 del propietario: el servidor no firma otro producto. Una licencia de otro producto no podría activarse nunca. **El propietario puede revertirlo:** el validador seguiría impidiendo la firma. |
| 7 | `features` lleva solo los módulos habilitados, como `clave → true`, y se serializa siempre como objeto. | Contrato, sección 4. |
| 8 | La migración `004` exige el motivo en toda revisión `revoked` y lo prohíbe en las `active`. | El contenido firmado ya lo incluye siempre. |
| 9 | La versión de AD-EL POS (`app_version`) se guarda al activar en línea. Las solicitudes sin internet todavía no la traen: llega en la Fase 6. | El alta de la activación se reescribió en esta fase. |
| 10 | La constancia PDF muestra el plan y los tres cupos en lugar de «instalaciones autorizadas». | La tabla `license_limits` ya no existe. El diseño final de las constancias es de la etapa 5. |
| 11 | Los archivos de `contracts/v1/` siguen siendo los heredados hasta la Fase 7. La prueba que comparaba el JWS publicado con esos archivos se sustituye por una del encabezado exacto. | Criterio 8 de AVANCE.md, sección E2-0.6. |
| 12 | Sin dependencias nuevas de Composer en esta fase. | — |

### Fase 5 — API en línea

| # | Decisión | Motivo |
| --- | --- | --- |
| 1 | `ApiProblem::STATUS` es la tabla de códigos y HTTP del contrato (sección 8.7). Solo los errores de transporte de `INVALID_REQUEST` llevan otro HTTP: 404, 405, 413 y 415, y 403 si la petición llega sin TLS fuera del desarrollo local. | Decisión 5 del propietario. El 403 sin TLS ya existía; en producción Nginx no deja pasar HTTP, así que solo protege una mala configuración. |
| 2 | `request_id` de un error: el que envió el cliente si es un UUID válido, con sus mismos bytes; `null` en el desafío, cuando falta o cuando no es válido. El servidor ya no inventa uno. Para el registro de fallas usa una referencia propia. | Propuesta V1.1 n.º 8. |
| 3 | `details` es siempre un objeto JSON: `{}` si no aplica y `{"transfer_available": false}` en `ACTIVATION_LIMIT`. | Contrato, secciones 8.3 y 8.7. |
| 4 | La misma instalación es la misma identidad (`server_id`) **y** la misma llave pública, con una activación vigente de esa licencia. Recibe su revisión vigente con `200`. La misma identidad con otra llave, o la misma llave con otra identidad, es otra instalación y recibe `ACTIVATION_LIMIT`. | Contrato, sección 8.3, y especificación, Fase 5, punto 3. |
| 5 | Desactivar una activación que ya terminó responde `200` con su revisión de cierre, sea cual sea el motivo, y con `"status": "deactivated"`. El motivo real está en `status_reason` de la licencia firmada. No se firma otra revisión. | Decisión 7 del propietario. El contrato fija el valor de `status` en la respuesta. |
| 6 | `REVOKED` solo lo responde `activate` sobre una licencia revocada, incluso a la computadora que la tenía. | Decisión 7 del propietario. |
| 7 | `server_id` se acepta solo como UUID versión 4 en minúsculas. Los demás UUID se aceptan en cualquier caja y la prueba de posesión usa los bytes recibidos. | Propuesta V1.1 n.º 13. |
| 8 | La versión de AD-EL POS (`app_version`) se guarda en la activación en cada operación autenticada: activar, volver a activar, consultar y desactivar. | Especificación, inventario de la Fase 0: se recibía y no se guardaba. |
| 9 | `transfer` no existe todavía: el desafío con esa acción recibe `INVALID_REQUEST` y la ruta `/v1/activations/transfer` responde 404. `TRANSFER_LIMIT` queda definido sin uso y `transfer_available` es siempre `false`. Los tres puntos llevan la marca `PENDIENTE etapa 4`. | Especificación, Fase 5, puntos 1, 3 y 6. |
| 10 | El fallo de arranque (`public/index.php`) responde a `/v1/…` con el formato de error del contrato, con `request_id: null`. | Sin arranque no se puede leer la petición. |
| 11 | Las rutas (`ApiKernel::ROUTES`) y el tamaño máximo (`MAX_BODY_BYTES`, 16 KiB) son constantes públicas. | La Fase 7 las compara con `openapi.yaml`. |
| 12 | Sin dependencias nuevas de Composer en esta fase. | — |

### Fase 6 — Archivos sin internet

| # | Decisión | Motivo |
| --- | --- | --- |
| 1 | El contenido de un `.licreq` tiene exactamente los campos de la sección 9.1: `server_id` y `app_version`, sin huella. `license_key` es opcional y **solo se acepta en `activate`**. | Contrato, sección 9.1: «en `activate`, opcionalmente `license_key`». Antes se aceptaba en cualquier acción. |
| 2 | `created_at` debe ser UTC terminado en `Z`, con 0 a 9 decimales. Ya no se acepta `+00:00`. Es un dato informativo: ninguna fecha de la licencia sale de él. | Contrato, sección 3. |
| 3 | `renew` entrega la revisión vigente de la activación, tal como está firmada. No firma otra ni avanza el contador. La decisión guardada apunta a esa misma revisión. | Especificación, Fase 6, punto 2. Si los derechos cambiaron, la revisión vigente ya es la nueva. |
| 4 | `renew` y `deactivate` se atienden aunque la activación haya terminado o la licencia esté revocada: la computadora recibe su revisión de cierre. `activate` sigue exigiendo una licencia vigente. | Propuesta V1.1 n.º 12. Una computadora sin internet solo se entera de su revocación o de su transferencia por esta vía. |
| 5 | `activate` de la misma instalación (misma identidad y misma llave, con activación vigente) entrega su revisión vigente sin crear otra activación. No es una transferencia y no puede forzarse contra sí misma. La misma identidad con otra llave es otra instalación y necesita la transferencia del propietario. | Propuesta V1.1 n.º 12, con la misma definición de «misma instalación» que la API en línea. |
| 6 | La versión de AD-EL POS que firmó la solicitud se guarda en la activación, igual que en la API en línea. | Decisión 8 de la Fase 5. |
| 7 | El panel muestra `server_id` y la versión de AD-EL POS en la revisión de una solicitud, y avisa cuando la computadora que pide activar ya tiene la licencia. | La huella ya no existe. |
| 8 | La transferencia forzada y la liberación forzada del panel siguen como estaban, con su confirmación de identidad. Firman `transferred` desde la Fase 4. | Especificación, Fase 6, punto 5. Su relación con el autoservicio es de la etapa 4. |
| 9 | Sin dependencias nuevas de Composer en esta fase. | — |

### Fase 7 — Archivos del contrato y verificador de referencia

| # | Decisión | Motivo |
| --- | --- | --- |
| 1 | **Dos dependencias nuevas, solo de desarrollo** (`require-dev`): `opis/json-schema` 2.6 (Apache-2.0), que valida JSON Schema 2020-12, y `symfony/yaml` 7.4 (MIT), que lee `openapi.yaml`. Traen `opis/string`, `opis/uri` y `symfony/polyfill-ctype`. Ninguna va al servidor: el paquete de despliegue instala con `composer install --no-dev`. | Las pruebas deben comparar el esquema y el OpenAPI publicados con el código y con las respuestas reales. Escribir a mano un validador de JSON Schema y un lector de YAML sería más código que mantener y menos confiable. Las dos son compatibles con PHP 8.3 y no tenían avisos de seguridad al instalarlas (2026-11-16). |
| 2 | El servidor sigue validando con su propio código (`Domain\LicenseContent`) antes de firmar; no carga el esquema en producción. La «conexión» con `license.schema.json` es una prueba: cada regla del validador está en el esquema, salvo 11 casos que un esquema no puede expresar, y los dos coinciden en todos los archivos de prueba. | Criterio 2 de AVANCE.md, sección E2-0.6. |
| 3 | Lo que el validador exige y el esquema no: seis decimales exactos en los instantes (el esquema admite de 0 a 9, que es lo que debe aceptar el cliente), fechas de calendario reales, `updates_until` y `expires_at` coherentes entre sí, el orden de los campos, y la diferencia entre `7` y `7.0`, que en JSON no existe. | El esquema describe lo que AD-EL POS debe aceptar; el validador, lo que el servidor se permite firmar. |
| 4 | El esquema fija `schema_version` en `1.0` y `product_id` en `adel_pos`, y permite campos adicionales. | Contrato, sección 4, y decisión 3 del propietario. El verificador de referencia acepta cualquier versión que empiece con `1.`, como pide el contrato al cliente. |
| 5 | Las llaves de los archivos de prueba se llaman `adelpos-test-fixture-a`, `adelpos-test-fixture-b`, `adelpos-prod-fixture-a`, `installation-fixture-a` e `installation-fixture-b`. Su semilla es pública: `sha256("ADELPOS LICENSE PUBLIC TEST ONLY V1 " + nombre)`. `SigningKeys::FIXTURE_SEEDS` las rechaza en producción y una prueba comprueba que la lista es la misma. | Contrato, sección 3. `adelpos-prod-fixture-a` solo hace de llave de producción en los casos negativos. |
| 6 | El generador de archivos de prueba no usa el código del servidor: arma cada contenido a mano, con resultados esperados escritos como constantes. Las pruebas comparan después el servidor contra ellos. | Si el generador usara el servidor, un error del servidor se copiaría a los archivos y nadie lo vería. |
| 7 | Los casos de fechas los comprueba la suite de PHP, no el verificador en Go. | El contrato dice que las fechas las calcula únicamente el servidor de licencias. |
| 8 | El verificador de referencia es un módulo de Go independiente, solo con la biblioteca estándar, dividido en `license.go`, `state.go`, `protocol.go` y `main.go`. Devuelve el motivo del rechazo como un texto estable (`unknown_kid`, `stale_revision`…). | Especificación, Fase 7, punto 4. Es la base que la integración de AD-EL POS puede copiar. |
| 9 | Además de los casos que pide la especificación, hay casos de: encabezado con el tipo anterior o en otro orden, contenido alterado, la misma identidad con otra llave, un cupo vacío, una hora con desfase, una revocada sin motivo y una licencia firmada con una segunda llave de confianza. | Son los errores más probables al integrar. |
| 10 | Las respuestas reales de la API se validan contra `openapi.yaml` en `HttpApiTest`, en cada petición: el cuerpo cumple el esquema de su ruta y de su código HTTP, la petición aceptada cumple el esquema de petición, y la respuesta trae exactamente los campos obligatorios. | Especificación, Fase 7: «las respuestas reales cumplen el esquema y el OpenAPI». |
| 11 | La prueba del verificador en Go se omite si Go no está instalado. `composer test:contract` sigue comprobando los archivos de prueba. | El servidor de producción no necesita Go. |

### Fase 8 — Panel: emisión con el modelo nuevo

| # | Decisión | Motivo |
| --- | --- | --- |
| 1 | Las fechas de una licencia (inicio del periodo, vencimiento y fin de actualizaciones) se muestran en la **zona horaria de su cliente**, con el nombre de la zona a la vista. Las fechas de eventos del panel (auditoría, activaciones, revisiones, historial) siguen en la zona del panel, `America/Monterrey`, también con su nombre. | Especificación, Fase 8, punto 6. Toda fecha lleva su zona escrita, así que no hay ambigüedad aunque en una pantalla convivan las dos. |
| 2 | La zona horaria del cliente se elige de una lista con las zonas IANA que conoce el servidor. Por omisión, `America/Monterrey`. Cambiarla no modifica fechas ya firmadas. | Contrato, sección 5.1. |
| 3 | En el formulario de emisión, el plan llena los tres cupos. Si se cambia un número aparece de inmediato el aviso «Licencia personalizada», y desaparece si se vuelve al valor del plan. El servidor es quien decide: compara lo recibido con el plan. | Especificación, Fase 8, punto 2. El aviso es una ayuda; la regla está en `LicenseService`. |
| 4 | El detalle de la licencia muestra tipo con su condición, plan y si está personalizada, los tres cupos, «1 computadora principal a la vez», inicio, vencimiento, tolerancia, fin de actualizaciones y la zona del cliente. Una licencia sin activar muestra «Empieza al activar». | Especificación, Fase 8, punto 3. Sustituye a «Instalaciones: 1 autorizada», la última marca de herencia. |
| 5 | Cada revisión firmada muestra su estado y, si es de cierre, su motivo: «licencia liberada», «transferida a otra computadora» o «revocada por el propietario». | Especificación, Fase 8, punto 3. |
| 6 | Las constancias PDF guardan en su instantánea las condiciones, el periodo y la zona del cliente, y muestran modalidad, plan, cupos, inicio, vencimiento y fin de actualizaciones. | Especificación, Fase 8, punto 5. Su diseño final es de la etapa 5. |
| 7 | Renovación y mantenimiento siguen como quedaron en la Fase 2: la tarjeta «Disponible próximamente» y el rechazo del servidor, con sus pruebas. | Especificación, Fase 8, punto 4. |
| 8 | Sin dependencias nuevas. | — |

### Repaso de formato

| # | Decisión | Motivo |
| --- | --- | --- |
| 1 | El formato lo aplica Prettier 3.10.1 con `@prettier/plugin-php` 0.26.0 (`.prettierrc.json`: tabuladores, 120 columnas). Son dependencias de desarrollo de npm, con versión exacta. | Un formateador da el mismo resultado siempre y evita discutir el estilo archivo por archivo. npm ya se usaba para Playwright; no se añade nada a Composer ni al paquete de despliegue. |
| 2 | Las plantillas (`templates/`) no pasan por Prettier: se indentaron con una herramienta propia y las líneas densas se reescribieron a mano. | El complemento de PHP no formatea bien archivos que mezclan HTML y PHP. |
| 3 | El límite que comprueba la prueba de estilo es de 140 columnas, con el tabulador contado como 4. Prettier apunta a 120. | Prettier no parte cadenas ni expresiones regulares, y el HTML anidado ocupa columnas con la indentación. |
| 4 | `001_esquema_inicial.sql` queda fuera del repaso. | La especificación de la etapa 2 prohíbe modificarlo. Queda como consulta al propietario en AVANCE.md, sección E2-F.2. **El propietario lo autorizó al empezar la etapa 3 (decisión D) y se reformateó en su Fase 0.** |
| 5 | El SQL dentro del código se parte por cláusula, y cada `CHECK` de las migraciones nuevas lleva una condición por línea. | Es la parte del código que más se lee al revisar una regla. |

## 11. Decisiones del propietario para la etapa 3

Todas son del 2026-11-16 y están al inicio de la especificación de la etapa (`docs/ETAPA_3_PLANES_Y_CICLO_COMERCIAL.md`).

| # | Decisión |
| --- | --- |
| 1 | **Aprobadas** las propuestas V1.1 n.º 14 a 20 (sección 9) y las decisiones A, B y C de la etapa 2 (AVANCE.md, sección E2-9.4): la emisión solo de `adel_pos`, `license_key` solo en `activate` y las dos dependencias de desarrollo. |
| 2 | **Autorizado** reformatear `001_esquema_inicial.sql` con la regla de código legible (decisión D). El esquema que produce no cambia. |
| 3 | **Precios:** se capturan en el panel, en el catálogo de planes; son opcionales, y si falta uno el panel pide el monto a mano; moneda única **MXN**; se registra el monto tal como se cobra, sin calcular IVA ni emitir facturas. |
| 4 | **Mantenimiento de una perpetua:** solo por un año, 12 meses. |
| 5 | **Una prueba se convierte en cualquier tipo:** suscripción con cualquier periodo o perpetua, con cualquier plan. |
| 6 | **Subir de plan** o aumentar cupos. Suscripción: el periodo nuevo empieza en la fecha de pago y se descuenta una bonificación por los días que faltaban del periodo pagado. Perpetua: se paga la diferencia entre los precios perpetuos de los dos planes y el cambio aplica de inmediato. |
| 7 | **Bajar de plan** o reducir cupos: el plan nuevo empieza cuando vence el tiempo pagado. Una perpetua no puede bajar de plan. |
| 8 | **Cambio que sube unos cupos y baja otros:** los aumentos como en el punto 6 y las reducciones como en el punto 7, cupo por cupo. |
| 9 | **Al renovar, la licencia conserva sus números.** Un cambio en el catálogo nunca reduce las licencias existentes. Los aumentos se aplican a las existentes si el propietario lo elige al guardar. Sustituye una frase del contrato §11: propuesta V1.1 n.º 21. |
| 10 | Las decisiones 3 a 8 se registran como propuesta V1.1 n.º 22, para incorporarlas al contrato `_v2`. |

