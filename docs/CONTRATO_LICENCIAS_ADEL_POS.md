# Contrato de licencias AD-EL POS — V1.0

Documento compartido entre dos proyectos:

- **Servidor de licencias de AD-EL POS** (PHP/MySQL, derivado del servidor de licencias de AIDEX).
- **AD-EL POS**: el servidor Go del restaurante y su etapa de integración de licencias.

Ningún proyecto puede cambiar por su cuenta campos, significados, firmas, rutas ni reglas de fechas de este contrato. Si surge una incompatibilidad, se registra una propuesta de V1.1 y se conserva V1.0 hasta que el propietario la apruebe.

Las decisiones comerciales vienen del propietario (sección 14). Este documento solo las traduce a reglas técnicas.

---

## 1. Responsabilidades

| Componente | Responsabilidad |
| --- | --- |
| Servidor de licencias | Emitir, firmar, renovar, transferir, revocar y auditar licencias. Calcular todas las fechas. Administrar planes y clientes. |
| Servidor Go del restaurante | Guardar su identidad y su llave. Activar, consultar actualizaciones e importar archivos. Verificar la licencia firmada sin internet. Aplicar estados y cupos. |
| Terminales (cajas, comanderos Android, KDS) | Autenticarse con el servidor Go del restaurante. **Nunca** hablan con el servidor de licencias ni aplican reglas comerciales propias. |

La licencia pertenece a la **computadora principal del restaurante**, no a las terminales. Una licencia tiene como máximo **una activación vigente**. No se cobra por usuario creado ni por dispositivo registrado.

---

## 2. Identificadores

| Campo | Formato | Origen |
| --- | --- | --- |
| `product_id` | `adel_pos` | Fijo |
| `license_id` | UUID | Servidor de licencias, al emitir |
| `activation_id` | UUID | Servidor de licencias, en cada activación o transferencia |
| `server_id` | UUID v4, minúsculas | Identidad existente de AD-EL POS (`identity/`). Nunca se regenera sola y no viaja en los respaldos. |
| `installation_public_key` | base64url sin relleno, 32 bytes Ed25519 | Llave de licencias de la instalación |
| `request_id` | UUID | Cliente, una por operación, para que los reintentos no dupliquen nada |
| `challenge_id` | UUID | Servidor de licencias |

**Llave de licencias de la instalación.** El servidor Go genera, una sola vez, un par de llaves **Ed25519 exclusivo para licencias** dentro de `identity/`, separado del certificado ECDSA de la red local. Al igual que el `server_id`, nunca se regenera solo ni viaja en los respaldos. La llave privada nunca sale del equipo. Si la identidad se pierde o se restaura un respaldo en otra computadora, esta tiene otra identidad y debe activarse o transferirse.

---

## 3. Formatos y criptografía

- JSON en UTF-8. Las llaves y firmas binarias se codifican en base64url sin relleno; el receptor rechaza las codificaciones no canónicas.
- Instantes en RFC 3339, en UTC y terminados en `Z`. El servidor emite seis decimales (`2026-11-16T06:30:00.000000Z`). El cliente acepta de 0 a 9 decimales y nunca acepta horas locales con desfase.
- **Licencia firmada:** JWS Compact con encabezado exacto `{"alg":"EdDSA","kid":"<kid>","typ":"adelpos-lic+jws"}`.
- **Entornos de firma separados:** `test` (por ejemplo, `licensing.sicrat.com`) y `production`.
  - El campo `environment` de la licencia debe coincidir con el entorno de la llave `kid`.
  - Las versiones de producción de AD-EL POS **solo confían en las llaves de producción**. Las versiones de desarrollo solo confían en las de prueba.
  - Una licencia de prueba nunca funciona en una instalación de producción.
- El cliente contiene únicamente **llaves públicas**, asociadas a su `kid`. Las llaves privadas de firma nunca se incluyen en repositorios ni instaladores.
- Las llaves de los archivos de prueba compartidos (sección 13) son públicas y deterministas. El servidor las rechaza en producción y el cliente de producción no confía en ellas.

---

## 4. Contenido exacto de la licencia firmada

Todos los campos son obligatorios. Los que admiten `null` lo indican.

