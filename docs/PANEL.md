# Panel de licencias de AD-EL POS: diseño definitivo

Diseño del panel para las fases 2 y 3 de la etapa 5. Parte del estilo actual (Bootstrap servido localmente, barra lateral oscura y marca de AD-EL POS) y lo ordena alrededor de lo que el propietario hace a diario. Las decisiones que no estaban en la especificación también quedan en `DECISIONS.md`, sección 18.

**Qué hace el propietario cada día**, en orden de frecuencia:

1. **Cobrar a tiempo:** ver qué suscripciones y pruebas vencen pronto, renovarlas o convertirlas.
2. **Atender lo que necesita una decisión:** alertas de transferencias, solicitudes sin internet pendientes y licencias en tolerancia.
3. **Emitir y entregar:** dar de alta un cliente, emitir su licencia y entregarle el código o el archivo `.lic` con su constancia.
4. **Consultar:** buscar una licencia por cliente, por computadora o por referencia, y ver su historia completa.

Cada pantalla se diseña para que esas tareas tomen el menor número de pasos posible, con la información junta y en español claro.

---

## 1. Inventario del panel al cerrar la etapa 4

Capturas de escritorio (1440 px) y de teléfono (390 px) de cada pantalla, con los datos sintéticos de las pruebas, en `var/screenshots/inventario-etapa-4/` (no se versiona). Ninguna pantalla desborda a lo ancho a 390 px: las tablas se desplazan dentro de su panel.

