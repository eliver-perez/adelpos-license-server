# Servidor de licencias AD-EL POS — Etapa 4: transferencias y alertas

## Objetivo

Completar el contrato con la **transferencia por autoservicio** (contrato §8.4):

- la ruta `transfer`;
- el cálculo real de `transfer_available`;
- el código de error `TRANSFER_LIMIT`;
- la confirmación por parte de la computadora anterior;
- las **alertas** del panel.

Además, se corrige la bonificación (propuestas V1.1 n.º 27 y 28) y se deja todo listo para que el propietario reciba el contrato `_v2`.

Al terminar esta etapa, la API cumple completo el contrato. Solo quedan para la etapa 5 las pantallas definitivas y el despliegue.

## Documentos de referencia

| Documento | Uso |
| --- | --- |
| `docs/CONTRATO_LICENCIAS_ADEL_POS.md` | Fuente de verdad, en especial §8.4. **No se edita.** |
| `DECISIONS.md` y `AVANCE.md` | Estado al cerrar la etapa 3: commit `d45651a`, 432 pruebas, propuestas V1.1 n.º 1 a 32 |
| Esta especificación | Alcance, decisiones del propietario y pruebas |

**Prioridad:**

1. Las decisiones del propietario.
2. El contrato.
3. Esta especificación.

## Decisiones del propietario para esta etapa (2026-11-17)

1. **Propuestas V1.1 aprobadas:**
   - las n.º 24, 25, 26, 29, 31 y 32, como están;
   - las decisiones E a I, como están.
2. **Propuestas n.º 23 y n.º 30:** aprobadas, con una regla adicional que irá en el contrato `_v2` para la integración:
   - **n.º 23:** un `period_starts_at` en el futuro es válido. El estado se calcula solo con `expires_at` (§5.6).
   - **n.º 30:** mientras espera su reducción, una licencia puede firmar cupos mayores que los de su plan, con `custom: false`.
3. **Propuestas n.º 27 y 28: se adoptan las alternativas.**
   - **n.º 28:** el valor de un periodo es **lo cobrado más la bonificación aplicada en ese pago**.
   - **n.º 27:** la bonificación suma la parte proporcional, aún no consumida, de **todos** los periodos ya pagados que no han terminado. Esto incluye el periodo en curso y el que se pagó por adelantado con una renovación anticipada.
4. **Alertas:**
   - aparecen en el resumen del panel y en un **botón de notificaciones en la barra superior**, con el número de alertas sin revisar y su lista;
   - se pueden marcar como «revisada»;
   - no se envían correos.
5. **Una licencia de prueba también puede transferirse** por autoservicio, con las mismas reglas. **Transferir nunca reinicia, extiende ni modifica las fechas de ninguna licencia**, porque las fechas pertenecen a la licencia y no a la activación.
6. **Reglas de transferencia ya acordadas** (contrato §8.4 y sección 14, punto 8):
   - solo puede haber 1 transferencia por autoservicio pendiente de confirmar;
   - un segundo intento antes de la confirmación se rechaza con `TRANSFER_LIMIT` y genera una alerta;
   - hay alerta cuando una licencia suma más de una transferencia en 12 meses, confirmadas o no;
   - las transferencias que hace el propietario desde el panel no consumen la del autoservicio, pero sí cuentan para esa alerta.

## Reglas generales

Se aplican las mismas reglas de la etapa 3:

- revisar secretos antes de cada commit;
- usar datos sintéticos que se borran al terminar;
- textos en español claro;
- código legible con tabuladores;
- migraciones nuevas a partir de la `008`;
- un commit por fase, con todas las pruebas pasando en **PHP 8.5 y PHP 8.3**;
- comprobar cada regla quitándola una a la vez;
- toda operación en una sola transacción, idempotente y auditada.

**Ambigüedades:** se resuelven con el criterio más conservador y coherente con el contrato, y se registran como propuestas V1.1 desde la **n.º 33**, con su alternativa. Esta especificación ya fija tres de ellas: la n.º 33 (Fase 2, punto 2), la n.º 34 (Fase 3, punto 2) y la n.º 35 (Fase 3, punto 3).

---

## Fase 0 — Verificación y corrección de la bonificación

1. **Confirmar el estado de partida:**
   - commit `d45651a` o posterior y árbol limpio;
   - 432 pruebas pasando en PHP 8.5 y en PHP 8.3;
   - el verificador Go pasa;
   - el SHA-256 del contrato no cambió.
