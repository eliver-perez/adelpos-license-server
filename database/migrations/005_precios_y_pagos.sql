-- Precios del catálogo y registro de pagos (etapa 3, Fase 1).
-- Los precios son opcionales, en MXN con dos decimales y nunca negativos. Si falta uno, el panel
-- pide el monto a mano. No se calcula IVA ni se emiten facturas: se registra el monto cobrado.
-- «license_payments» es el historial comercial de la licencia, de solo inserción. Reemplaza a
-- «subscription_periods» y «maintenance_purchases», que no tenían escrituras: así no hay dos
-- fuentes de verdad para lo que se cobró y el plazo que cubre.
-- En una restricción CHECK un valor NULL cuenta como válido, por eso cada comparación de una
-- columna que admite NULL va precedida de IS NULL o IS NOT NULL.
-- Este archivo no debe contener punto y coma al final de una línea de comentario.
ALTER TABLE plans
	ADD COLUMN price_monthly DECIMAL(10,2) NULL,
	ADD COLUMN price_quarterly DECIMAL(10,2) NULL,
	ADD COLUMN price_semiannual DECIMAL(10,2) NULL,
	ADD COLUMN price_annual DECIMAL(10,2) NULL,
	ADD COLUMN price_perpetual DECIMAL(10,2) NULL,
	ADD COLUMN price_maintenance DECIMAL(10,2) NULL,
	ADD CONSTRAINT chk_plan_prices CHECK (
		(price_monthly IS NULL OR price_monthly>=0)
		AND (price_quarterly IS NULL OR price_quarterly>=0)
		AND (price_semiannual IS NULL OR price_semiannual>=0)
		AND (price_annual IS NULL OR price_annual>=0)
		AND (price_perpetual IS NULL OR price_perpetual>=0)
		AND (price_maintenance IS NULL OR price_maintenance>=0)
	);
DROP TABLE subscription_periods;
DROP TABLE maintenance_purchases;
-- concept: issue (alta), renewal (renovación), conversion (conversión), plan_change (cambio de
-- plan) o maintenance (mantenimiento).
-- term: el plazo que cubre. Un periodo de suscripción, «perpetual», «maintenance» (12 meses de
-- actualizaciones de una perpetua) o «trial», que no tiene costo.
-- catalog_amount es el precio del catálogo que se sugirió, credit_amount la bonificación que se
-- descontó y suggested_amount lo que quedaba por cobrar, nunca menos de 0. Si el monto cobrado
-- es otro, override_reason guarda el motivo.
-- covers_from y covers_until son el periodo que cubre el pago. Quedan vacíos si sus fechas se
-- fijan al activar o si el pago no cambia el periodo.
CREATE TABLE license_payments (
	payment_id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	operation_id BINARY(16) NOT NULL,
	concept VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	term VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	plan_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	paid_at DATETIME(6) NOT NULL,
	currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'MXN',
	amount DECIMAL(10,2) NOT NULL,
	catalog_amount DECIMAL(10,2) NULL,
	credit_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
	suggested_amount DECIMAL(10,2) NULL,
	override_reason VARCHAR(500) NULL,
	reference VARCHAR(190) NOT NULL DEFAULT '',
	covers_from DATETIME(6) NULL,
	covers_until DATETIME(6) NULL,
	admin_id BINARY(16) NOT NULL,
	created_at DATETIME(6) NOT NULL,
	UNIQUE KEY uq_payment_operation(operation_id),
	UNIQUE KEY uq_payment_license(payment_id,license_id),
	INDEX ix_payment_license(license_id,paid_at),
	CONSTRAINT fk_payment_license FOREIGN KEY (license_id) REFERENCES licenses(license_id),
	CONSTRAINT fk_payment_operation FOREIGN KEY (operation_id) REFERENCES admin_operations(operation_id),
	CONSTRAINT fk_payment_plan FOREIGN KEY (plan_id) REFERENCES plans(plan_id),
	CONSTRAINT fk_payment_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id),
	CONSTRAINT chk_payment_concept CHECK (
		concept IN ('issue','renewal','conversion','plan_change','maintenance')
	),
	CONSTRAINT chk_payment_term CHECK (
		(
			concept='maintenance'
			AND term='maintenance'
		)
		OR (
			concept<>'maintenance'
			AND term IN ('monthly','quarterly','semiannual','annual','perpetual','trial')
		)
	),
	CONSTRAINT chk_payment_trial CHECK (
		term<>'trial'
		OR (
			concept='issue'
			AND amount=0
		)
	),
	CONSTRAINT chk_payment_currency CHECK (currency='MXN'),
	CONSTRAINT chk_payment_amounts CHECK (
		amount>=0
		AND credit_amount>=0
		AND (catalog_amount IS NULL OR catalog_amount>=0)
	),
	CONSTRAINT chk_payment_suggestion CHECK (
		(
			catalog_amount IS NULL
			AND suggested_amount IS NULL
		)
		OR (
			catalog_amount IS NOT NULL
			AND suggested_amount IS NOT NULL
			AND suggested_amount=GREATEST(catalog_amount-credit_amount,0)
		)
	),
	CONSTRAINT chk_payment_override CHECK (
		(
			override_reason IS NULL
			AND (suggested_amount IS NULL OR amount=suggested_amount)
		)
		OR (
			override_reason IS NOT NULL
			AND override_reason<>''
			AND suggested_amount IS NOT NULL
			AND amount<>suggested_amount
		)
	),
	CONSTRAINT chk_payment_covers CHECK (
		(
			covers_from IS NULL
			AND covers_until IS NULL
		)
		OR (
			covers_from IS NOT NULL
			AND covers_until IS NOT NULL
			AND covers_until>covers_from
		)
	)
) ENGINE=InnoDB;
-- El pago que cubre el periodo vigente: con su monto se calcula la bonificación de un cambio de
-- plan. Vacío en una licencia emitida antes de esta etapa: se usa entonces el precio del catálogo.
ALTER TABLE licenses
	ADD COLUMN period_payment_id BINARY(16) NULL,
	ADD CONSTRAINT fk_license_period_payment
		FOREIGN KEY (period_payment_id,license_id) REFERENCES license_payments(payment_id,license_id);