```json
{
  "schema_version": "1.0",
  "product_id": "adel_pos",
  "environment": "production",
  "license_id": "UUID",
  "activation_id": "UUID",
  "server_id": "UUID v4",
  "installation_public_key": "base64url de 32 bytes",
  "licensee_name": "Nombre o razón social del cliente",
  "license_type": "subscription",
  "billing_period": "monthly",
  "license_status": "active",
  "status_reason": null,
  "license_revision": 7,
  "issued_at": "2026-11-16T06:30:00.000000Z",
  "period_starts_at": "2026-11-16T06:30:00.000000Z",
  "expires_at": "2026-12-16T06:30:00.000000Z",
  "grace_days": 5,
  "updates_until": "2026-12-16T06:30:00.000000Z",
  "plan": { "plan_id": "elemental", "name": "Plan elemental", "custom": false },
  "limits": { "cash_registers": 3, "order_takers": 3, "kitchen_displays": 3 },
  "features": {}
}
```

| Campo | Regla |
| --- | --- |
| `environment` | `production` o `test` |
| `license_type` | `perpetual`, `subscription` o `trial` |
| `billing_period` | `monthly`, `quarterly`, `semiannual` o `annual` en suscripciones. `null` en perpetua y prueba. |
| `license_status` | `active` o `revoked`. Ningún otro valor. |
| `status_reason` | `null` si está `active`. Si está `revoked`: `transferred` (se transfirió a otra computadora), `deactivated` (se liberó la licencia) o `revoked` (la revocó el propietario). |
| `license_revision` | Entero ≥ 1. Contador **global por licencia** que aumenta en cada firma nueva. Puede saltar números y nunca se reutiliza. |
| `issued_at` | Momento de la firma |
| `period_starts_at` | Inicio del periodo vigente. En perpetua, la activación. |
| `expires_at` | Instante exclusivo de vencimiento. `null` solo en perpetua. |
| `grace_days` | `5` en suscripción, `0` en prueba y perpetua |
| `updates_until` | Última fecha de publicación de versiones a las que tiene derecho la instalación |
| `plan` | Solo informativo. `custom: true` si la licencia se personalizó. Los derechos los marca `limits`. |
| `limits` | Enteros de 0 a 999. En V1 no existe «sin límite». |
| `features` | Objeto `clave → true` con los módulos habilitados. Claves `^[a-z][a-z0-9_]{1,63}$`. Vacío en V1. |

**Reglas para el cliente:**

- Rechaza la licencia si falta un campo obligatorio o si su tipo o formato es incorrecto.
- Ignora los campos adicionales desconocidos.
- Ignora las claves de `features` que no conoce, sin habilitarlas para nada.
- Si `schema_version` no empieza con `1.`, la trata como incompatible.

---

## 5. Reglas de fechas (las calcula únicamente el servidor de licencias)

### 5.1 Meses de calendario

- Las fechas se calculan en la **zona horaria del cliente** (IANA), que se guarda en su ficha. La predeterminada es `America/Monterrey`. El resultado se firma en UTC.
- Sumar N meses conserva el **día ancla** y la hora local. Si el día no existe en el mes destino, se usa el último día de ese mes.
- El día ancla se guarda en la licencia para que no se recorra. Ejemplo: 31 ene → 28 feb → 31 mar → 30 abr.
- Si la hora local no existe por un cambio de horario, se usa el siguiente instante válido. Si la hora se repite, se usa el primero.
- Duraciones: mensual 1, trimestral 3, semestral 6 y anual 12 meses. Pruebas: 15 días, 1 mes o 3 meses. «15 días» significa 15 días de calendario a la misma hora local.

### 5.2 Inicio del primer periodo

- **Activación en línea:** el momento en que el servidor confirma la activación.
- **Activación sin internet:** el momento en que se aprueba la solicitud y se genera el `.lic`.
- El día ancla y la hora local se toman de ese momento.
- Una suscripción emitida pero no activada todavía no puede renovarse. Su periodo inicial se puede editar en el panel.

### 5.3 Renovación de suscripciones

El administrador captura la **fecha de pago** (por omisión, la actual) y la duración. La duración puede ser distinta de la anterior.

