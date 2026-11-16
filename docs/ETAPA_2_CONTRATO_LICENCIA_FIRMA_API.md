# Servidor de licencias AD-EL POS — Etapa 2: contrato (licencia, firma y API)

## Objetivo

Sustituir el protocolo heredado por el **contrato de AD-EL POS V1.0** (`docs/CONTRATO_LICENCIAS_ADEL_POS.md`). Al terminar la etapa, todo lo que el servidor firma, recibe y devuelve debe coincidir con el contrato:

- entornos de firma `test` y `production`;
- contenido exacto de la licencia;
- motor de fechas para el primer periodo;
- API en línea;
- archivos `.licreq` y `.lic`;
- JSON Schema, OpenAPI, archivos de prueba y verificador en Go.

Al cerrar, la etapa de integración de AD-EL POS ya puede programarse contra ejemplos reales.

## Documentos de referencia

| Documento | Uso |
| --- | --- |
| `docs/CONTRATO_LICENCIAS_ADEL_POS.md` | **Fuente de verdad.** Las secciones citadas abajo se implementan tal cual. |
| `DECISIONS.md` y `AVANCE.md` | Estado al cerrar la etapa 1, incluidos los 20 puntos marcados como `HEREDADO` |
| Esta especificación | Alcance, orden y pruebas de la etapa |

**Prioridad:**

1. Las decisiones del propietario.
2. El contrato.
3. Esta especificación.

**El contrato no se edita.** Si al implementar aparece algo ambiguo o imposible, se registra en `AVANCE.md` como propuesta V1.1 y se consulta al propietario.

## Reglas generales

- Se aplican las mismas reglas de la etapa 1:
  - revisar secretos antes de cada commit;
  - datos sintéticos que se borran al terminar;
  - textos en español claro;
  - compatibilidad con PHP 8.3.6 y MySQL 8.0.46;
  - un commit por fase, con todas las pruebas pasando y el resultado anotado en `AVANCE.md`.
- La migración `001_esquema_inicial.sql` **no se modifica**, porque `InitialSchemaTest` la compara con la cadena original. Los cambios de esquema van en migraciones nuevas, a partir de la `002`.
  - No hay instalaciones con datos reales.
  - Por eso las migraciones nuevas pueden eliminar o rediseñar tablas heredadas sin conservar sus datos.
- Las dependencias nuevas de Composer solo se permiten si se justifican en `DECISIONS.md`, y de preferencia solo en `require-dev`.
- **No se implementa en esta etapa:**
  - renovación, conversión de prueba a pago, mantenimiento y edición o propagación de planes (etapa 3);
  - transferencias y alertas (etapa 4).

  Esas operaciones del panel heredado quedan **deshabilitadas**, con su prueba de rechazo. No se dejan funcionando con reglas viejas.

---

## Fase 0 — Verificación contra el código (sin cambios)

1. Confirmar que el repositorio está en el estado de cierre de la etapa 1:
   - commit `ec61425` o posterior;
   - árbol limpio;
   - 164 pruebas pasando;
   - el verificador Go pasa.
2. Regenerar la lista de los 20 puntos `HEREDADO` y clasificar cada uno según la fase de esta etapa que lo resuelve, o según si pasa a la etapa 3 o 4.
3. Inventariar dónde se construyen y se validan:
   - el contenido de la licencia (`RevisionPublisher`);
   - las entradas de la API (`Protocol`, `ActivationService`);
   - las solicitudes sin internet (`OfflineRequest`, `OfflineService`);
   - las llaves de firma (`SigningKeys`);
   - los límites (`license_limits`, `DocumentService`);
   - las operaciones de renovación y mantenimiento del panel.
4. Comparar el contrato contra el código actual y listar cada diferencia: campos, nombres, mensajes de firma, códigos de error, rutas y tipos.
5. Entregar al propietario un resumen con lo anterior y cualquier ambigüedad del contrato **antes de continuar**.

---

## Fase 1 — Entornos de firma (contrato §3)