2. **Registrar en `DECISIONS.md`** las decisiones 1 a 6 de esta especificación.
3. **Corregir la bonificación** (decisión 3):

   ```
   valor_del_periodo(pago) = monto_cobrado + bonificación_aplicada_en_ese_pago

   bonificación = Σ sobre cada pago con periodo pagado que todavía no termina en el instante del nuevo pago:
                  valor_del_periodo × segundos_no_consumidos_de_ese_periodo ÷ segundos_del_periodo
   ```

   - Un periodo que todavía no empieza (renovación anticipada) cuenta completo.
   - Cada parte se redondea a centavos con la misma regla de `Domain\Credit`. Después se suman.
   - El monto sugerido sigue siendo `máximo(0, precio_nuevo − bonificación)`.
   - Si un pago no tiene monto registrado (licencias emitidas antes de la etapa 3), se usa el precio de catálogo vigente, como hasta ahora.
4. **Actualizar las pruebas y los casos de bonificación** de `contracts/v1/fixtures/`. Hay que agregar como mínimo:
   - la renovación anticipada con subida de plan: los 4 días restantes del periodo en curso más el mes pagado por adelantado;
   - la segunda subida de plan sobre un periodo que ya se había pagado con bonificación;
   - la combinación de los dos casos.

   Los montos esperados se calculan a mano, por separado, como en la etapa 3.
5. **Listar todas las marcas `PENDIENTE etapa 4`** y asignar cada una a la fase que la resuelve.

---

## Fase 1 — Modelo de datos

Migración `008`:

1. **Transferencias:** un registro por transferencia con estos datos:
   - licencia;
   - activación de origen y de destino;
   - **vía:** `self_service` (autoservicio) u `owner` (el propietario desde el panel);
   - instante y administrador, si lo hubo;
   - estado de confirmación: `pending` o `confirmed`, con el instante y la forma en que se confirmó;
   - motivo de liberación manual, si la hubo.

   Solo puede haber **una transferencia de autoservicio pendiente** por licencia, y debe estar garantizado en la base.
2. **Las transferencias que el propietario ya puede hacer desde el panel** (con computadora destino, o «recuperar plaza» sin destino) quedan registradas con la vía `owner`.
3. **Alertas** con estos datos:
   - tipo: `transfer_blocked` (intento bloqueado) o `frequent_transfers` (más de una en 12 meses);
   - licencia, instante de creación y detalle;
   - número de intentos;
   - revisión: quién la marcó y cuándo.

   Para no repetir alertas, un mismo tipo y una misma licencia comparten **una alerta abierta**. Mientras no se revise, los intentos nuevos solo aumentan su contador y actualizan su último instante.
4. Se actualizan `bin/grants.php`, `SCHEMA.md` y las etiquetas de auditoría.

**Pruebas:**
- La unicidad de la transferencia pendiente está garantizada en la base.
- Las vías y estados válidos.
- Cada tipo de alerta tiene a lo más una abierta por licencia.
- Los permisos de la cuenta de aplicación.

---

## Fase 2 — Transferencia por autoservicio (contrato §8.4)

1. **Desafío:** se habilita la acción `transfer`, con `activation_id: null`.
2. **`POST /v1/activations/transfer`** recibe los mismos campos que activar y responde igual cuando tiene éxito. Las reglas se aplican en este orden:
   1. **Código desconocido, retirado o de otro producto:** `404 LICENSE_NOT_FOUND`, con la misma respuesta en todos los casos.
   2. **Licencia revocada:** `403 REVOKED`.
   3. **La misma instalación ya está activa** (mismo `server_id` y misma llave): devuelve su revisión vigente, igual que la reactivación. No cuenta como transferencia.
   4. **No hay ninguna activación vigente:** se hace una **activación normal**, que no cuenta como transferencia. Si es la primera activación, se calcula el primer periodo. Esta regla se registra como **propuesta V1.1 n.º 33**; la alternativa sería responder con un error y pedir a AD-EL POS que use `activate`.
   5. **Hay una transferencia de autoservicio pendiente:** `409 TRANSFER_LIMIT`, y se crea o actualiza la alerta `transfer_blocked`. La negativa consume el desafío y queda guardada como respuesta idempotente, igual que las demás negativas comerciales.
   6. **En cualquier otro caso**, se ejecuta en **una sola transacción**:
      - la activación anterior pasa a `transferred` y se firma su revisión de cierre `revoked` / `transferred`;
      - se crea la activación nueva y se firma su revisión `active`, **con las mismas fechas, cupos, plan y módulos de la licencia**;
      - se registra la transferencia como `self_service` / `pending`;
      - se evalúa la alerta `frequent_transfers` (Fase 4);
      - se registran la auditoría y el historial de la licencia.