```
limite_tolerancia = expires_at + grace_days × 24 h

si fecha_pago < limite_tolerancia:
    base = expires_at            (pagó antes de vencer o dentro de la tolerancia; conserva el día ancla)
si no:
    base = fecha_pago            (el día ancla y la hora local pasan a ser los del pago)

nuevo expires_at = base + duración, aplicando la regla del día ancla
```

Ejemplos (hora local 10:00 en todos los casos):

- Vence el 2 de marzo y paga el 1 de marzo → nuevo vencimiento: 2 de abril.
- Vence el 2 de marzo y paga el 5 de marzo, dentro de la tolerancia → 2 de abril.
- Vence el 2 de marzo y paga el 7 de marzo a las 10:00 o después → 7 de abril. La tolerancia terminó y esos 5 días se regalan.
- Vence el 2 de marzo y paga el 20 de marzo → 20 de abril.

### 5.4 Pruebas

- El plan y la duración se eligen al emitir.
- Sin tolerancia (`grace_days: 0`). `updates_until = expires_at`.
- **Convertir una prueba en pago** firma una nueva revisión para la misma activación, sin reinstalar. El nuevo periodo empieza en la fecha de pago y los días de prueba restantes no se acumulan.

### 5.5 Actualizaciones

- **Suscripción y prueba:** `updates_until = expires_at`.
- **Perpetua:** se eligen 0, 6 o 12 meses al emitir (6 por omisión). Se cuentan desde el inicio del primer periodo (sección 5.2). Con 0 meses, `updates_until` es igual a la activación.
- **Compra de mantenimiento de una perpetua:** amplía `updates_until` desde la fecha posterior entre la fecha de pago y el `updates_until` vigente.
- La comparación con la fecha de publicación de cada versión le corresponde al actualizador de AD-EL POS, que está fuera de este contrato. Al terminar el periodo, la versión instalada sigue funcionando.

### 5.6 Estado temporal, calculado por el cliente

```
ahora_efectivo = máximo(reloj_local, última_hora_confiable)

perpetua:                                  vigente
ahora_efectivo < expires_at:               vigente
expires_at <= ahora_efectivo < limite:     tolerancia   (límite = expires_at + grace_days × 86 400 s)
ahora_efectivo >= limite:                  vencida
```

`última_hora_confiable` es el valor más reciente entre tres datos que el cliente guarda de forma persistente:

- el `server_time` de las respuestas del servidor de licencias;
- el `issued_at` de las licencias aceptadas;
- la última hora local registrada mientras el sistema funcionaba.

Atrasar el reloj nunca prolonga una licencia. Las demás protecciones contra cambios de reloj y copias de la base le corresponden a AD-EL POS.

---

## 6. Estados de la licencia en AD-EL POS

| Estado | Condición |
| --- | --- |
| **Vigente** | Licencia válida, `active`, antes de su vencimiento |
| **Tolerancia** | Licencia válida, `active`, dentro de sus `grace_days` |
| **Vencida** | Licencia válida, `active`, con la tolerancia agotada |
| **Revocada** | Licencia válida con `license_status: revoked`. El mensaje depende de `status_reason`. |
| **Sin activar** | No hay ninguna licencia válida y la base no tiene operaciones |
| **Sin activar con datos** | No hay ninguna licencia válida, pero la base ya tiene operaciones (por ejemplo, un respaldo restaurado) |

Un archivo alterado, firmado con una llave no confiable o destinado a otra instalación **se ignora**. En ese caso se usa la última licencia válida aceptada. Si no hay ninguna, el estado es sin activar.

Qué se permite en cada estado:

| Operación | Vigente | Tolerancia | Vencida / Revocada | Sin activar con datos | Sin activar |
| --- | --- | --- | --- | --- | --- |
| Crear pedidos y agregar productos a cuentas abiertas | Sí, dentro de los cupos | Sí, dentro de los cupos | **No** | No | No |
| Cobrar, cancelar y aplicar descuentos a lo ya capturado | Sí | Sí | **Sí** | No | — |
| Abrir turno de caja | Sí, dentro de los cupos | Sí, dentro de los cupos | Solo si hay cuentas pendientes de cobro, y dentro de los cupos | No | No |
| Cerrar turno y hacer el corte | Sí | Sí | Sí | No | — |
| KDS: mostrar pedidos ya enviados | Sí | Sí | Sí | No | — |
| Reportes, historial y respaldos | Sí | Sí | Sí | Sí | — |
| Administración (usuarios, catálogo, configuración) | Sí | Sí | Sí | No | No |
| Activar, renovar, transferir o importar `.lic` | Sí | Sí | Sí | Sí | Sí |

