-- Modelo de datos del contrato V1.0: zona horaria del cliente, planes, tipos de licencia,
-- cupos, periodo vigente, identidad «server_id» y datos de las revisiones firmadas.
-- Lo heredado que el contenido firmado todavía usa (la huella del equipo, license_limits y las
-- fechas maintenance_until y entitled_release_until) se retira en una migración posterior.
-- Requiere una base sin licencias: las columnas nuevas de «licenses» no admiten filas anteriores.
-- En una restricción CHECK un valor NULL cuenta como válido, por eso cada comparación de una
-- columna que admite NULL va precedida de IS NULL o IS NOT NULL.
-- Este archivo no debe contener punto y coma al final de una línea de comentario.
ALTER TABLE customers
	ADD COLUMN time_zone VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'America/Monterrey',
	ADD CONSTRAINT chk_customer_time_zone CHECK (time_zone<>'');
CREATE TABLE plans (
	plan_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
	display_name VARCHAR(190) NOT NULL,
	cash_registers SMALLINT NOT NULL,
	order_takers SMALLINT NOT NULL,
	kitchen_displays SMALLINT NOT NULL,
	created_at DATETIME(6) NOT NULL,
	updated_at DATETIME(6) NOT NULL,
	CONSTRAINT chk_plan_name CHECK (display_name<>''),
	CONSTRAINT chk_plan_limits CHECK (
		cash_registers BETWEEN 0 AND 999
		AND order_takers BETWEEN 0 AND 999
		AND kitchen_displays BETWEEN 0 AND 999
	)
) ENGINE=InnoDB;
INSERT INTO plans(plan_id,display_name,cash_registers,order_takers,kitchen_displays,created_at,updated_at) VALUES
	('basico',_utf8mb4'Plan básico',1,0,0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)),
	('elemental',_utf8mb4'Plan elemental',3,3,3,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)),
	('premium',_utf8mb4'Plan premium',5,10,5,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6));
ALTER TABLE licenses DROP CHECK chk_license_terms;
ALTER TABLE licenses MODIFY entitled_release_until DATETIME(6) NULL;
ALTER TABLE licenses
	ADD COLUMN billing_period VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
	ADD COLUMN trial_duration VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
	ADD COLUMN updates_months TINYINT UNSIGNED NULL,
	ADD COLUMN plan_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	ADD COLUMN plan_name VARCHAR(190) NOT NULL,
	ADD COLUMN plan_custom BOOLEAN NOT NULL DEFAULT 0,
	ADD COLUMN cash_registers SMALLINT NOT NULL,
	ADD COLUMN order_takers SMALLINT NOT NULL,
	ADD COLUMN kitchen_displays SMALLINT NOT NULL,
	ADD COLUMN period_starts_at DATETIME(6) NULL,
	ADD COLUMN anchor_day TINYINT UNSIGNED NULL,
	ADD COLUMN anchor_local_time TIME(6) NULL,
	ADD COLUMN updates_until DATETIME(6) NULL,
	ADD CONSTRAINT fk_license_plan FOREIGN KEY (plan_id) REFERENCES plans(plan_id),
	-- Cada rama exige uno de los tres tipos: no hace falta otra restricción para el tipo.
	ADD CONSTRAINT chk_license_terms CHECK (
		(
			license_type='perpetual'
			AND billing_period IS NULL
			AND trial_duration IS NULL
			AND updates_months IS NOT NULL
			AND updates_months IN (0,6,12)
			AND grace_days=0
		)
		OR (
			license_type='subscription'
			AND billing_period IS NOT NULL
			AND billing_period IN ('monthly','quarterly','semiannual','annual')
			AND trial_duration IS NULL
			AND updates_months IS NULL
			AND grace_days=5
		)
		OR (
			license_type='trial'
			AND billing_period IS NULL
			AND trial_duration IS NOT NULL
			AND trial_duration IN ('15d','1m','3m')
			AND updates_months IS NULL
			AND grace_days=0
		)
	),
	ADD CONSTRAINT chk_license_plan CHECK (plan_name<>'' AND plan_custom IN (0,1)),
	ADD CONSTRAINT chk_license_limits CHECK (
		cash_registers BETWEEN 0 AND 999
		AND order_takers BETWEEN 0 AND 999
		AND kitchen_displays BETWEEN 0 AND 999
	),
	ADD CONSTRAINT chk_license_period CHECK (
		(
			period_starts_at IS NULL
			AND expires_at IS NULL
			AND anchor_day IS NULL
			AND anchor_local_time IS NULL
			AND updates_until IS NULL
		)
		OR (
			period_starts_at IS NOT NULL
			AND anchor_day IS NOT NULL
			AND anchor_day BETWEEN 1 AND 31
			AND anchor_local_time IS NOT NULL
			AND anchor_local_time>='00:00:00'
			AND anchor_local_time<'24:00:00'
			AND updates_until IS NOT NULL
			AND (
				(
					license_type='perpetual'
					AND expires_at IS NULL
					AND updates_until>=period_starts_at
				)
				OR (
					license_type<>'perpetual'
					AND expires_at IS NOT NULL
					AND expires_at>period_starts_at
					AND updates_until=expires_at
				)
			)
		)
	);
ALTER TABLE activations RENAME COLUMN installation_id TO server_id;
ALTER TABLE activations ADD COLUMN app_version VARCHAR(100) NULL;
ALTER TABLE activations DROP CHECK chk_activation_state;
ALTER TABLE activations
	ADD CONSTRAINT chk_activation_state CHECK (state IN ('active','deactivated','revoked','transferred'));
ALTER TABLE activation_challenges RENAME COLUMN installation_id TO server_id;
ALTER TABLE license_requests RENAME COLUMN installation_id TO server_id;
ALTER TABLE signing_keys ADD UNIQUE KEY uq_signing_kid_environment (kid,environment);
ALTER TABLE license_revisions
	ADD COLUMN environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	ADD COLUMN status_reason VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
	ADD CONSTRAINT fk_revision_key_environment
		FOREIGN KEY (kid,environment) REFERENCES signing_keys(kid,environment),
	ADD CONSTRAINT chk_revision_reason CHECK (
		status_reason IS NULL
		OR (license_status='revoked' AND status_reason IN ('transferred','deactivated','revoked'))
	);
