<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

consoleRun(function (): void {
	$options = getopt('', ['env:', 'license-environment:', 'url:', 'dsn:', 'user:', 'output:', 'signing-dir:']);
	if (array_diff(['env', 'license-environment', 'url', 'dsn', 'user'], array_keys($options))) {
		throw new AdelPos\License\Domain\Problem(
			'Uso: php bin/configure.php --env=production --license-environment=test ' .
				'--url=https://licensing.sicrat.com ' .
				'--dsn="mysql:host=127.0.0.1;dbname=adelpos_license;charset=utf8mb4" --user=adelpos_license_app' .
				PHP_EOL .
				'--env es la seguridad de la aplicación (production o development). --license-environment es el ' .
				'entorno de firma de las licencias (test o production) y se indica siempre: licensing.sicrat.com ' .
				'usa test.',
		);
	}
	if (!in_array($options['env'], ['production', 'development'], true)) {
		throw new AdelPos\License\Domain\Problem('--env debe ser production o development.');
	}
	if (!in_array($options['license-environment'], AdelPos\License\Config::LICENSE_ENVIRONMENTS, true)) {
		throw new AdelPos\License\Domain\Problem('--license-environment debe ser test o production.');
	}
	$path = $options['output'] ?? dirname(__DIR__) . '/config/local.php';
	AdelPos\License\Infrastructure\Backup::outsidePublic($path);
	if (file_exists($path) || is_link($path)) {
		throw new AdelPos\License\Domain\Problem('La configuración ya existe; no se reemplazaron sus claves.');
	}
	$values = [
		'APP_ENV' => $options['env'],
		'LICENSE_ENVIRONMENT' => $options['license-environment'],
		'APP_URL' => $options['url'],
		'DB_DSN' => $options['dsn'],
		'DB_USER' => $options['user'],
		'DB_PASSWORD' => hiddenInput('Contraseña de la base de datos: '),
		'ADMIN_NETWORKS' => '',
	];
	foreach (['MFA_KEY', 'CREDENTIAL_KEY', 'AUDIT_KEY', 'SESSION_KEY', 'OPERATION_KEY'] as $key) {
		$values[$key] = base64_encode(random_bytes(32));
	}
	if (isset($options['signing-dir'])) {
		AdelPos\License\Infrastructure\Backup::outsidePublic($options['signing-dir']);
		$values['SIGNING_KEY_DIR'] = $options['signing-dir'];
	}
	(new AdelPos\License\Config($values, false))->validate();
	$oldMask = umask(0077);
	try {
		$file = fopen($path, 'x');
		if (!$file) {
			throw new RuntimeException('Could not create configuration.');
		}
		$contents = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($values, true) . ";\n";
		if (fwrite($file, $contents) !== strlen($contents)) {
			throw new RuntimeException('Incomplete configuration write.');
		}
		fclose($file);
	} finally {
		umask($oldMask);
	}
	fwrite(
		STDOUT,
		'Configuración y claves independientes creadas. Entorno de licencias: ' .
			$values['LICENSE_ENVIRONMENT'] .
			'. Protege y respalda el archivo: ' .
			$path .
			"\n",
	);
});
