-- Índice de los cobros del resumen del panel (etapa 5, Fase 2).
-- El resumen suma lo cobrado en el mes en curso y en el anterior, por concepto: un rango sobre
-- «paid_at» de todas las licencias. El único índice con «paid_at» empieza por «license_id», así que
-- esa suma recorría la tabla completa. Este índice cubre la consulta (fecha, concepto y monto) y la
-- tabla es de solo inserción, así que mantenerlo cuesta poco.
-- Este archivo no debe contener punto y coma al final de una línea de comentario.
ALTER TABLE license_payments
	ADD INDEX ix_payment_paid_concept(paid_at,concept,amount);
