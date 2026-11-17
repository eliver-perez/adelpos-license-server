# Servidor de licencias AD-EL POS — Etapa 3: planes y ciclo comercial

## Objetivo

Completar el ciclo comercial de una licencia después de su activación:

- precios y registro de pagos;
- editor de planes con propagación de aumentos;
- renovación con la regla de tolerancia;
- conversión de prueba a pago;
- cambio de plan con bonificación;
- compra de mantenimiento anual;
- una tarea programada que aplica reducciones al vencer lo pagado.

Al terminar, el panel puede administrar la vida comercial completa de una licencia **sin cambiar el contenido firmado**. La licencia sigue siendo exactamente la de la sección 4 del contrato.

## Documentos de referencia

| Documento | Uso |
| --- | --- |
| `docs/CONTRATO_LICENCIAS_ADEL_POS.md` | Fuente de verdad. No se edita. |
| `DECISIONS.md` y `AVANCE.md` | Estado al cerrar la etapa 2: 377 pruebas, commit `72b9098`. Incluye las propuestas V1.1 y los `PENDIENTE etapa 3`. |
| Esta especificación | Alcance, decisiones nuevas del propietario y pruebas |

**Prioridad:** primero las decisiones del propietario, luego el contrato y después esta especificación. Las decisiones nuevas de esta etapa que **amplían o precisan el contrato** se registran como propuestas V1.1 con número (sección «Decisiones del propietario»). El contrato se reescribirá como `_v2` al cerrar la etapa 4.

## Decisiones del propietario para esta etapa (2026-11-16)

1. **Aprobadas** las propuestas V1.1 n.º 14 a 20 y las decisiones A, B y C de la etapa 2.
2. **Autorizado** reformatear `001_esquema_inicial.sql` con la regla de código legible (decisión D).
3. **Precios:**
   - se capturan en el panel, en el catálogo de planes;
   - son opcionales: si un precio falta, el panel pide el monto a mano;
   - moneda única: **MXN**;
   - se registra el monto tal como se cobra. No se calcula IVA ni se emiten facturas.
4. **Mantenimiento de una perpetua:** se compra solo **por un año**, es decir, 12 meses.
5. **Una prueba se puede convertir en cualquier tipo:** suscripción con cualquier periodo, o perpetua, con cualquier plan.
6. **Subir de plan** (más caro) o aumentar cupos:
   - **Suscripción:** el periodo nuevo empieza en la **fecha de pago**, y se descuenta una **bonificación** por los días que faltaban del periodo pagado.
   - **Perpetua:** se paga la **diferencia** entre el precio perpetuo de los dos planes y el cambio aplica de inmediato.
7. **Bajar de plan** (más barato) o reducir cupos: el plan nuevo **empieza cuando vence el tiempo pagado**. Una perpetua no puede bajar de plan.
8. **Cambio que sube unos cupos y baja otros:** los aumentos se aplican como en el punto 6 y las reducciones como en el punto 7, **cupo por cupo**.
9. **Al renovar, la licencia conserva sus números.** Un cambio en el catálogo **nunca reduce** las licencias existentes, ni siquiera al renovar.
   - Los aumentos del catálogo se pueden aplicar a las licencias existentes si el propietario lo elige al guardar (contrato §11).
   - Cómo avisar al cliente de los cambios de catálogo se decidirá después.
   - Este punto **sustituye** la frase del contrato §11 «aplica a licencias nuevas o en su siguiente renovación». Se registra como **propuesta V1.1 n.º 21**.
10. Los puntos 3 a 8 se registran como **propuesta V1.1 n.º 22** (reglas de cambio de plan y bonificación), para incorporarlos al contrato `_v2`.

## Reglas generales

Se aplican las mismas reglas de la etapa 2:

- revisar secretos antes de cada commit;
- datos sintéticos que se borran al terminar;
- textos en español claro;
- código legible con tabuladores;
- migraciones nuevas a partir de la `005`;
- un commit por fase, con todas las pruebas pasando y el resultado anotado en `AVANCE.md`;
- quitar reglas una a una para comprobar que cada regla nueva tiene una prueba que la vigila.

**Toda operación comercial de esta etapa:**

- se hace en una sola transacción (registro del pago, cambio de la licencia, revisión firmada y auditoría), o no se hace;
- es idempotente por su identificador de operación, como las operaciones existentes del panel;
- respeta el bloqueo optimista de la licencia (`row_version`);
- exige el rol que corresponda: superadministrador u operador. Editar el catálogo de planes y precios es solo del superadministrador;
- **firma una revisión nueva** solo cuando cambia algo del contenido firmado, y la entrega por consulta en línea, `renew` sin internet o descarga del `.lic`.