3. **`ACTIVATION_LIMIT`:** `details.transfer_available` es `true` si la licencia no está revocada y no tiene una transferencia de autoservicio pendiente. En cualquier otro caso es `false`.
4. **Fechas (decisión 5):**
   - Las fechas de la licencia nunca cambian al transferir, sea cual sea su tipo: prueba, suscripción, perpetua, vigente, en tolerancia o vencida.
   - Una prueba o suscripción vencida se puede transferir, pero sigue vencida.
5. Se conservan:
   - los límites de intentos de la API, incluido el límite por código comercial;
   - la regla de que el código nunca se guarda ni se registra;
   - los reintentos por `request_id`, incluido `REQUEST_CONFLICT`.

**Pruebas:**
- Transferencia exitosa de cada tipo de licencia: prueba, suscripción y perpetua; vigente, en tolerancia y vencida. Las fechas deben quedar idénticas al microsegundo.
- La computadora anterior conserva su revisión de cierre.
- La misma instalación; sin activación vigente; licencia revocada; código desconocido.
- Segundo intento con `TRANSFER_LIMIT` y su alerta, y varios intentos con una sola alerta abierta.
- `transfer_available` verdadero y falso.
- Reintento idéntico y `request_id` con otro contenido.
- Desafío de otra acción.
- **Concurrencia:** dos transferencias simultáneas desde dos computadoras distintas. Exactamente una gana y la otra recibe `TRANSFER_LIMIT`.
- Transferencia simultánea con una desactivación de la computadora anterior.

---

## Fase 3 — Confirmación y liberación manual

1. **La transferencia se confirma** (y el contador vuelve a 0) cuando la **computadora anterior** demuestra con su llave que es ella y recibe su revisión de cierre. Hay tres formas:
   - `refresh` en línea;
   - `deactivate` en línea;
   - la aprobación de su `.licreq` de `renew` o `deactivate` sin internet.
2. La confirmación **sin internet** se registra como **propuesta V1.1 n.º 34**. El contrato solo menciona `refresh` y `deactivate`, pero la solicitud firmada demuestra lo mismo.
3. **Liberación manual por el propietario:**
   - Sirve para cuando la computadora anterior nunca va a volver a conectarse, por ejemplo porque se dañó. Desde el detalle de la licencia, el superadministrador puede **liberar el contador de autoservicio** con un motivo obligatorio y nueva autenticación. Queda auditado.
   - La transferencia queda `confirmed`, con la forma `manual` y el motivo.
   - Se registra como **propuesta V1.1 n.º 35**. Es el camino de soporte que el contrato da por hecho, pero no describe.
4. Una confirmación es **idempotente**. Una segunda consulta de la computadora anterior no cambia nada.
5. Las transferencias del propietario (`owner`) no tienen estado pendiente: nacen confirmadas.

**Pruebas:**
- Confirmación por cada una de las tres formas.
- Una nueva transferencia es posible después de confirmar.
- La consulta de la computadora **nueva** no confirma nada.
- Una llave ajena no confirma.
- Liberación manual con permisos, motivo y nueva autenticación.
- Las transferencias del propietario no consumen el autoservicio.
- Confirmación repetida.
- **Concurrencia:** una confirmación simultánea con un intento de transferencia.

---

## Fase 4 — Alertas en el panel (decisión 4)

1. **`transfer_blocked`:** se genera con cada `TRANSFER_LIMIT`. El detalle debe permitir al propietario decidir:
   - el `server_id` que lo intentó;
   - la versión de AD-EL POS;
   - el número de intentos;
   - la fecha de la transferencia pendiente.
2. **`frequent_transfers`:** se genera cuando una licencia suma **dos o más transferencias**, de autoservicio o del propietario, dentro de los **12 meses anteriores**. Los 12 meses son de calendario, en la zona horaria del cliente, y se calculan con el motor de fechas.
3. **Panel:**
   - **Botón de notificaciones en la barra superior**, en todas las pantallas del panel, con el número de alertas sin revisar. Abre una lista: las sin revisar primero, y después las revisadas, con un filtro.
   - **Sección «Alertas» en el resumen**, con las más recientes sin revisar.
   - Cada alerta enlaza al detalle de su licencia y tiene el botón **«Marcar como revisada»**. Lo pueden usar el operador y el superadministrador; la cuenta de consulta solo las ve.
   - Al revisar una alerta queda registrado quién y cuándo, en la auditoría.
   - Si la misma situación vuelve a ocurrir **después** de revisar la alerta, se abre una alerta nueva.
4. El **detalle de la licencia** muestra:
   - el historial de transferencias: vía, origen, destino, fecha, estado y forma de confirmación;
   - si hay una transferencia pendiente;
   - el botón de liberación manual (Fase 3).