**Reglas firmes:**

- Ningún estado borra ventas, inventario, historial ni configuración.
- Ningún estado impide cobrar una cuenta ya abierta en una instalación activada.
- Ningún estado bloquea la consulta de reportes ni de historial.
- Los permisos de usuario y los derechos de la licencia son independientes: tener permiso para cobrar no garantiza que haya un cupo libre.

---

## 7. Cupos de sesiones simultáneas (los aplica el servidor Go)

| Límite | Qué ocupa un cupo | Cuándo se libera |
| --- | --- | --- |
| `cash_registers` (cajas) | Un **turno de caja abierto** en una terminal. Una caja no es una computadora, un usuario, un turno ni un cajón: es una terminal operando como caja. | Al cerrar ese turno. Si la terminal se cae, el turno sigue abierto y el cupo, ocupado. Un administrador puede cerrar el turno desde otra computadora. Cambiar de cajero en la misma terminal no consume otro cupo. |
| `order_takers` (comanderos) | Una sesión, Android o de escritorio, que **captura pedidos sin turno de caja abierto** | Al cerrar la sesión, al salir de ese modo o tras un periodo sin actividad. Ese periodo se define en la etapa de integración. |
| `kitchen_displays` (KDS) | Una terminal en **modo Cocina** | Al salir de ese modo o al cerrar la sesión |

- Los reportes, el catálogo, el inventario y la administración no ocupan cupo.
- La operación es la que se valida: crear o modificar un pedido exige tener un turno de caja o un cupo de comandero.
- La asignación de cupos debe ser atómica, para que dos terminales no obtengan el mismo cupo al mismo tiempo.
- Si una licencia nueva trae menos cupos de los que hay en uso, nada se interrumpe. Solo se impide ocupar nuevos cupos hasta quedar dentro del límite.
- **Nota para la integración:** el conteo actual (`sessions-summary`, por `desktop`, `android` y `kitchen`) cuenta tipos de dispositivo. Este contrato cuenta **lo que está haciendo la terminal**. Una terminal de escritorio puede ser caja, comandero, cocina o solo administración.

---

## 8. API en línea

Todas las peticiones son HTTPS con TLS verificado, usan `Content-Type: application/json` y tienen un tamaño máximo de 16 KiB.

- La dirección base es configurable en AD-EL POS. Para pruebas: `https://licensing.sicrat.com`.
- La especificación formal está en `contracts/v1/openapi.yaml` y `contracts/v1/license.schema.json`, que se generan en la etapa 2 y deben coincidir con este documento.

### 8.1 Desafío

`POST /v1/activations/challenge`

```json
{ "action": "activate", "product_id": "adel_pos", "server_id": "UUID", "activation_id": null }
```

- `action` puede ser `activate`, `transfer`, `refresh` o `deactivate`.
- `activation_id` es `null` en `activate` y `transfer`, y obligatorio en `refresh` y `deactivate`.

Respuesta `200`:

```json
{ "challenge_id": "UUID", "nonce": "base64url de 32 bytes", "expires_at": "RFC 3339" }
```

Cada desafío es de un solo uso, dura 5 minutos y solo sirve para la acción, el producto y la instalación que lo pidieron.

### 8.2 Prueba de posesión

```
mensaje = UTF8("ADELPOS-LIC-V1\n" + action + "\n" + challenge_id + "\n" + nonce + "\n" + product_id + "\n" + server_id + "\n" + (activation_id o "-"))
proof   = base64url(Ed25519.sign(llave_privada_de_licencias, mensaje))
```

En `activate` y `transfer`, el servidor verifica la prueba con la `installation_public_key` enviada. En `refresh` y `deactivate`, la verifica con la llave vinculada a la activación.