---

## Fase 0 — Verificación y preparación

1. Confirmar el estado de partida:
   - commit `72b9098` o posterior y árbol limpio;
   - 377 pruebas pasando;
   - verificador Go pasando;
   - SHA-256 del contrato sin cambio.
2. **Probar en PHP 8.3.**
   - Instalar PHP 8.3 real en el equipo, por ejemplo con el tap `shivammathur/php` de Homebrew, o usar un contenedor con PHP 8.3 y las extensiones del proyecto.
   - Correr la suite completa contra el MySQL 8.0.46 de pruebas, incluidos el motor de fechas y los archivos del contrato.
   - Registrar en `AVANCE.md` la versión exacta, las extensiones, la versión de los datos de zonas horarias y el resultado.
   - Si algo falla, se corrige en un commit propio antes de la Fase 1 y se informa al propietario.
3. Reformatear `001_esquema_inicial.sql` (decisión 2). `InitialSchemaTest` debe seguir pasando: el esquema que produce no cambia.
4. Registrar en `DECISIONS.md` la aprobación de las propuestas n.º 14 a 20, de A a D y las propuestas nuevas n.º 21 y 22.
5. Listar todas las marcas `PENDIENTE etapa 3` y asignar cada una a la fase de esta etapa que la resuelve.

---

## Fase 1 — Precios y registro de pagos

1. **Precios del catálogo**, todos opcionales, en MXN con dos decimales:
   - suscripción: mensual, trimestral, semestral y anual;
   - perpetua;
   - mantenimiento anual de una perpetua.
2. **Tabla de pagos.** Es un registro **de solo inserción** que forma el historial comercial de la licencia. Cada pago guarda:
   - licencia, fecha de pago (instante UTC capturado en la zona del cliente) y monto cobrado;
   - concepto: `alta`, `renovación`, `conversión`, `cambio de plan` o `mantenimiento`;
   - monto de catálogo sugerido, bonificación aplicada y motivo si el monto se modificó a mano;
   - referencia comercial, periodo o plazo que cubre, administrador y fecha de registro.
3. **Al emitir una licencia** se captura el monto de alta: el panel sugiere el precio del catálogo y permite modificarlo. Las pruebas tienen monto 0.
4. Las licencias emitidas antes de esta etapa no tienen monto de alta. Para la bonificación se usa el precio del catálogo vigente (Fase 5).
5. Se rediseñan o reemplazan `subscription_periods` y `maintenance_purchases`, que hoy no tienen escrituras, para que no haya dos fuentes de verdad. La decisión se documenta en `DECISIONS.md`.
6. Se actualizan permisos (`bin/grants.php`), `SCHEMA.md` y las etiquetas de auditoría.

**Pruebas:**
- Los precios son opcionales y no aceptan valores negativos.
- El pago es de solo inserción: la cuenta de la aplicación no puede actualizarlo ni borrarlo.
- Al emitir se sugiere el precio y el monto se puede modificar.
- La operación es idempotente.
- La auditoría registra cada pago.

---

## Fase 2 — Editor de planes y propagación de aumentos (contrato §11 y decisión 9)

1. **Editor de planes**, solo para el superadministrador. Permite crear un plan y editar su nombre, sus cupos y sus precios.
   - Un plan en uso no se borra: se **archiva** y deja de ofrecerse al emitir.
   - Cada cambio queda en la auditoría con sus valores anteriores y nuevos.
2. **Al guardar cupos nuevos**, el propietario elige entre «solo licencias nuevas» y «también las existentes que siguen este plan».
   - Si elige las existentes, se aplican **solo los aumentos**, cupo por cupo, a las licencias **no personalizadas** de ese plan:
     - suscripciones y pruebas vigentes o en tolerancia;
     - todas las perpetuas no revocadas.
   - Se firma una revisión nueva para cada activación vigente.
   - Las licencias sin activar toman los números nuevos y los firman al activarse.
3. **Las reducciones del catálogo nunca se aplican a licencias existentes** (decisión 9). Si se cambia el nombre de un plan, el nombre firmado se actualiza solo cuando se firme por otro motivo.
4. La propagación de un plan con muchas licencias debe ser segura ante fallos: todas o ninguna, o por lotes reanudables e idempotentes. Se documenta qué ocurre si falla a la mitad.
5. **Resultado visible:** cuántas licencias se actualizaron y cuántas revisiones se firmaron.