1. Nueva configuración obligatoria **`LICENSE_ENVIRONMENT`**, con valor `test` o `production`.
   - Es independiente de `APP_ENV`. `licensing.sicrat.com` funciona con `APP_ENV=production` (seguridad de producción) y `LICENSE_ENVIRONMENT=test`.
   - `config/local.example.php` y `bin/configure.php` la piden de forma explícita. El ejemplo del dominio de pruebas usa `test`.
2. Cada llave de firma pertenece a un solo entorno de licencias.
   - Registro y carpeta separados: `var/keys/<entorno>/`.
   - El servidor **solo firma** con la llave activa del entorno configurado.
   - El arranque y `bin/preflight.php` fallan si la llave activa no corresponde al entorno.
   - Formato sugerido de `kid`: `adelpos-test-AAAAMM-n` y `adelpos-prod-AAAAMM-n`.
3. Las semillas públicas de prueba se rechazan cuando `LICENSE_ENVIRONMENT=production`, aunque se les cambie el nombre. Esto se conserva de la etapa 1.
4. `bin/signing-key.php` muestra el entorno en cada operación. También exporta las llaves públicas por entorno, en un formato que AD-EL POS pueda incluir en su versión: `kid`, entorno y llave pública.

**Pruebas:**
- No se puede firmar con una llave de otro entorno.
- `preflight` detecta cuando no coinciden.
- Producción rechaza las semillas de prueba.
- La exportación de llaves públicas no incluye ningún secreto.

---

## Fase 2 — Modelo de datos

Una migración nueva (`002_contrato_v1.sql`, o varias si conviene) que deje el esquema listo para el contrato.

1. **Clientes:** `time_zone` (identificador IANA), valor por omisión `America/Monterrey`. Se valida contra la lista de zonas de PHP.
2. **Planes:** tabla `plans` con las filas iniciales de la sección 11 del contrato:
   - `basico`: 1 caja, 0 comanderos, 0 KDS;
   - `elemental`: 3, 3 y 3;
   - `premium`: 5, 10 y 5.

   En esta etapa **no hay editor ni propagación** (eso es de la etapa 3). Solo se usan para llenar los cupos al emitir y para el campo `plan`.
3. **Licencias:**
   - `license_type`: `perpetual`, `subscription` o `trial`.
   - `billing_period`: `monthly`, `quarterly`, `semiannual` o `annual`, solo en suscripción.
   - `trial_duration`: `15d`, `1m` o `3m`, solo en prueba.
   - `updates_months`: 0, 6 o 12, solo en perpetua. Valor por omisión: 6.
   - Copia del plan: `plan_id`, `plan_name` y `plan_custom`.
   - Cupos: `cash_registers`, `order_takers` y `kitchen_displays`, enteros de 0 a 999. **Sustituyen** a la tabla heredada `license_limits`.
   - Campos del periodo vigente, que se llenan al activar o al aprobar sin internet: `period_starts_at`, `expires_at`, `anchor_day`, `anchor_local_time` y `updates_until`.
   - `grace_days`: lo deriva el tipo de licencia (5 en suscripción, 0 en prueba y perpetua). Se valida con restricciones `CHECK`.
4. **Activaciones:**
   - La identidad pasa a llamarse `server_id`.
   - Se **eliminan** `fingerprint_version` y `fingerprint_hash`.
   - Se guarda la `app_version` de la última operación.
5. **Revisiones:** se guarda `status_reason` (`transferred`, `deactivated` o `revoked`) en las revisiones `revoked`, y el entorno de la llave que firmó.
6. Se conserva `license_features` y la selección genérica de módulos (`features: {}` en V1).
7. Se actualizan:
   - `bin/grants.php`;
   - `SCHEMA.md`;
   - la prueba de permisos, para que cubra exactamente las tablas nuevas.

**Pruebas:**
- La migración se aplica sobre una base vacía y, repetida, no hace nada.
- Las restricciones rechazan combinaciones inválidas:
  - periodo de cobro en una perpetua;
  - meses de actualización en una suscripción;
  - un cupo negativo o mayor que 999;
  - tolerancia que no corresponde al tipo.
- La prueba de permisos pasa.

---

## Fase 3 — Motor de fechas (contrato §5.1, §5.2, §5.4 y §5.5)

