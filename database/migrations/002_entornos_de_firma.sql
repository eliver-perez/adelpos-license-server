-- Entornos de firma del contrato V1.0 (sección 3): «test» y «production».
-- Son independientes del entorno de la aplicación (APP_ENV), que antes hacía de entorno de firma.
-- Requiere que no existan llaves de los entornos anteriores («development», «testing»)
-- Este archivo no debe contener punto y coma al final de una línea de comentario.
ALTER TABLE signing_keys DROP CHECK chk_signing_environment;
ALTER TABLE signing_keys ADD CONSTRAINT chk_signing_environment CHECK (environment IN ('test','production'));
ALTER TABLE signing_scopes ADD CONSTRAINT chk_scope_environment CHECK (environment IN ('test','production'));
