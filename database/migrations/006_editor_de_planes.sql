-- Editor del catálogo de planes (etapa 3, Fase 2).
-- Un plan en uso no se borra: se archiva y deja de ofrecerse al emitir. «row_version» detecta un
-- formulario obsoleto, como en clientes, productos y licencias.
-- El identificador del plan se firma en la licencia (plan.plan_id), así que la base exige el
-- mismo formato que el contrato: minúsculas, números y guiones bajos, de 2 a 64 caracteres.
-- Este archivo no debe contener punto y coma al final de una línea de comentario.
ALTER TABLE plans
	ADD COLUMN state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
	ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1,
	ADD CONSTRAINT chk_plan_state CHECK (state IN ('active','archived')),
	ADD CONSTRAINT chk_plan_identifier CHECK (REGEXP_LIKE(plan_id,'^[a-z][a-z0-9_]{1,63}$','c'));