**Pruebas:**
- Crear, editar y archivar planes.
- Un plan archivado no se ofrece al emitir.
- Propagación solo a licencias nuevas, y también a existentes.
- Un cambio mixto (sube cajas, baja comanderos): solo sube el aumento en las existentes.
- Las personalizadas, las revocadas y las vencidas sin tolerancia no cambian.
- Las licencias sin activar toman los números nuevos.
- Reintento idéntico.
- Fallo a la mitad.
- Permisos (solo superadministrador).

---

## Fase 3 — Renovación de suscripciones (contrato §5.3)

1. **Regla de la fecha base:**

   ```
   si fecha_pago < expires_at + grace_days × 24 h:  base = expires_at, conserva el día y la hora ancla
   si no:                                            base = fecha_pago, el ancla pasa a ser la del pago
   nuevo expires_at = base + duración elegida (CalendarPeriod::addMonths con el ancla)
   ```

2. **Duración:** se puede cambiar al renovar (mensual, trimestral, semestral o anual). `billing_period` y `updates_until` se actualizan.
3. **Cupos:** la licencia conserva sus números al renovar (decisión 9). Si tiene una reducción programada (Fase 5), se aplica según la Fase 7.
4. **Monto:** el panel sugiere el precio del catálogo para el plan y el periodo. Si la licencia es personalizada, pide el monto a mano.
5. Se firma una revisión nueva. Las computadoras en línea la reciben al consultar; las que no tienen internet, con `renew` o con la descarga del `.lic`.
6. **Aprobar un `renew` sin internet:** el propietario puede registrar ahí mismo la renovación con su pago. Así se resuelve el `PENDIENTE etapa 3` de `OfflineService`.
7. **No se puede renovar:** una perpetua, una licencia revocada, ni una suscripción **sin activar**. Para esta última, la Fase 6 permite editar su periodo inicial.
8. Se resuelve el límite conocido del motor de fechas al encadenar periodos (`DECISIONS.md`, sección 10, Fase 3, decisión 8), o se documenta con una prueba por qué no aplica.

**Pruebas,** con reloj congelado y los cuatro ejemplos del contrato §5.3, más:
- justo en el límite de la tolerancia (exclusivo);
- renovación encadenada con día ancla 31;
- cambio de duración al renovar;
- renovación en la zona horaria del cliente con cambio de horario;
- renovación sin internet;
- rechazo en los casos del punto 7;
- dos operadores renovando a la vez (`row_version`).

---

## Fase 4 — Conversión de prueba a pago (contrato §5.4 y decisión 5)

1. Una prueba se convierte en **cualquier tipo**:
   - suscripción con cualquier periodo;
   - perpetua con 0, 6 o 12 meses de actualizaciones.

   Puede elegir **cualquier plan**, o cupos personalizados.
2. **Prueba activada:**
   - el periodo nuevo empieza en la **fecha de pago** y los días de prueba restantes no se acumulan;
   - en una perpetua, `expires_at` queda vacío y las actualizaciones se cuentan desde la fecha de pago;
   - se firma una revisión nueva para la misma activación, sin reinstalar.
3. **Prueba sin activar:** se cambian sus condiciones y las fechas se fijan al activar, como cualquier licencia.
4. Se puede convertir durante la prueba o después de vencida. No hay bonificación.
5. El pago se registra con el concepto `conversión`.

**Pruebas:**
- Conversión a cada tipo, activada y sin activar.
- Conversión antes y después del vencimiento.
- Cambio de plan durante la conversión.
- La misma activación conserva su identidad y sus revisiones aumentan.

---

## Fase 5 — Cambio de plan con bonificación (decisiones 6, 7 y 8)

**Cómo se clasifica un cambio.** Se compara cupo por cupo con los números vigentes de la licencia:

- **aumento:** al menos un cupo sube;
- **reducción:** al menos un cupo baja;
- **mixto:** los dos.

El precio solo se usa para sugerir montos.

### 5.1 Suscripción vigente: aumento o cambio mixto

1. El **periodo nuevo empieza en la fecha de pago**, con el plan y la duración elegidos. El ancla pasa a ser la del pago.
2. **Bonificación sugerida:**

   ```
   bonificación = monto pagado del periodo vigente × segundos restantes ÷ segundos del periodo
   ```

   - El monto pagado es el del registro de pagos.
   - Si no existe, se usa el precio de catálogo vigente del plan y el periodo anteriores.
   - Se redondea a centavos.
   - El panel muestra los días restantes, el monto pagado, la bonificación y el **monto sugerido a cobrar**: el precio nuevo menos la bonificación, nunca menor que 0.
   - El operador puede modificar el monto cobrado con un motivo. Todo se guarda en el pago.