### 8.3 Activar

`POST /v1/activations`

```json
{
  "request_id": "UUID", "product_id": "adel_pos", "license_key": "K7M-9R3X-6TVP-8CHD",
  "server_id": "UUID", "installation_public_key": "base64url", "challenge_id": "UUID",
  "proof": "base64url", "app_version": "0.12.0"
}
```

- **Éxito `200`:** `{ "license_jws": "...", "server_time": "RFC 3339", "request_id": "UUID" }`
- **La misma instalación vuelve a activar** (mismo `server_id` y misma llave, con una activación vigente de esta licencia): devuelve su revisión vigente. No es una transferencia.
- **La licencia ya está activa en otra instalación:** `409 ACTIVATION_LIMIT` con `details.transfer_available` (`true` o `false`).

### 8.4 Transferir por autoservicio

`POST /v1/activations/transfer` recibe los mismos campos que activar y responde igual cuando tiene éxito.

**Reglas:**

1. Requiere que la licencia exista, no esté revocada y tenga una activación vigente en **otra** instalación.
2. Requiere que no haya ninguna transferencia por autoservicio pendiente de confirmar. Si la hay, responde `409 TRANSFER_LIMIT` (en AD-EL POS: «Comunícate con soporte») y genera una alerta en el panel.
3. En una sola transacción:
   - firma una revisión `revoked` / `transferred` para la activación anterior;
   - crea una activación nueva con su revisión `active`;
   - marca la transferencia como pendiente de confirmar.
4. La transferencia **se confirma** cuando la instalación anterior se autentica con `refresh` o `deactivate` y recibe su revisión de cierre. En ese momento, el contador de transferencias pendientes vuelve a 0.
5. El panel genera una alerta si una licencia acumula más de una transferencia en 12 meses, aunque estén confirmadas.
6. Las transferencias que hace el propietario desde el panel (por ejemplo, sin internet) no consumen el cupo del autoservicio, pero quedan en la auditoría y cuentan para la alerta de 12 meses.

### 8.5 Consultar actualización

`POST /v1/activations/refresh`

```json
{
  "request_id": "UUID", "product_id": "adel_pos", "server_id": "UUID", "activation_id": "UUID",
  "challenge_id": "UUID", "proof": "base64url", "app_version": "0.12.0"
}
```

- **Respuesta `200`:** igual que activar. Devuelve la **revisión vigente** de esa activación, que puede ser más nueva por una renovación, un cambio de plan o una revocación.
- Si la activación ya terminó, devuelve su revisión de cierre y confirma la transferencia pendiente si la había.
- Esta consulta **no es obligatoria** para operar. AD-EL POS la hace en segundo plano cuando tiene internet: al arrancar y una vez al día como valor orientativo. Sin internet no pasa nada.

### 8.6 Desactivar (liberar la licencia)

`POST /v1/activations/deactivate` recibe los mismos campos que consultar actualización.

Respuesta `200`:

```json
{
  "activation_id": "UUID", "deactivated_at": "RFC 3339", "status": "deactivated",
  "license_jws": "revisión de cierre revoked/deactivated", "server_time": "RFC 3339", "request_id": "UUID"
}
```

Solo una desactivación confirmada por el servidor libera la licencia. Después se puede activar en otra computadora con el mismo código, sin límite y sin soporte.

### 8.7 Reintentos y errores

- **Reintentos:** se identifican por `product_id` y `request_id`. Un reintento idéntico devuelve exactamente la misma respuesta. Reutilizar un `request_id` con otro contenido devuelve `409 REQUEST_CONFLICT`.
- **Formato de error:** `{ "error": { "code": "...", "message": "...", "request_id": "UUID o null", "details": {} } }`
- El `message` es informativo. AD-EL POS muestra sus propios textos en español claro a partir de `code`.

