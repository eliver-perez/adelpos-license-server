<?php
declare(strict_types=1);

// Generar con bin/configure.php para una instalación NUEVA. No reemplazar claves existentes.
// APP_ENV: production | development | testing. HTTP solo para desarrollo en loopback.
// LICENSE_ENVIRONMENT: test | production. Es obligatorio y no depende de APP_ENV: indica con qué
// llaves se firman las licencias. licensing.sicrat.com es solo para pruebas, por eso usa «test»
// con APP_ENV=production. «production» solo se permite con APP_ENV=production.
// ADELPOS_LICENSE_CONFIG permite ubicar la configuración fuera del código; variables de proceso prevalecen.
// Todos los secretos siguientes son marcadores vacíos, nunca credenciales utilizables.
return [
	'APP_ENV' => 'production',
	'LICENSE_ENVIRONMENT' => 'test',
	'APP_URL' => 'https://licensing.sicrat.com',
	'DB_DSN' => 'mysql:host=127.0.0.1;port=3306;dbname=adelpos_license;charset=utf8mb4',
	'DB_USER' => 'adelpos_license_app',
	'DB_PASSWORD' => '',
	'MFA_KEY' => '',
	'CREDENTIAL_KEY' => '',
	'AUDIT_KEY' => '',
	'SESSION_KEY' => '',
	'OPERATION_KEY' => '',
	'ADMIN_NETWORKS' => '',
	// Absolute path outside public/, readable only by the dedicated PHP user.
	// Empty/unset uses var/keys/<LICENSE_ENVIRONMENT>; private files are created by CLI.
	// Each license environment needs its own directory.
	'SIGNING_KEY_DIR' => '',
];