| Pantalla | Para qué la usa el propietario | Qué cuesta trabajo hoy | Qué cambia |
| --- | --- | --- | --- |
| **Resumen** (`/admin`) | Saber qué hacer hoy | Las tarjetas cuentan licencias emitidas, clientes y perpetuas: datos que no piden ninguna acción. «Vencen en 30 días» no distingue suscripciones de pruebas ni lleva a renovar. No hay cobros. «Licencias recientes» repite la lista. El aviso de la marca ocupa espacio sin informar. El texto «No hay alertas sin revisar» queda sin margen | Tarjetas de lo que pide acción (activas por tipo, en tolerancia, vencidas recientes, alertas), «Por vencer» con filtro de 7, 15 y 30 días y acceso directo a renovar o convertir, cobros del mes en curso y del anterior, alertas y actividad recientes |
| **Licencias** (`/admin/licenses`) | Encontrar una licencia | Solo busca por cliente o por identificador. El estado dice «Emitida» o «Revocada», no si está vigente, en tolerancia o vencida. No muestra plan, cupos ni computadora. Filtra por producto, que siempre es AD-EL POS. No se puede ordenar. En el teléfono, el vencimiento y el enlace al detalle quedan fuera de la vista | Búsqueda por cliente, razón social, licencia, computadora y referencia; filtros por tipo, estado real, plan, personalizada y transferencia pendiente; orden por vencimiento o por alta; columnas con estado de color, plan y cupos, vencimiento en la zona del cliente y computadora activa; filas como tarjetas en el teléfono |
| **Detalle de la licencia** | Ver todo de una licencia y operar | Una sola página de 11 bloques (3,500 px de alto en escritorio). El estado vuelve a decir «Emitida». Las operaciones están repartidas: renovar, convertir y cambiar de plan en la columna derecha; revocar y la clave al final; recuperar plaza dentro de «Sin internet»; liberar el contador dentro de «Transferencias». El historial y los pagos quedan al fondo | Cabecera fija con cliente, estado, tipo, plan, cupos, fechas y computadora activa; una sola barra de acciones con solo las que aplican; siete secciones navegables (Resumen, Pagos, Computadoras y transferencias, Revisiones firmadas, Sin internet, Constancias, Historial) |
| **Renovar, convertir, cambiar de plan, mantenimiento, periodo inicial** | Registrar una operación con su pago | Los datos de la operación se eligen en el detalle y la vista previa no deja cambiarlos (salvo en la renovación). El título de un cambio de plan es técnico («Aumento», «Reducción programada»). El resultado está en una lista de datos sin un resumen de qué cambia | Cada operación en su página: datos arriba (se pueden ajustar), resultado claro (qué cambia, desde cuándo, monto sugerido y desglose de la bonificación) y confirmación al lado |
| **Emitir licencia** | Dar de alta una licencia | Funciona bien. Pide elegir el producto, que siempre es AD-EL POS. No se puede llegar desde un cliente con el cliente ya elegido | Se conserva; el producto se preselecciona y se puede abrir desde el detalle del cliente |
| **Clientes** | Encontrar un cliente y ver lo que tiene | La lista no dice cuántas licencias tiene cada cliente. El «detalle» es solo el formulario de edición: no muestra sus licencias ni sus pagos | Lista con búsqueda (nombre, razón social, RFC, correo), número de licencias y zona horaria; **detalle del cliente** con sus datos, todas sus licencias con su estado y su historial de pagos; la edición en su propia página |
| **Planes** | Consultar y ajustar el catálogo | La tabla junta cuatro precios en una celda. Al guardar no se sabe cuántas licencias cambiarían | Tabla con un precio por columna; formulario por bloques y la propagación explicada con un ejemplo y el número de licencias que cambiarían, calculado antes de guardar |
| **Alertas** | Atender transferencias bloqueadas o frecuentes | Funciona. Los filtros no tienen etiqueta visible. Las fechas van en la zona del panel | Diseño nuevo de la tarjeta de alerta, filtros con etiqueta, fechas en la zona del cliente; botón de la barra superior con el diseño nuevo y el contador también en la navegación |
| **Solicitudes sin internet** | Importar, revisar y aprobar `.licreq` | La lista no dice de qué cliente es una solicitud pendiente de activación. La revisión es larga y pone la identidad técnica antes de lo que hay que decidir | Lista con acción, cliente, estado y fecha; la revisión empieza con «qué pasará al aprobar» y la licencia elegida, y deja la identidad técnica como dato de apoyo |
| **Productos** | Casi nunca: solo existe AD-EL POS | — | Se conserva, con el diseño nuevo, en el grupo «Catálogo» |
| **Auditoría** | Revisar quién hizo qué | El detalle es JSON. La búsqueda solo usa el nombre técnico de la operación | Se conserva el detalle; búsqueda también por la etiqueta en español y filas como tarjetas en el teléfono |
| **Administradores** y **Mi seguridad** | Cuentas, permisos, contraseña y dispositivos de confianza | Funcionan | Se conservan sus reglas con el diseño nuevo |
| **Acceso, verificación en dos pasos, códigos de recuperación, confiar en el dispositivo** | Entrar al panel | Funcionan | Se conservan |
| **Página de error** | Saber qué pasó | Un error de validación en un formulario lleva a esta página y **se pierde lo escrito** | Un error de validación vuelve al formulario con el mensaje y lo escrito; la página de error queda para lo demás |
| **Descarga sensible** (`.lic`, constancias de entrega) | Confirmar la identidad | Funciona | Se conserva, con el nombre de lo que se descarga |

---

## 2. Navegación

**Barra lateral** (oscura, 248 px en escritorio; menú desplegable en el teléfono), en tres grupos con íconos de trazo propios (SVG en línea, sin fuentes externas):

| Grupo | Entradas |
| --- | --- |
| **Operación** | Resumen · Licencias · Clientes · Alertas (con el número sin revisar) · Sin internet (con el número de pendientes) |
| **Catálogo** | Planes · Productos |
| **Control** | Auditoría · Administradores (solo superadministrador) · Mi seguridad |

**Barra superior:** el título de la pantalla con su ruta («Licencias / Cocina Regional»), el botón **＋ Emitir licencia** para quien puede escribir, el botón de notificaciones con el número de alertas sin revisar y su lista, y la cuenta con «Salir».

**Pie:** la zona horaria del panel (`America/Monterrey`), con la que se muestran las fechas que no son de un cliente.

---

## 3. Pantallas

### 3.1 Resumen