3. **Aumentos:** se aplican de inmediato en la revisión nueva.
4. **Reducciones** de un cambio mixto: se **programan** para el instante en que vencía el periodo pagado **anterior** (el `expires_at` previo al cambio). Hasta entonces, la licencia conserva el número mayor de ese cupo.
5. Si se paga **después del vencimiento** (en tolerancia o más tarde), no es un cambio de plan sino una **renovación con plan nuevo** (Fase 3): no hay bonificación y no se programan reducciones. El periodo nuevo trae los números nuevos.

### 5.2 Suscripción vigente: solo reducción (o bajar de plan)

1. Se registra como **cambio programado**: el plan y los cupos nuevos entran en vigor en el `expires_at` vigente.
   - No se cobra nada al registrarlo.
   - El cobro llega con la siguiente renovación, que sugiere el precio del plan nuevo.
2. Mientras esté programado, el panel lo muestra y permite cancelarlo.
3. Solo puede haber **un cambio programado** por licencia. Uno nuevo sustituye al anterior y queda en la auditoría.

### 5.3 Perpetua

1. **Subir de plan:** el monto sugerido es la diferencia entre los dos precios perpetuos del catálogo. El cambio aplica de inmediato y no modifica `updates_until`.
2. **Bajar de plan:** no se permite.
3. **Cambio mixto:** no se permite. El panel explica que una perpetua solo puede subir.

### 5.4 Prueba

Cambiar de plan durante la prueba es libre, sin cobro, y no cambia sus fechas.

### 5.5 Licencias personalizadas

Siguen las mismas reglas. Como no tienen precio de catálogo, el panel pide el monto a mano. Elegir un plan del catálogo quita la marca de personalizada.

**Pruebas:**
- Aumento con bonificación exacta, con monto pagado registrado y con precio de catálogo.
- Monto modificado a mano con motivo.
- Bonificación mayor que el precio nuevo: cobro 0.
- Cambio mixto: aumento inmediato y reducción programada al vencimiento anterior.
- Reducción pura programada, cancelada y sustituida.
- Pago después del vencimiento tratado como renovación.
- Perpetua: sube con la diferencia y se rechaza que baje o un cambio mixto.
- Prueba sin cobro.
- Personalizada.
- Concurrencia.

---

## Fase 6 — Mantenimiento de la perpetua y periodo inicial

1. **Mantenimiento** (decisión 4): solo 12 meses.

   ```
   nuevo updates_until = máximo(fecha_pago, updates_until vigente) + 12 meses (motor de fechas, zona del cliente)
   ```

   - El monto sugerido es el precio de mantenimiento anual del plan.
   - Se firma una revisión nueva.
   - Solo aplica a perpetuas activadas y no revocadas. Una perpetua sin activar no lo necesita, porque sus actualizaciones cuentan desde la activación.
2. **Editar el periodo inicial de una licencia sin activar** (propuesta V1.1 n.º 11): permite cambiar el tipo, el periodo o la duración, los meses de actualizaciones, el plan y los cupos. No hay fechas que mover.
3. Se habilitan en el panel las operaciones que estaban como «Disponible próximamente».

**Pruebas:**
- Mantenimiento antes y después de que terminen las actualizaciones.
- Encadenado dos veces.
- Rechazo en suscripción, prueba, perpetua sin activar y revocada.
- Edición del periodo inicial y rechazo si ya está activada.

---

## Fase 7 — Tarea programada de reducciones

1. Comando `bin/apply-scheduled-changes.php`, que se incluye en la tarea diaria de mantenimiento existente y también puede ejecutarse a mano. Aplica los cambios programados cuyo instante ya llegó:
   - cambia el plan y los cupos de la licencia;
   - firma una revisión nueva para la activación vigente;
   - registra la auditoría.
2. **Interacción con la renovación:**
   - Si la licencia **se renovó antes** del instante programado, la renovación firma el periodo extendido **con los números mayores**, y la tarea firma la reducción al llegar el instante.
   - Si **se renueva después** del instante y la tarea todavía no corrió, la renovación aplica la reducción en su misma revisión.
3. Si la licencia **venció sin renovarse**, la reducción se aplica igual, sin efecto práctico, para que una renovación posterior parta del plan nuevo.
4. Es **idempotente** y segura ante dos ejecuciones simultáneas: cada cambio se aplica una sola vez.
5. **Limitación aceptada:** una computadora sin internet recibe la revisión reducida en su siguiente consulta, `renew` o `.lic`. Mientras tanto conserva los números mayores. Se documenta en `SECURITY.md`.

