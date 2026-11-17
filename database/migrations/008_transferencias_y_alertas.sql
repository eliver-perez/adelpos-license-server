-- Transferencias por autoservicio y alertas del panel (etapa 4, Fase 1; contrato §8.4).
-- «license_transfers» registra toda transferencia de una licencia. Su vía es «self_service», la que
-- pide AD-EL POS por la API, u «owner», la que hace el propietario desde el panel, con computadora
-- de destino o para recuperar la plaza de un equipo averiado. Las del propietario nacen confirmadas.
-- Una de autoservicio queda pendiente hasta que la computadora anterior se autentica y recibe su
-- revisión de cierre, o hasta que el superadministrador libera el contador a mano. Solo puede haber
-- una pendiente por licencia, y lo garantiza el índice único de una columna generada.
-- La cuenta de la aplicación solo actualiza la confirmación: el resto del registro no cambia.
-- «license_alerts» guarda las alertas del panel. Un mismo tipo y una misma licencia comparten una
-- alerta abierta: mientras no se revisa, un intento nuevo solo aumenta su contador.
-- El desafío admite la acción «transfer», que como «activate» no lleva activación (contrato §8.1).
-- En una restricción CHECK un valor NULL cuenta como válido, por eso cada comparación de una
-- columna que admite NULL va precedida de IS NULL o IS NOT NULL.
-- Este archivo no debe contener punto y coma al final de una línea de comentario.
ALTER TABLE activation_challenges DROP CHECK chk_challenge_action;
ALTER TABLE activation_challenges DROP CHECK chk_challenge_identity;
ALTER TABLE activation_challenges
	ADD CONSTRAINT chk_challenge_action CHECK (action IN ('activate','transfer','refresh','deactivate')),
	ADD CONSTRAINT chk_challenge_identity CHECK (
		(
			action IN ('activate','transfer')
			AND activation_id IS NULL
		)
		OR (
			action NOT IN ('activate','transfer')
			AND activation_id IS NOT NULL
		)
	);
ALTER TABLE license_transfers DROP CHECK ck_transfer_ack;
ALTER TABLE license_transfers
	MODIFY admin_id BINARY(16) NULL,
	MODIFY reason VARCHAR(500) NOT NULL DEFAULT '',
	MODIFY offline_limit_accepted BOOLEAN NULL,
	ADD COLUMN via VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'owner'
		AFTER incoming_activation_id,
	ADD COLUMN state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'confirmed',
	ADD COLUMN confirmed_at DATETIME(6) NULL,
	ADD COLUMN confirmation VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
	ADD COLUMN released_by BINARY(16) NULL,
	ADD COLUMN release_reason VARCHAR(500) NULL,
	ADD COLUMN pending_license_id BINARY(16) GENERATED ALWAYS AS (
		CASE WHEN state='pending' THEN license_id ELSE NULL END
	) STORED;
-- Las transferencias que ya registró el panel son del propietario y nacen confirmadas.
UPDATE license_transfers SET confirmed_at=created_at,confirmation='owner' WHERE confirmed_at IS NULL;
ALTER TABLE license_transfers
	ALTER COLUMN via DROP DEFAULT,
	ALTER COLUMN state DROP DEFAULT,
	ADD UNIQUE KEY uq_transfer_pending (pending_license_id),
	ADD INDEX ix_transfer_license_time (license_id,created_at),
	ADD CONSTRAINT fk_transfer_releaser FOREIGN KEY (released_by) REFERENCES admin_users(id),
	ADD CONSTRAINT chk_transfer_via CHECK (via IN ('self_service','owner')),
	ADD CONSTRAINT chk_transfer_state CHECK (state IN ('pending','confirmed')),
	ADD CONSTRAINT chk_transfer_origin CHECK (
		(
			via='owner'
			AND admin_id IS NOT NULL
			AND reason<>''
			AND offline_limit_accepted IS NOT NULL
			AND offline_limit_accepted=1
			AND state='confirmed'
			AND confirmation IS NOT NULL
			AND confirmation='owner'
		)
		OR (
			via<>'owner'
			AND admin_id IS NULL
			AND reason=''
			AND offline_limit_accepted IS NULL
			AND incoming_activation_id IS NOT NULL
			AND (
				confirmation IS NULL
				OR confirmation<>'owner'
			)
		)
	),
	ADD CONSTRAINT chk_transfer_confirmation CHECK (
		(
			state='pending'
			AND confirmed_at IS NULL
			AND confirmation IS NULL
			AND released_by IS NULL
			AND release_reason IS NULL
		)
		OR (
			state<>'pending'
			AND confirmed_at IS NOT NULL
			AND confirmed_at>=created_at
			AND confirmation IS NOT NULL
			AND (
				(
					confirmation='manual'
					AND released_by IS NOT NULL
					AND release_reason IS NOT NULL
					AND release_reason<>''
				)
				OR (
					confirmation IN ('owner','refresh','deactivate','offline_renew','offline_deactivate')
					AND released_by IS NULL
					AND release_reason IS NULL
				)
			)
		)
	);
-- alert_type: transfer_blocked (un intento de transferencia por autoservicio bloqueado por
-- TRANSFER_LIMIT) o frequent_transfers (dos o más transferencias en los 12 meses anteriores).
-- attempts cuenta las veces que ocurrió la situación mientras la alerta estuvo abierta, y
-- last_seen_at es la última. detail_json guarda lo que el propietario necesita para decidir.
-- La revisión dice quién la marcó y cuándo. Revisada, deja de estar abierta: si la situación se
-- repite, se abre otra.
CREATE TABLE license_alerts (
	alert_id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	alert_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	attempts INT UNSIGNED NOT NULL,
	detail_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	created_at DATETIME(6) NOT NULL,
	last_seen_at DATETIME(6) NOT NULL,
	reviewed_at DATETIME(6) NULL,
	reviewed_by BINARY(16) NULL,
	open_license_id BINARY(16) GENERATED ALWAYS AS (
		CASE WHEN reviewed_at IS NULL THEN license_id ELSE NULL END
	) STORED,
	UNIQUE KEY uq_alert_open (open_license_id,alert_type),
	INDEX ix_alert_recent (last_seen_at),
	INDEX ix_alert_license (license_id,created_at),
	CONSTRAINT fk_alert_license FOREIGN KEY (license_id) REFERENCES licenses(license_id),
	CONSTRAINT fk_alert_reviewer FOREIGN KEY (reviewed_by) REFERENCES admin_users(id),
	CONSTRAINT chk_alert_type CHECK (alert_type IN ('transfer_blocked','frequent_transfers')),
	CONSTRAINT chk_alert_attempts CHECK (attempts>=1),
	CONSTRAINT chk_alert_detail CHECK (JSON_VALID(detail_json)),
	CONSTRAINT chk_alert_seen CHECK (last_seen_at>=created_at),
	CONSTRAINT chk_alert_review CHECK (
		(
			reviewed_at IS NULL
			AND reviewed_by IS NULL
		)
		OR (
			reviewed_at IS NOT NULL
			AND reviewed_by IS NOT NULL
			AND reviewed_at>=created_at
		)
	)
) ENGINE=InnoDB;