1. **Encabezado:** «Resumen», la fecha de hoy en la zona del panel y accesos rápidos: Emitir licencia, Nuevo cliente, Importar `.licreq`.
2. **Tarjetas de conteo** (cada una lleva a la lista filtrada):
   - **Licencias activas por tipo:** suscripciones, perpetuas y pruebas en estado vigente, con el número de licencias sin activar como nota;
   - **En tolerancia** (ámbar);
   - **Vencidas en los últimos 30 días** (rojo);
   - **Alertas sin revisar.**
3. **Por vencer:** suscripciones y pruebas que vencen en los próximos **7, 15 o 30 días** (filtro de tres botones; 30 por omisión). Columnas: cliente, plan, tipo, fecha de vencimiento en la zona del cliente con «hoy», «mañana» o «en N días», y el acceso directo **Renovar** (suscripción) o **Convertir** (prueba). Sin acceso para la cuenta de consulta.
4. **Cobros del periodo:** total cobrado en el mes en curso y en el anterior, en MXN, y el número de operaciones por concepto (alta, renovación, conversión, cambio de plan, mantenimiento). Con la nota «Cifras informativas, no contables» y la zona con la que se cortan los meses.
5. **Alertas recientes** sin revisar y **actividad reciente**, con sus etiquetas en español.

### 3.2 Licencias

- **Búsqueda única** por cliente (nombre comercial o razón social), `license_id`, `server_id` (de cualquier computadora que la haya tenido) y referencia comercial.
- **Filtros:** tipo; estado (vigente, en tolerancia, vencida, revocada, sin activar); plan (también los archivados); «solo personalizadas»; «con transferencia pendiente».
- **Orden:** alta más reciente (por omisión), alta más antigua, vencimiento más próximo y vencimiento más lejano. Las licencias sin vencimiento (perpetuas y sin activar) van al final cuando se ordena por vencimiento.
- **Columnas:** cliente (con su razón social), tipo y periodo, plan y cupos resumidos («Plan elemental · 3 cajas · 3 comanderos · 3 KDS», con la insignia «Personalizada»), estado con su color, vencimiento en la zona del cliente y computadora activa (el `server_id` abreviado, completo al pasar el cursor).
- **Paginación** de 20 en 20, con el total y los filtros conservados en la URL.
- «Limpiar filtros» cuando hay alguno activo.

### 3.3 Detalle de la licencia

**Cabecera** (siempre visible, en todas las secciones):

- el cliente, con enlace a su detalle, y el estado con su color;
- tipo y periodo; plan y cupos (con «Personalizada» si aplica);
- vencimiento y fin de actualizaciones, en la zona del cliente;
- la computadora activa (o «Ninguna»), y avisos breves si hay un cambio programado, una transferencia pendiente o alertas abiertas.

**Barra de acciones**, en un solo lugar, con **solo las que aplican** a la licencia y al rol:

| Acción | Cuándo aparece | Rol |
| --- | --- | --- |
| Renovar | Suscripción activada, no revocada | Superadministrador y operador |
| Convertir | Prueba no revocada | Superadministrador y operador |
| Cambiar de plan | Activada o prueba, no revocada | Superadministrador y operador |
| Mantenimiento | Perpetua activada, no revocada | Superadministrador y operador |
| Editar periodo inicial | Sin activar, no revocada | Superadministrador y operador |
| Descargar | Archivo `.lic` de la revisión vigente (permiso «descargar archivos sin internet») y constancias | Según permisos |
| Más: revelar código, reemplazar clave | No revocada | Revelar: permiso «revelar códigos»; reemplazar: superadministrador y operador |
| Revocar | No revocada | Superadministrador |

La cuenta de consulta no ve acciones. Cada acción lleva a su página; las sensibles (revocar, reemplazar clave, revelar código, liberar el contador, recuperar plaza y la transferencia forzada) piden una **confirmación explícita**: qué va a pasar, una casilla «Entiendo…», el motivo cuando aplica, y contraseña y código de verificación.

**Secciones** (pestañas en escritorio, menú desplegable en el teléfono; cada una tiene su URL, `?section=…`, y solo consulta lo que muestra):