| Código | HTTP | Uso |
| --- | --- | --- |
| `INVALID_REQUEST` | 400 | Campos faltantes, sobrantes o con formato incorrecto |
| `INCOMPATIBLE_SCHEMA` | 400 | Versión de esquema no soportada |
| `INVALID_PROOF` | 401 | Desafío inválido, vencido o ya usado, prueba incorrecta o identidad desconocida |
| `REVOKED` | 403 | Licencia revocada por el propietario |
| `LICENSE_NOT_FOUND` | 404 | Código inexistente, retirado o de otro producto. La respuesta es la misma en todos los casos. |
| `ACTIVATION_LIMIT` | 409 | La licencia ya está activa en otra instalación |
| `TRANSFER_LIMIT` | 409 | Hay una transferencia por autoservicio pendiente de confirmar |
| `REQUEST_CONFLICT` | 409 | `request_id` reutilizado con otro contenido |
| `RATE_LIMITED` | 429 | Límite de intentos |
| `TEMPORARY_UNAVAILABLE` | 503 | Falla temporal. Nunca afecta a una licencia válida ya instalada. |

Los códigos comerciales nunca se escriben en los registros del servidor.

---

## 9. Activación y renovación sin internet

### 9.1 Solicitud `.licreq`

```json
{ "schema_version": "1.0", "payload_b64u": "...", "signature_b64u": "..." }
```

- `payload_b64u` es el JSON en UTF-8 codificado en base64url, con estos campos:
  - `action`: `activate`, `renew` o `deactivate`;
  - `request_id`, `created_at`, `product_id`, `server_id`, `installation_public_key` y `app_version`;
  - en `renew` y `deactivate`, también `license_id` y `activation_id`;
  - en `activate`, opcionalmente `license_key`.
- **Firma:** `Ed25519(llave_privada_de_licencias, UTF8("ADELPOS-LICREQ-V1\n" + payload_b64u))`.
- `renew` pide la revisión vigente. Sirve, por ejemplo, después de registrar un pago o un cambio de plan.
- El servidor guarda el archivo original cifrado y sin cambios. Si trae `license_key`, nunca la muestra. **Recibir una solicitud no concede nada:** el propietario la aprueba en el panel y asigna la licencia en el caso de `activate`.
- Una transferencia sin internet la decide el propietario, aprobando el `activate` de la computadora nueva como transferencia.

### 9.2 Respuesta `.lic`

- Contiene el **mismo JWS Compact** que la API en línea, como texto UTF-8.
- En una activación sin internet, el periodo empieza al aprobar la solicitud y generar el archivo (sección 5.2).
- Para `deactivate`, el `.lic` contiene la revisión de cierre y libera la licencia en el servidor.
- No es posible borrar a distancia una copia que sigue desconectada. Esa limitación es conocida y aceptada.

---

## 10. Verificación en el servidor Go del restaurante

Antes de aceptar una licencia, ya sea en línea o con un `.lic`, el servidor Go comprueba:

1. El encabezado exacto, que el `kid` sea de confianza para el entorno de esa versión y la firma Ed25519.
2. Que el contenido cumpla la sección 4. `product_id = adel_pos` y `environment` debe coincidir con el `kid`.
3. Que `server_id` e `installation_public_key` sean exactamente los suyos.
4. Que `license_revision` sea **mayor** que la más alta ya aceptada para ese `license_id`. Una revisión igual solo se acepta si es idéntica byte por byte. Así se rechaza cualquier retroceso, incluso entre activaciones distintas de la misma licencia.
5. Si todo es correcto, guarda la licencia, la revisión aceptada y la última hora confiable de forma persistente.

Además:

- La verificación se hace **en cada operación protegida** y en el backend. Las terminales solo muestran el estado.
- El servidor Go nunca necesita internet para verificar.

---

## 11. Planes

- El catálogo de planes se administra en el panel, con nombre, cajas, comanderos y KDS. Los valores iniciales son provisionales:

| Plan (`plan_id`) | Cajas | Comanderos | KDS |
| --- | --- | --- | --- |
| Básico (`basico`) | 1 | 0 | 0 |
| Elemental (`elemental`) | 3 | 3 | 3 |
| Premium (`premium`) | 5 | 10 | 5 |

