-- Cambios de plan programados (etapa 3, Fase 5; decisiones 7 y 8 del propietario).
-- Bajar de plan o reducir cupos entra en vigor cuando vence el tiempo pagado: el cambio se guarda
-- aquí con su instante y lo aplica la tarea programada (bin/apply-scheduled-changes.php), o la
-- renovación si llega antes que la tarea. Una licencia tiene a lo sumo un cambio pendiente: uno
-- nuevo sustituye al anterior, que queda como «replaced».
-- Los cupos de destino solo pueden subir después de programarse, cuando el catálogo propaga un
-- aumento del plan de destino. Por eso la cuenta de la aplicación actualiza esas columnas.
-- Este archivo no debe contener punto y coma al final de una línea de comentario.
CREATE TABLE license_scheduled_changes (
	change_id BINARY(16) PRIMARY KEY,
	license_id BINARY(16) NOT NULL,
	operation_id BINARY(16) NOT NULL,
	effective_at DATETIME(6) NOT NULL,
	plan_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
	plan_custom BOOLEAN NOT NULL,
	cash_registers SMALLINT NOT NULL,
	order_takers SMALLINT NOT NULL,
	kitchen_displays SMALLINT NOT NULL,
	state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
	pending_license_id BINARY(16) GENERATED ALWAYS AS (
		CASE WHEN state='pending' THEN license_id ELSE NULL END
	) STORED,
	admin_id BINARY(16) NOT NULL,
	reason VARCHAR(500) NOT NULL DEFAULT '',
	created_at DATETIME(6) NOT NULL,
	resolved_at DATETIME(6) NULL,
	resolved_by BINARY(16) NULL,
	UNIQUE KEY uq_scheduled_pending(pending_license_id),
	INDEX ix_scheduled_due(state,effective_at),
	CONSTRAINT fk_scheduled_license FOREIGN KEY (license_id) REFERENCES licenses(license_id),
	CONSTRAINT fk_scheduled_operation FOREIGN KEY (operation_id) REFERENCES admin_operations(operation_id),
	CONSTRAINT fk_scheduled_plan FOREIGN KEY (plan_id) REFERENCES plans(plan_id),
	CONSTRAINT fk_scheduled_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id),
	CONSTRAINT fk_scheduled_resolver FOREIGN KEY (resolved_by) REFERENCES admin_users(id),
	CONSTRAINT chk_scheduled_state CHECK (state IN ('pending','applied','cancelled','replaced')),
	CONSTRAINT chk_scheduled_plan CHECK (plan_custom IN (0,1)),
	CONSTRAINT chk_scheduled_limits CHECK (
		cash_registers BETWEEN 0 AND 999
		AND order_takers BETWEEN 0 AND 999
		AND kitchen_displays BETWEEN 0 AND 999
	),
	CONSTRAINT chk_scheduled_resolution CHECK (
		(
			state='pending'
			AND resolved_at IS NULL
			AND resolved_by IS NULL
		)
		OR (
			state='applied'
			AND resolved_at IS NOT NULL
		)
		OR (
			state IN ('cancelled','replaced')
			AND resolved_at IS NOT NULL
			AND resolved_by IS NOT NULL
		)
	)
) ENGINE=InnoDB;
