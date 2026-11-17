# Archivos del contrato de licencias V1.0

Esta carpeta es la forma exacta, legible por una máquina, del [contrato de licencias](../../docs/CONTRATO_LICENCIAS_ADEL_POS.md) entre el servidor de licencias y AD-EL POS. Si un archivo de aquí y el contrato difieren, **manda el contrato**.

| Archivo | Qué es |
| --- | --- |
| [`license.schema.json`](license.schema.json) | Contenido de la licencia firmada (sección 4), en JSON Schema 2020-12 |
| [`openapi.yaml`](openapi.yaml) | API en línea (sección 8), en OpenAPI 3.1: rutas, peticiones, respuestas, errores y ejemplos |
| [`fixtures/`](fixtures/) | Archivos de prueba compartidos (sección 13) |
| [`generate-fixtures.php`](generate-fixtures.php) | Generador de los archivos de prueba |
| [`verify/`](verify/) | Verificador de referencia en Go, base de la integración de AD-EL POS |

## Llaves de prueba

Todas las llaves de `fixtures/` salen de **semillas públicas**. Sirven solo para pruebas.

- El servidor de licencias las rechaza en producción (`SigningKeys::FIXTURE_SEEDS`).
- Ninguna versión de producción de AD-EL POS debe confiar en ellas.
- `adelpos-prod-fixture-a` hace de llave de producción únicamente en los casos negativos. Sigue siendo pública.

## Archivos de prueba

`fixtures/vectors.json` lo contiene todo, con el resultado esperado de cada caso:

| Sección | Contenido |
| --- | --- |
| `signing_keys`, `installations` | Llaves de firma por entorno y dos instalaciones, con su semilla pública: `sha256("ADELPOS LICENSE PUBLIC TEST ONLY V1 " + name)` |
| `licenses` | 11 licencias válidas: perpetua con 0, 6 y 12 meses de actualizaciones, suscripción mensual y anual, prueba de 15 días, personalizada, las tres revisiones de cierre (`deactivated`, `transferred`, `revoked`) y una firmada con otra llave de confianza |
| `negative_licenses` | 17 licencias que deben rechazarse, con su motivo: firma o contenido alterados, `kid` desconocido, encabezado distinto, licencia de prueba en una instalación de producción, entorno que no coincide con la llave, otra instalación, otro producto, campo faltante, `schema_version` 2.0, tipos incorrectos y más |
| `revision_cases` | 6 casos de la regla «la revisión nunca retrocede» (sección 10) |
| `dates` | 24 casos de fechas con su resultado: meses de calendario con día ancla, años bisiestos, cambios de horario, 15 días de calendario y el primer periodo de cada tipo de licencia |
| `temporal_states` | 13 casos del estado temporal (sección 5.6): límites exactos de vigente, tolerancia y vencida, y reloj atrasado |
| `proofs` | Pruebas de posesión de las cuatro acciones. `transfer` es solo un vector criptográfico: la API todavía no la acepta |
| `offline_requests` | Solicitudes `.licreq` de las tres acciones |

Además, cada licencia está en `fixtures/licenses/<nombre>.lic` y cada solicitud en `fixtures/requests/<acción>.licreq`, tal como viajan.

Los casos de renovación, cambio de plan, conversión de prueba y compra de mantenimiento se agregan cuando el servidor implemente esas reglas.

```sh
php contracts/v1/generate-fixtures.php           # vuelve a escribir fixtures/
php contracts/v1/generate-fixtures.php --check   # comprueba que nada cambió
```

El generador no usa el código del servidor: arma cada contenido a mano, con sus valores esperados. Las pruebas (`tests/Unit/ContractFilesTest.php`) comparan después el servidor contra estos archivos: firma los mismos bytes, calcula las mismas fechas y valida igual que el esquema.

## Verificador de referencia

`verify/` es un módulo de Go sin dependencias externas. Aplica lo que debe aplicar el servidor Go del restaurante antes de aceptar una licencia (sección 10) y para calcular su estado (sección 5.6):

1. Encabezado exacto `{"alg":"EdDSA","kid":"<kid>","typ":"adelpos-lic+jws"}`.
2. `kid` de confianza **para el entorno de esa versión**: una versión de producción no incluye llaves de prueba.
3. Firma Ed25519.
4. Contenido de la sección 4: campos obligatorios, tipos y formatos. `product_id` es `adel_pos`. Los campos desconocidos se ignoran. Una `schema_version` que no empieza con `1.` es incompatible.
5. `environment` igual al entorno de la llave que firmó.
6. `server_id` e `installation_public_key` exactamente los de la instalación.
7. `license_revision` mayor que la más alta ya aceptada para ese `license_id`. Una revisión igual solo se acepta si es idéntica byte por byte.
8. Estado temporal con `ahora_efectivo = máximo(reloj local, última hora confiable)`.

| Archivo | Contenido |
| --- | --- |
| `verify/license.go` | `Verifier.Verify`: los pasos 1 a 7 |
| `verify/state.go` | `TemporalState` y `EffectiveNow`: el paso 8 |
| `verify/protocol.go` | Mensaje y firma de la prueba de posesión, y verificación de una solicitud `.licreq` |
| `verify/main.go` | Ejecuta todos los casos de `fixtures/vectors.json` y compara cada resultado con el esperado |

```sh
go run -C contracts/v1/verify . ../fixtures/vectors.json
```

Las fechas (`dates`) no las ejecuta el verificador: **las calcula únicamente el servidor de licencias** (sección 5). Las comprueba la suite de PHP contra el motor de fechas.

No es un validador de producción. Es código de referencia para la etapa de integración de AD-EL POS: se puede copiar y adaptar. Quedan fuera las partes que el contrato deja a AD-EL POS, como guardar de forma persistente la licencia, la revisión aceptada y la última hora confiable.

## Relación con el código del servidor

| En el contrato | En el servidor | Prueba que los compara |
| --- | --- | --- |
| `license.schema.json` | `src/Domain/LicenseContent.php`, que valida todo contenido antes de firmarlo | Cada regla del validador está en el esquema, salvo las que un esquema no puede expresar: fechas cruzadas, calendario real, seis decimales exactos y el orden de los campos |
| Rutas de `openapi.yaml` | `ApiKernel::ROUTES` | Deben ser las mismas |
| Peticiones de `openapi.yaml` | `Protocol::REQUEST_FIELDS` | Mismos campos, en el mismo orden |
| Errores de `openapi.yaml` | `ApiProblem::STATUS` | Mismos códigos y HTTP |
| Respuestas de `openapi.yaml` | Respuestas reales de la API | Las pruebas de integración validan cada respuesta |