- La licencia firma **números**, no paquetes. Ajustar los números de una licencia la marca como personalizada (`custom: true`), y deja de seguir a su plan.
- Al modificar un plan, el propietario elige si el cambio aplica solo a licencias nuevas o también a las existentes que siguen ese plan.
- Si aplica a las existentes, el servidor firma una revisión nueva para cada activación vigente. Las instalaciones con internet la reciben en su siguiente consulta. Las demás la reciben en su próxima renovación o con un `.lic`.
- **Una reducción nunca se aplica a licencias vigentes.** Aplica a licencias nuevas o en su siguiente renovación.

---

## 12. Privacidad y seguridad

- El servidor de licencias **solo recibe** el `server_id`, la llave pública, la versión de la aplicación y el código comercial. **Nunca** recibe ventas, productos, usuarios, inventario ni la base del restaurante.
- Ningún secreto de firma se comparte con AD-EL POS.
- La firma impide modificar localmente los derechos sin invalidarlos.
- Ni la identidad ni la firma impiden que el administrador de un equipo clone por completo una máquina virtual o restaure instantáneas antiguas. Las protecciones locales adicionales le corresponden a AD-EL POS.

---

## 13. Archivos de prueba compartidos

En la etapa 2, el servidor de licencias publica en `contracts/v1/fixtures/` lo siguiente, junto con un verificador independiente en Go que sirve de base para la etapa de integración de AD-EL POS:

- **Licencias firmadas** con llaves públicas de prueba: perpetua, suscripción mensual, suscripción anual, prueba, renovada, cambio de plan, personalizada, `transferred`, `deactivated` y `revoked`.
- **Casos negativos:** firma alterada, `kid` desconocido, llave de prueba presentada como de producción, otra instalación, otro producto, revisión menor, campo faltante, `schema_version` 2.0 y tipos incorrectos.
- **Casos de fechas**, con entradas y resultados esperados:
  - 31 de enero más 1 mes, y la secuencia del día ancla;
  - 29 de febrero más 12 meses;
  - renovación antes de vencer, dentro de la tolerancia, justo en el límite de la tolerancia y después;
  - conversión de una prueba en pago;
  - actualizaciones de una perpetua con 0, 6 y 12 meses, y la compra de mantenimiento.
- **Casos de estado temporal** (sección 5.6), con límites exactos y reloj atrasado.
- **Pruebas de posesión** para las cuatro acciones, y **solicitudes `.licreq`** para las tres acciones.

---

## 14. Decisiones del propietario incorporadas

1. La licencia se ata al servidor Go del restaurante (`server_id` más llave Ed25519). No se cobra por usuario ni por dispositivo.
2. No hay comunicación periódica obligatoria. El servidor fija y firma el vencimiento, también en las activaciones sin internet.
3. Suscripciones mensual, trimestral, semestral y anual en meses de calendario. Si el día no existe, se usa el último día del mes.
4. Renovación desde el vencimiento si se paga antes de vencer o dentro de la tolerancia. Si se paga después, desde la fecha de pago, y se regalan los días de tolerancia.
5. Tolerancia de 5 días para las suscripciones. Las pruebas no tienen tolerancia.
6. Pruebas de 15 días, 1 mes o 3 meses, con el plan elegido al emitir.
7. Perpetua con 0, 6 o 12 meses de actualizaciones (6 por omisión), contados desde la activación en línea o desde la generación del `.lic`.
8. Transferencia por autoservicio: 1 pendiente como máximo, y el contador vuelve a 0 al confirmarse. Las alertas se generan según la sección 8.4.
9. Planes básico, elemental y premium, editables en el panel, con propagación opcional a las licencias existentes. Las reducciones no se aplican a licencias vigentes.
10. Al vencer se puede cobrar y cerrar lo capturado, pero no capturar nuevos pedidos ni agregar productos.
11. Tabla de estados de la sección 6.
12. `licensing.sicrat.com` es solo para pruebas y usa llaves de prueba.

## 15. Fuera de V1

- El actualizador, los manifiestos de versiones y la firma de instaladores. Le corresponden a AD-EL POS y van al final.
- La huella del equipo y las protecciones locales contra copias o cambios de reloj, más allá de la sección 5.6.
- El tiempo de inactividad que libera un cupo de comandero y los días de aviso antes del vencimiento. Se definen en la etapa de integración de AD-EL POS.
- Pasarela de pagos y portal del cliente.