| Sección | Contenido |
| --- | --- |
| Resumen | Condiciones (tipo, periodo, tolerancia y qué pasa al vencer), plan y cupos, fechas (inicio del periodo, vencimiento, fin de la tolerancia, actualizaciones), cambio programado con su cancelación, datos comerciales (referencia y nota, con su historial), módulos e identificador |
| Pagos | Historial de pagos: fecha, concepto con su plazo y plan, monto cobrado, sugerido, bonificación, motivo del cambio de monto y quién lo registró |
| Computadoras y transferencias | Activación vigente, historial de activaciones, transferencias con su vía y su confirmación, la pendiente con su liberación manual, recuperar plaza y las alertas de la licencia |
| Revisiones firmadas | Cada revisión con su número, estado y motivo, fecha, computadora y descarga del `.lic` (paginadas de 50 en 50) |
| Sin internet | Solicitudes de la licencia, su acción, su decisión y su fecha |
| Constancias | Generar constancias y descargar las generadas, con su folio y su revisión |
| Historial | Cambios comerciales (`license_changes`) y la auditoría de la licencia |

### 3.4 Operaciones comerciales

Una página por operación, con el mismo esquema:

1. **Datos de la operación** (arriba): lo que se puede elegir (duración, fecha de pago, tipo, plan y cupos). La renovación y el mantenimiento muestran el cálculo de inmediato con sus valores por omisión (la duración vigente y la fecha de pago actual); la conversión y el cambio de plan piden primero el destino.
2. **Resultado** (bloque destacado): **qué cambia** (antes → después), **desde cuándo**, el **monto sugerido** y, en un cambio de plan, el **desglose de la bonificación** por periodo pagado.
3. **Confirmación** (al lado en escritorio, debajo en el teléfono): monto cobrado, motivo si el monto es otro, referencia y nota, y el botón con el verbo de la operación.

El título dice la operación en palabras del propietario: «Cambio de plan: subida con bonificación», «Cambio de plan: reducción programada», «Renovación con plan nuevo».

### 3.5 Clientes

- **Lista:** búsqueda por nombre comercial, razón social, RFC o correo; columnas cliente (con razón social), RFC, licencias (cuántas y cuántas vigentes), zona horaria y estado.
- **Detalle del cliente:** sus datos (razón social, RFC, contacto, zona horaria y estado) con «Editar datos»; **todas sus licencias** con su estado, plan, vencimiento y computadora; **su historial de pagos** de todas sus licencias; y **Emitir licencia para este cliente**, que abre el formulario con el cliente elegido.
- **Edición:** el formulario actual, en su propia página.

### 3.6 Planes

- **Catálogo:** una fila por plan, con sus cupos, un precio por columna (mensual, trimestral, semestral, anual, perpetua y mantenimiento), cuántas licencias lo siguen y su estado.
- **Formulario** por bloques: nombre, cupos, precios y, al editar, **«¿A qué licencias aplican los cupos nuevos?»** con un ejemplo («Si subes las cajas de 3 a 4, una licencia de este plan con 3 cajas pasa a 4; una personalizada no cambia») y **el número de licencias que cambiarían y de revisiones que se firmarían**, que se recalcula al escribir los cupos, antes de guardar.

### 3.7 Alertas, sin internet, seguridad y administradores

- **Alertas:** filtros con etiqueta (estado y tipo), tarjeta de alerta con su tipo, el cliente, lo que pasó en una frase y las fechas en la zona del cliente, y «Marcar como revisada».
- **Sin internet:** importar arriba; lista con acción, cliente, estado y fecha; la revisión empieza por «qué pasará al aprobar» y la licencia elegida, y deja la identidad técnica de la computadora como dato de apoyo.
- **Seguridad y administradores:** mismas reglas (verificación en dos pasos, nueva autenticación, permisos), con los componentes nuevos.

---

## 4. Componentes reutilizables