Un componente de dominio puro, por ejemplo `Domain\CalendarPeriod`, sin base de datos y con reloj inyectable.

1. `addMonths(inicio, meses, zona, día_ancla, hora_ancla)`:
   - conserva el día ancla y la hora local;
   - si el día no existe en el mes, usa el último día;
   - si la hora no existe por un cambio de horario, usa el siguiente instante válido;
   - si la hora se repite, usa el primero.

   El resultado es un instante UTC.
2. `addDays(inicio, días, zona)` para la prueba de 15 días. Son días de calendario a la misma hora local.
3. **Primer periodo** (§5.2). Se calcula en el momento de la activación en línea, o de la aprobación y generación del `.lic`:
   - **Suscripción:** `expires_at` es el inicio más 1, 3, 6 o 12 meses. `updates_until` es igual a `expires_at`.
   - **Prueba:** `expires_at` es el inicio más 15 días, 1 mes o 3 meses. `updates_until` es igual a `expires_at`.
   - **Perpetua:** `expires_at` es `null`. `updates_until` es el inicio más 0, 6 o 12 meses (con 0, es igual al inicio).
   - El día y la hora ancla se toman del inicio, en la zona horaria del cliente.
4. Una licencia emitida pero no activada **no tiene fechas**. El panel muestra «Empieza al activar».
5. Los instantes se emiten con seis decimales y terminan en `Z` (§3).

**Pruebas:** unitarias con casos exactos.
- 31 de enero más 1, 2 y 3 meses, aplicando el ancla: 28 de feb, 31 de mar y 30 de abr.
- Año bisiesto y no bisiesto, y 29 de febrero más 12 meses.
- 15 días, 1 mes y 3 meses.
- Las cuatro duraciones de suscripción.
- Perpetua con 0, 6 y 12 meses.
- Hora inexistente y hora repetida, en una zona con cambio de horario (por ejemplo, `America/Tijuana`).
- Zona `America/Monterrey` cerca de la medianoche UTC, para comprobar que el día local es el correcto.

Estos casos también se publican como archivos de prueba en la Fase 7.

---

## Fase 4 — Contenido y firma de la licencia (contrato §4)