**Pruebas,** con reloj congelado:
- antes, justo en y después del instante;
- dos ejecuciones simultáneas;
- renovación antes y después del instante;
- licencia vencida;
- cambio cancelado que ya no se aplica.

---

## Fase 8 — Archivos del contrato (propuesta V1.1 n.º 6)

En `contracts/v1/fixtures/`, generados y comprobados con `--check`. El verificador de referencia en Go los ejecuta.

1. **Licencias válidas nuevas:**
   - una suscripción renovada (revisión mayor, misma activación);
   - una prueba convertida a suscripción;
   - una prueba convertida a perpetua;
   - una perpetua con mantenimiento;
   - un aumento de plan;
   - una reducción aplicada por la tarea.
2. **Casos de fechas, con entrada y resultado esperado:**
   - los cuatro ejemplos de §5.3 y el límite exacto de la tolerancia;
   - renovación encadenada con día ancla;
   - cambio de duración al renovar;
   - conversión de prueba a pago;
   - mantenimiento antes y después de vencer las actualizaciones.
3. **Casos de bonificación**, con monto pagado, inicio, vencimiento, instante del pago y bonificación esperada al centavo. Son útiles si algún día AD-EL POS muestra montos.
4. `openapi.yaml` no cambia: esta etapa no agrega rutas a la API.

---

## Fase 9 — Panel y cierre

1. **Pantallas mínimas.** Las definitivas son de la etapa 5.
   - Catálogo de planes con precios.
   - En el detalle de la licencia: renovar, convertir, cambiar de plan (con el cálculo de la bonificación visible), mantenimiento, cambio programado con opción de cancelar, e historial de pagos.
   - Todo con fechas en la zona del cliente y textos en español claro.
2. **Playwright:**
   - renovar;
   - convertir una prueba;
   - subir de plan con bonificación;
   - programar y cancelar una reducción;
   - mantenimiento;
   - editar el catálogo y propagar un aumento.
3. **Marcas:** cero `PENDIENTE etapa 3`.
4. **Documentación:** se actualizan `README.md`, `ARCHITECTURE.md`, `SCHEMA.md`, `SECURITY.md`, `OPERATIONS.md` y `TEST_PLAN.md`.
5. **`DECISIONS.md`:** decisiones técnicas y propuestas V1.1 nuevas.
6. **`AVANCE.md`:** cifras por fase, pruebas nuevas y sustituidas, reglas comprobadas, pendientes para las etapas 4 y 5, y comandos para reproducir.
7. **Paquete de despliegue** generado y revisado.

---

## Criterios de aceptación

- [ ] La suite pasa también en **PHP 8.3**, con resultado registrado.
- [ ] Hay precios opcionales en el catálogo y un registro de pagos de solo inserción, con cada operación comercial registrada.
- [ ] El editor de planes propaga solo aumentos y solo cuando se elige. Las reducciones del catálogo nunca alcanzan licencias existentes.
- [ ] La renovación cumple exactamente §5.3, con día ancla, cambio de duración y la vía sin internet.
- [ ] La conversión de prueba funciona a cualquier tipo y plan, desde la fecha de pago.
- [ ] El cambio de plan aplica la bonificación sugerida exacta, los aumentos de inmediato y las reducciones programadas al vencimiento pagado. Una perpetua solo sube, pagando la diferencia.
- [ ] El mantenimiento es anual y encadenable. El periodo inicial se puede editar antes de activar.
- [ ] La tarea programada es idempotente y segura ante concurrencia.
- [ ] Los archivos de prueba nuevos pasan el verificador Go.
- [ ] El contenido firmado no cambia de formato y el contrato no se modificó.
- [ ] Cero `PENDIENTE etapa 3`. Pasan la suite, Playwright, la revisión de secretos, el formato y el paquete.

## Fuera de esta etapa

- **Etapa 4:** transferencia por autoservicio, `TRANSFER_LIMIT`, `transfer_available`, confirmación y alertas. Al cerrarla se entrega el contrato `_v2` con las propuestas V1.1 n.º 1 a 22 y las que surjan.
- **Etapa 5:**
  - pantallas definitivas y constancias;
  - `limit_req` de Nginx;
  - despliegue en `licensing.sicrat.com`.
- Avisos a los clientes por cambios de catálogo (decisión 9).
- Pasarela de pagos, facturación e impuestos.
- Cambios en AD-EL POS o en AIDEX.
