-- Retira lo que el contenido firmado dejó de usar al pasar al contrato V1.0 (sección 4):
-- la huella del equipo, la lista de límites anterior y las fechas de mantenimiento.
-- Los cupos de la licencia viven desde la migración 003 en «licenses» (cajas, comanderos y
-- pantallas de cocina), y el fin de las actualizaciones en «updates_until».
-- Además exige el motivo de cierre en toda revisión firmada como «revoked».
-- Requiere una base sin revisiones firmadas con el contenido anterior.
-- Este archivo no debe contener punto y coma al final de una línea de comentario.
ALTER TABLE activations
	DROP COLUMN fingerprint_version,
	DROP COLUMN fingerprint_hash;
DROP TABLE license_limits;
ALTER TABLE licenses
	DROP COLUMN maintenance_until,
	DROP COLUMN entitled_release_until;
ALTER TABLE license_revisions DROP CHECK chk_revision_reason;
ALTER TABLE license_revisions
	ADD CONSTRAINT chk_revision_reason CHECK (
		(license_status='active' AND status_reason IS NULL)
		OR (
			license_status='revoked'
			AND status_reason IS NOT NULL
			AND status_reason IN ('transferred','deactivated','revoked')
		)
	);