1. **Encabezado exacto:** `{"alg":"EdDSA","kid":"<kid>","typ":"adelpos-lic+jws"}`.
2. El contenido tiene **exactamente** los campos de la §4, en el orden del ejemplo del contrato. Se serializa con `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. `features` siempre se serializa como objeto (`{}`), nunca como lista.
3. **Valores:**
   - `environment`: el entorno configurado.
   - `licensee_name`: el nombre o razón social del cliente al momento de firmar.
   - `plan`: la copia de la licencia.
   - `limits`: los tres cupos.
   - `grace_days`: según el tipo.
   - `license_status`: `active` solo si la licencia comercial está vigente y la activación también. En cualquier otro caso es `revoked`, con su `status_reason`:
     - `deactivated`: la licencia se liberó, en línea o sin internet;
     - `revoked`: la revocó el propietario;
     - `transferred`: solo con transferencia forzada desde el panel, que ya existe. Su regla de autoservicio llega en la etapa 4.
4. `license_revision` es un contador **global por licencia** (§4). Cada firma nueva lo incrementa y nunca se reutiliza.
5. **Validar antes de firmar.** Si el contenido no cumple `contracts/v1/license.schema.json` (Fase 7), no se firma y la transacción se revierte. Hasta que exista el esquema, se valida con las mismas reglas en código. La validación definitiva se conecta en la Fase 7.
6. Se eliminan del contenido la huella y los límites heredados, y se resuelven los puntos `HEREDADO` correspondientes.

**Pruebas:**
- El contenido de cada tipo de licencia coincide exactamente con lo esperado, campo por campo y en el orden indicado.
- Revocación, desactivación y transferencia forzada firman `revoked` con su `status_reason` correcto.
- Las revisiones aumentan de forma global entre activaciones de la misma licencia.
- Un contenido inválido no se firma y no deja cambios en la base.

---

## Fase 5 — API en línea (contrato §8)

1. **Desafío (§8.1):**
   - Acciones: `activate`, `refresh` y `deactivate`, con `server_id`. La acción `transfer` se **rechaza con `INVALID_REQUEST`** hasta la etapa 4; queda registrada como pendiente en `AVANCE.md`.
   - Cada desafío dura 5 minutos y es de un solo uso.
2. **Prueba de posesión (§8.2):** el mensaje usa `ADELPOS-LIC-V1` y `server_id`.
3. **Activar (§8.3):**
   - En la primera activación se calcula el primer periodo (Fase 3) en la misma transacción.
   - **La misma instalación vuelve a activar** (mismo `server_id` y misma llave, con activación vigente): se devuelve su revisión vigente. No se crea otra activación ni se cambian las fechas.
   - **El mismo `server_id` con otra llave:** se trata como otra instalación.
   - **Otra instalación:** se responde `409 ACTIVATION_LIMIT` con `details.transfer_available: false`. Este valor se calcula de verdad en la etapa 4.
4. **Consultar actualización (§8.5):** devuelve la revisión vigente de la activación, aunque haya terminado. En ese caso devuelve su revisión de cierre.
5. **Desactivar (§8.6):** libera la licencia, firma la revisión de cierre `revoked`/`deactivated` y la incluye en la respuesta, junto con `server_time` y `request_id`.
6. **Errores (§8.7):**
   - Formato con `details` (un objeto vacío si no aplica).
   - Códigos y HTTP de la tabla del contrato.
   - `REQUEST_CONFLICT` sustituye al conflicto heredado cuando se reutiliza un `request_id`.
   - `TRANSFER_LIMIT` queda definido pero no se usa hasta la etapa 4.
   - `INCOMPATIBLE_SCHEMA` para versiones no soportadas.
7. **Se conservan:**
   - el tamaño máximo de 16 KiB;
   - los reintentos por `request_id`;
   - los límites de intentos;
   - la regla de no registrar códigos comerciales;
   - que una negativa comercial autenticada consume el desafío.
8. Se elimina todo rastro del protocolo heredado: `LIC-V1`, `lic+jws`, la identidad de instalación anterior y la huella. Ya no se aceptan.

**Pruebas:**
- Cada ruta, con éxito y con cada error que le corresponde.
- Reintento idéntico y `request_id` reutilizado con otro contenido.
- Desafío vencido, ya usado, de otra acción y de otra instalación.
- Prueba de posesión de otra llave.
- Reactivación desde la misma instalación.
- Una segunda instalación recibe `ACTIVATION_LIMIT`.
- Desactivar y volver a activar en otra instalación.
- Consulta de una activación terminada.
- Concurrencia: dos activaciones simultáneas de la misma licencia en instalaciones distintas producen exactamente una activación. Se reutiliza el enfoque de las pruebas de concurrencia existentes.
- Ninguna respuesta ni registro contiene el código comercial.

---

## Fase 6 — Archivos sin internet (contrato §9)

1. **`.licreq`:**
   - el sobre conserva la misma estructura;
   - el contenido incluye `server_id` y `app_version` y ya no lleva huella;
   - la firma se calcula sobre `ADELPOS-LICREQ-V1\n` + `payload_b64u`.
2. **Acciones:**
   - `activate`: el propietario asigna la licencia. Al aprobar, se calcula el primer periodo en ese momento.
   - `renew`: entrega la revisión vigente.
   - `deactivate`: entrega la revisión de cierre `deactivated` y libera la licencia.
3. **`.lic`:** es el mismo JWS de la API en línea.
4. **Se conservan:**
   - el original guardado cifrado y sin cambios;
   - que recibir la solicitud no concede nada sin la aprobación;
   - el código comercial opcional, que nunca se muestra;
   - los reintentos compartidos con la API en línea.
5. La transferencia forzada desde el panel sigue existiendo. Firma `transferred` para la activación anterior. Su relación con el autoservicio y las alertas se resuelve en la etapa 4.

**Pruebas:**
- Las tres acciones, de punta a punta: importar, revisar, aprobar y descargar.
- Firma inválida.
- Solicitud con huella o con el prefijo heredado: se rechaza.
- El periodo empieza en el momento de la aprobación, con reloj congelado.
- Solicitud repetida.
- La copia guardada no cambia.

---

## Fase 7 — Archivos del contrato y verificador de referencia (contrato §13)

Se reemplaza por completo el contenido de `contracts/v1/`:

1. **`license.schema.json`** (JSON Schema 2020-12). Contenido exacto de la §4, con:
   - todos los campos obligatorios;
   - las condiciones por tipo (por ejemplo, `expires_at: null` solo en perpetua, y `billing_period` solo en suscripción);
   - los formatos de UUID, fechas y base64url;
   - los rangos de los cupos.

   Se permiten campos adicionales, porque el cliente debe ignorarlos (§4). Se conecta a la validación previa a la firma de la Fase 4.
2. **`openapi.yaml`** (OpenAPI 3.1): las rutas implementadas en esta etapa, con sus solicitudes, respuestas, errores y ejemplos.
   - `transfer` se agrega en la etapa 4.
   - El contenido de la licencia hace referencia a `license.schema.json`.
3. **`fixtures/`**, generados con llaves públicas de prueba del entorno `test`. Se generan con `generate-fixtures.php` y se comprueban con `--check`. Contenido:
   - **Licencias válidas:**
     - perpetua con 0, 6 y 12 meses;
     - suscripción mensual y anual;
     - prueba de 15 días;
     - personalizada (`custom: true`);
     - tres revisiones de cierre: `deactivated`, `revoked` y `transferred`.
   - **Casos negativos** de la §13:
     - firma alterada y `kid` desconocido;
     - llave de prueba presentada como de producción, y `environment` que no coincide con el `kid`;
     - licencia de otra instalación y de otro producto;
     - revisión menor;
     - campo faltante, `schema_version` 2.0 y tipos incorrectos.
   - **Fechas:** los casos de la Fase 3, con entrada y resultado esperado.
   - **Estado temporal (§5.6):**
     - límites exactos de vigente, tolerancia y vencida;
     - reloj atrasado que no prolonga la licencia, usando `ahora_efectivo`.
   - **Pruebas de posesión** de las cuatro acciones (`transfer` incluida, como vector criptográfico) y **`.licreq`** de las tres acciones.
   - Los casos de renovación y cambio de plan se agregan en la etapa 3.
4. **Verificador de referencia en Go** (`contracts/v1/verify/`, módulo independiente sin dependencias externas). Además de comprobar firmas, implementa las reglas que aplicará el servidor Go del restaurante (§5.6 y §10):
   - encabezado exacto;
   - `kid` de confianza **por entorno**;
   - reglas del contenido;
   - coincidencia de `server_id` y llave;
   - revisión mayor que la última aceptada para ese `license_id`;
   - estado temporal con `ahora_efectivo`.

   Ejecuta **todos** los casos de `fixtures/`, positivos y negativos, y el resultado debe ser idéntico al esperado. Es la base que la integración de AD-EL POS podrá reutilizar. Se documenta en `contracts/v1/README.md`.

**Pruebas:**
- Cada archivo de prueba cumple o incumple el esquema según lo esperado.
- Las respuestas reales de la API (pruebas de integración) cumplen el esquema y el OpenAPI, por lo menos en su forma.
- Las rutas de `ApiKernel` coinciden exactamente con las de `openapi.yaml`.
- El verificador Go pasa sobre todos los casos.
- `generate-fixtures.php --check` da OK.

---

## Fase 8 — Panel: emisión con el modelo nuevo

Este es el ajuste mínimo para emitir y consultar con el contrato. Las pantallas definitivas se hacen en la etapa 5.

1. **Ficha del cliente:** zona horaria, con selector y `America/Monterrey` como valor por omisión.
2. **Emitir licencia:**
   - **tipo:** perpetua, suscripción o prueba;
   - **según el tipo:**
     - perpetua: meses de actualizaciones (0, 6 o 12; 6 por omisión);
     - suscripción: periodo (mensual, trimestral, semestral o anual);
     - prueba: duración (15 días, 1 mes o 3 meses);
   - **plan:** llena los tres cupos. Si se cambia algún número, la licencia queda **personalizada**, con un aviso visible.
3. **Detalle de la licencia:**
   - tipo y periodo;
   - plan y si está personalizada;
   - cupos;
   - inicio, vencimiento y fin de actualizaciones, o «Empieza al activar»;
   - estado;
   - revisiones con su `status_reason`.
4. **Renovación y mantenimiento:** se ocultan o deshabilitan con el texto «Disponible próximamente». El servidor rechaza esas operaciones aunque se intenten directamente, y una prueba lo comprueba.
5. **Constancias PDF:** muestran tipo, periodo, plan y cupos en lugar de los datos eliminados. Su diseño final es de la etapa 5.
6. Los textos están en español claro, con las fechas en la zona horaria del cliente. Las pruebas de Playwright se actualizan.

**Pruebas:**
- Emitir los tres tipos.
- Personalizar un plan.
- Validaciones del formulario.
- Rechazo de renovación y mantenimiento.
- Detalle antes y después de activar.
- Playwright: panel y dispositivos de confianza.

---

## Fase 9 — Cierre

1. **Puntos `HEREDADO`:**
   - todos los resueltos en esta etapa desaparecen;
   - los que pasan a otra etapa se vuelven a marcar con su etapa real: `PENDIENTE etapa 3: …` o `PENDIENTE etapa 4: …`;
   - la búsqueda del marcador `HEREDADO` debe dar **cero** resultados.
2. Actualizar `README.md`, `ARCHITECTURE.md`, `SCHEMA.md`, `SECURITY.md`, `OPERATIONS.md` y `TEST_PLAN.md`. Se quitan las secciones de herencia ya resueltas.
3. **`DECISIONS.md`:** decisiones técnicas de la etapa, dependencias nuevas con su justificación y cualquier propuesta V1.1 que haya quedado.
4. **`AVANCE.md`:**
   - cifras por fase;
   - pruebas eliminadas y nuevas, con su motivo;
   - pendientes para las etapas 3 a 5;
   - comandos para reproducir la verificación.
5. Generar el paquete de despliegue: incluye `contracts/v1/openapi.yaml` y `license.schema.json` como documentación, sin archivos de prueba ni llaves.

---

## Criterios de aceptación

- [ ] Todo lo que el servidor firma, recibe y devuelve coincide con el contrato V1.0. El contrato no se modificó.
- [ ] No queda ningún rastro del protocolo heredado: `LIC-V1`, `LICREQ-V1`, `lic+jws`, huella e identidad de instalación anterior.
- [ ] Hay entornos de firma `test` y `production`, separados y comprobados. Producción rechaza las llaves de prueba.
- [ ] El primer periodo se calcula con el motor de fechas, al activar en línea o al aprobar sin internet, y está cubierto por casos exactos.
- [ ] La API, los archivos sin internet, los errores y los reintentos están probados, incluida la concurrencia.
- [ ] Existen `license.schema.json`, `openapi.yaml`, los archivos de prueba y el verificador Go, y todos coinciden con el código.
- [ ] El panel emite los tres tipos con plan o personalizada. Renovación y mantenimiento están deshabilitados, con prueba.
- [ ] Cero marcadores `HEREDADO`. Los pendientes quedan marcados con su etapa.
- [ ] La suite completa, Playwright, la revisión de secretos y el paquete de despliegue pasan.

## Fuera de esta etapa

- **Etapa 3:**
  - renovación con fecha de pago y la regla de tolerancia;
  - conversión de prueba a pago;
  - compra de mantenimiento;
  - editor de planes y propagación;
  - casos de prueba de renovación y cambio de plan.
- **Etapa 4:**
  - transferencia por autoservicio (`transfer`, `TRANSFER_LIMIT` y `transfer_available`);
  - confirmación por la computadora anterior;
  - alertas.
- **Etapa 5:**
  - pantallas definitivas y constancias;
  - `limit_req` de Nginx;
  - despliegue en `licensing.sicrat.com`.
- Cualquier cambio en AD-EL POS o en AIDEX.