| Componente | Uso | Reglas |
| --- | --- | --- |
| **Tarjeta** (`.panel`) | Agrupa un bloque | Fondo blanco, borde de 1 px, radio de 12 px; título `h2` de 17 px y una línea de ayuda opcional |
| **Tarjeta de conteo** (`.metric-card`) | Cifras del resumen | Etiqueta, número grande y una nota; toda la tarjeta es un enlace a la lista filtrada; borde superior del color del estado que cuenta |
| **Tabla** (`.table` + `.table-stack`) | Listas | Encabezados en mayúsculas pequeñas; en el teléfono cada fila se vuelve una tarjeta con la etiqueta de cada dato (`data-label`) |
| **Filtros** (`.filter-bar`) | Encima de cada lista | Búsqueda primero, después los filtros con etiqueta visible o accesible, «Filtrar» y «Limpiar filtros» |
| **Insignia de estado** (`.badge-state`) | Estado de una licencia, una activación, una revisión, una solicitud o una alerta | Punto de color más texto: el color nunca va solo |
| **Insignia neutra** (`.tag`) | Tipo, plan personalizado, «Solo consulta» | Gris claro |
| **Barra de acciones** (`.action-bar`) | Detalle de la licencia | Botón principal (la operación más frecuente) y secundarios; en el teléfono se reparten en dos columnas |
| **Lista de datos** (`.detail-grid`) | Datos de una licencia o de una operación | Etiqueta pequeña arriba y valor abajo |
| **Antes y después** (`.change-list`) | Resultado de una operación | Dato, valor anterior tachado suave y valor nuevo destacado |
| **Confirmación explícita** (`.confirm-panel`) | Operaciones sensibles | Qué pasará, casilla «Entiendo…», motivo y nueva autenticación; botón rojo para lo irreversible |
| **Aviso** (`.alert`) | Éxito, error, advertencia | Éxito en verde con `role="status"`; error en rojo con `role="alert"` y qué hacer; advertencia en ámbar |
| **Estado vacío** (`.empty-state`) | Lista o sección sin datos | Qué significa y qué hacer, con el botón de la acción cuando el rol la permite |
| **Pestañas** (`.section-tabs`) | Secciones del detalle | Enlaces con `aria-current`; en el teléfono, una lista desplegable |

---

## 5. Colores de estado

El color siempre va acompañado de texto. Contraste de al menos 4.5:1 entre el texto de la insignia y su fondo.

| Estado | Color | Texto | Fondo |
| --- | --- | --- | --- |
| **Vigente** (suscripción o perpetua) | Verde | `#14653a` | `#e3f5ea` |
| **Prueba vigente** | Azul | `#1d4fb8` | `#e5eeff` |
| **En tolerancia** | Ámbar | `#8a4b00` | `#fff1d6` |
| **Vencida** | Rojo | `#a1251b` | `#fde8e6` |
| **Revocada** | Rojo oscuro | `#7a1b14` | `#f6dedb` |
| **Transferida, liberada, cerrada, rechazada, revisada** | Gris | `#475467` | `#eef1f5` |
| **Sin activar** | Gris azulado, con borde | `#3d4a5c` | `#ffffff` |
| **Pendiente** (solicitud, transferencia), **alerta sin revisar** | Ámbar | `#8a4b00` | `#fff1d6` |

**Cómo se calcula el estado de una licencia** (mismas reglas que el contrato, §5.6, con la hora del servidor):

| Estado | Condición |
| --- | --- |
| Revocada | `commercial_status = revoked` |
| Sin activar | No revocada y sin fechas (`period_starts_at` vacío) |
| Vigente | Perpetua activada, o `ahora < expires_at` |
| En tolerancia | `expires_at ≤ ahora < expires_at + grace_days × 86 400 s` |
| Vencida | `ahora ≥ expires_at + grace_days × 86 400 s` |

**«Por vencer en N días»:** licencias no revocadas, con vencimiento todavía no cumplido, cuya **fecha** de vencimiento en la zona del cliente cae entre hoy y hoy + N días (también en la zona del cliente). Una licencia que vence hoy a las 23:00 aparece en «7 días» con «vence hoy».

**«Vencidas en los últimos 30 días»:** licencias vencidas cuyo fin de tolerancia cae, en la zona del cliente, entre hoy − 30 días y hoy.

---

## 6. Tipografía y espaciado