**Pruebas:**
- Creación de cada tipo de alerta.
- Una sola alerta abierta, con su contador.
- Alerta nueva después de revisar.
- Límite exacto de los 12 meses en la zona horaria del cliente.
- Las transferencias del propietario cuentan.
- Permisos para revisar.
- Número de alertas sin revisar en la barra.
- Auditoría.

---

## Fase 5 — Archivos del contrato y verificador

1. **`openapi.yaml`:**
   - ruta `POST /v1/activations/transfer`, con sus respuestas y errores, incluido `TRANSFER_LIMIT`;
   - acción `transfer` en el desafío;
   - `details.transfer_available` en `ACTIVATION_LIMIT`;
   - ejemplos.

   La prueba que compara las rutas de `ApiKernel` con el OpenAPI debe seguir pasando.
2. **`fixtures/`:**
   - **licencias:** la revisión `active` de la activación nueva tras una transferencia por autoservicio, con las mismas fechas, y la revisión de cierre `transferred` de la anterior;
   - **casos de revisión:** la computadora nueva acepta su primera revisión de una licencia con revisiones previas; la anterior acepta su cierre, que tiene un número de revisión mayor;
   - **casos de bonificación:** los de la Fase 0.
3. **El verificador de referencia en Go** pasa sobre todos los casos.
4. `contracts/v1/README.md` describe el flujo de transferencia desde el punto de vista de AD-EL POS:
   - qué hacer con `ACTIVATION_LIMIT` y `transfer_available`;
   - qué mostrar con `TRANSFER_LIMIT`;
   - que la computadora anterior conoce su cierre al consultar.

---

## Fase 6 — Panel y cierre

1. **Playwright:**
   - transferencia por autoservicio (simulada por API) y su reflejo en el detalle;
   - intento bloqueado con su alerta en la barra y en el resumen;
   - marcar como revisada;
   - liberación manual del contador;
   - alerta de transferencias frecuentes.
2. **Marcas:** cero `PENDIENTE etapa 4`.
3. **Documentación:** actualizar `README.md`, `ARCHITECTURE.md`, `SCHEMA.md`, `SECURITY.md` (limitación conocida: una computadora anterior sin internet sigue funcionando hasta su vencimiento o para siempre si es perpetua), `OPERATIONS.md` (cómo atender una alerta y cuándo liberar el contador) y `TEST_PLAN.md`.
4. **Insumo para el contrato `_v2`:** en `DECISIONS.md`, una tabla **consolidada y final** de todas las propuestas V1.1, de la n.º 1 a la última. Cada fila lleva:
   - número y sección del contrato;
   - el texto final de la regla, tal como quedó implementada;
   - su estado (aprobada, aprobada con nota o pendiente).

   El propietario la usará para recibir `CONTRATO_LICENCIAS_ADEL_POS_v2.md`. **Claude Code no edita el contrato.**
5. **`AVANCE.md`:**
   - cifras por fase y reglas comprobadas;
   - consultas al propietario (propuestas desde la n.º 33 y decisiones más allá de la especificación);
   - pendientes para la etapa 5;
   - comandos para reproducir.
6. **Paquete de despliegue** generado y revisado.

---

## Criterios de aceptación

- [ ] La bonificación suma lo no consumido de todos los periodos pagados, calculado sobre su valor completo, y queda cubierta con casos exactos.
- [ ] La transferencia por autoservicio cumple §8.4, con las reglas de esta especificación. Transferir nunca cambia las fechas.
- [ ] `TRANSFER_LIMIT` y `transfer_available` son reales y están probados, incluida la concurrencia.
- [ ] La confirmación funciona por las tres formas, más la liberación manual auditada.
- [ ] Las alertas aparecen en el resumen y en la barra superior, sin duplicarse, y se pueden marcar como revisadas.
- [ ] `openapi.yaml`, los archivos de prueba y el verificador Go cubren la transferencia.
- [ ] Hay una tabla consolidada de propuestas V1.1 lista para el contrato `_v2`.
- [ ] Cero `PENDIENTE etapa 4`. La suite pasa en PHP 8.5 y en PHP 8.3, y pasan Playwright, la revisión de secretos, el formato y el paquete.
- [ ] El contrato no se modificó.

## Fuera de esta etapa

- **Etapa 5:**
  - pantallas definitivas, también las del ciclo comercial y las de transferencias;
  - constancias;
  - `limit_req` de Nginx;
  - despliegue en `licensing.sicrat.com`.
- **El contrato `_v2`:** lo redacta y entrega el propietario a partir de la tabla consolidada.
- **Avisos por correo,** pasarela de pagos y facturación.
- **Cambios en AD-EL POS o en AIDEX.**