- **Fuente:** la del sistema (`-apple-system`, `Segoe UI`, …), sin fuentes externas. Identificadores (`license_id`, `server_id`, folios, códigos) en monoespaciada de 12 px.
- **Tamaños:** cuerpo 14 px; texto secundario 12–13 px; `h1` de 26 a 30 px; `h2` 17 px; `h3` 15 px; números de las tarjetas 30 px.
- **Pesos:** 400 el cuerpo, 600 etiquetas y botones, 650 los títulos.
- **Espaciado:** escala de 4, 8, 12, 16, 24 y 32 px. Tarjetas con 24 px de relleno (16 px en el teléfono); 24 px entre tarjetas.
- **Ancho de lectura:** los textos de ayuda no pasan de 72 caracteres por línea.
- **Controles:** 44 px de alto mínimo (objetivo táctil); foco visible con un anillo azul de 3 px.

---

## 7. Reglas de texto

- **Español claro y directo**, de tú. Frases cortas. Sin palabras en inglés ni nombres técnicos cuando hay uno en español: «archivo firmado» y no «JWS»; «llave» y no «kid» (el identificador va como dato secundario).
- **Botones con el verbo de lo que hacen:** «Registrar renovación», «Programar el cambio», «Revocar la licencia». Nada de «Enviar» o «Aceptar».
- **Estados con su nombre de la tabla de colores**, siempre igual en todas las pantallas.
- **Fechas** siempre con su zona: las de una licencia, sus pagos, sus computadoras y sus revisiones, en la **zona del cliente** («17/12/2026 · 10:00 America/Tijuana»); las demás (auditoría, sesiones, dispositivos), en la del panel. En las listas, la zona va en una línea secundaria.
- **Montos** con el formato `$1,500.00 MXN`.
- **Mensajes de éxito:** qué se hizo y qué sigue («Renovación registrada. La computadora activa recibe la revisión nueva al consultar.»).
- **Mensajes de error:** qué pasó y qué hacer, sin culpar («La fecha de pago no puede ser futura. Elige una fecha de hoy o anterior.»). Un error de validación conserva lo escrito.
- **Estados vacíos:** qué significa y el siguiente paso («Este cliente todavía no tiene licencias. Emite la primera.»).

---

## 8. Accesibilidad, teléfono y rendimiento

- **Etiquetas:** todo campo tiene un `<label>` (visible, o solo para lectores de pantalla en los filtros compactos). Los íconos son decorativos (`aria-hidden`) y los botones de solo ícono llevan nombre accesible.
- **Teclado:** enlace «Saltar al contenido», orden de tabulación natural, foco visible, menús de Bootstrap navegables con flechas y `Escape` que cierra el menú lateral.
- **Contraste:** texto normal de al menos 4.5:1; los grises de ayuda no bajan de `#5b6678` sobre blanco.
- **Teléfono (390 px):** sin desbordamiento horizontal de la página; tablas como tarjetas; barra de acciones en dos columnas; pestañas del detalle como lista desplegable; formularios a una columna.
- **Rendimiento:** listas paginadas de 20 en 20; el detalle consulta solo la sección abierta; las consultas del resumen usan rangos sobre columnas indexadas y se filtran por la zona del cliente en PHP sobre un conjunto acotado. Un índice nuevo solo entra en una migración (`009` o posterior) si la consulta lo necesita, con su justificación.
- **Sin dependencias nuevas de producción:** Bootstrap sigue servido localmente; nada de CDN ni servicios externos.

---

## 9. Pruebas

- **Playwright** recorre cada pantalla en escritorio y en teléfono: búsqueda y filtros, «Por vencer», cobros, cada sección del detalle, las operaciones con vista previa, el detalle del cliente y el conteo del catálogo antes de propagar; y comprueba que nada desborda a 390 px.
- **PHPUnit** para las consultas nuevas (conteos, por vencer, cobros, búsqueda y filtros, con los límites exactos en la zona del cliente) y para la visibilidad de las acciones según el rol y el tipo de licencia.
- Las pruebas del panel que ya existían se conservan con sus selectores actualizados.
