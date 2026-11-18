<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

use AdelPos\License\{App, Config};
use AdelPos\License\Domain\Problem;
use AdelPos\License\Infrastructure\DeploymentCheck;

// Verificación del despliegue (DEPLOY_UBUNTU_24_04.md, paso 13). No modifica nada. Se ejecuta en el VPS con sudo:
//   sudo env ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php php8.3 bin/verify-deployment.php
// Termina con código 1 si alguna comprobación da Error.
consoleRun(function (): void {
	$options = getopt('', [
		'url:',
		'http-url:',
		'ca-file:',
		'license-environment:',
		'app-user:',
		'admin-user:',
		'backup-dir:',
		'code-dir:',
		'systemctl:',
		'help',
	]);
	if (isset($options['help'])) {
		fwrite(
			STDOUT,
			"Uso: sudo env ADELPOS_LICENSE_CONFIG=… php8.3 bin/verify-deployment.php [opciones]\n" .
				"  --url=https://…            dirección del sitio (APP_URL de la configuración)\n" .
				"  --http-url=http://…        dirección por HTTP para la redirección (la de --url con http://)\n" .
				"  --license-environment=…    entorno de firma que debe tener el sitio (test)\n" .
				"  --app-user=…               usuario del sistema de la aplicación (adelpos-license)\n" .
				"  --admin-user=…             dueño del código y de los respaldos (root)\n" .
				"  --backup-dir=…             carpeta de respaldos (/srv/adelpos-license/backup)\n" .
				"  --code-dir=…               código desplegado (el de este comando)\n" .
				"  --ca-file=…                autoridad de certificados propia, solo para pruebas\n" .
				"  --systemctl=…              programa systemctl (systemctl)\n",
		);
		return;
	}
	$path = getenv('ADELPOS_LICENSE_CONFIG') ?: dirname(__DIR__) . '/config/local.php';
	if (!is_file($path)) {
		throw new Problem(
			'No existe la configuración ' .
				$path .
				'. Define ADELPOS_LICENSE_CONFIG=/srv/adelpos-license/shared/config.php.',
		);
	}
	if (!is_readable($path)) {
		throw new Problem('No se puede leer ' . $path . ': ejecuta la verificación con sudo.');
	}
	$config = Config::load();
	$url = rtrim($options['url'] ?? $config->get('APP_URL'), '/');
	if (!preg_match('~\Ahttps://[^/?#]+\z~', $url)) {
		throw new Problem('La dirección del sitio debe ser https://dominio, sin ruta: ' . $url);
	}
	$httpUrl = rtrim($options['http-url'] ?? 'http://' . parse_url($url, PHP_URL_HOST), '/');
	if (!preg_match('~\Ahttp://[^/?#]+\z~', $httpUrl)) {
		throw new Problem('La dirección por HTTP debe ser http://dominio, sin ruta: ' . $httpUrl);
	}
	$check = new DeploymentCheck($config, static fn(): App => App::boot(), [
		'config' => $path,
		'code' => $options['code-dir'] ?? dirname(__DIR__),
		'app_user' => $options['app-user'] ?? 'adelpos-license',
		'admin_user' => $options['admin-user'] ?? 'root',
		'backup' => $options['backup-dir'] ?? '/srv/adelpos-license/backup',
		'license_environment' => $options['license-environment'] ?? 'test',
		'url' => $url,
		'http_url' => $httpUrl,
		'ca_file' => $options['ca-file'] ?? '',
		'systemctl' => $options['systemctl'] ?? 'systemctl',
	]);
	$results = $check->run();
	fwrite(STDOUT, 'Verificación del despliegue de AD-EL POS — Licencias en ' . $url . "\n\n");
	$counts = [DeploymentCheck::OK => 0, DeploymentCheck::WARNING => 0, DeploymentCheck::ERROR => 0];
	foreach ($results as $result) {
		++$counts[$result['status']];
		fwrite(STDOUT, str_pad($result['status'], 13) . $result['title'] . ': ' . $result['detail'] . "\n");
		if ($result['fix'] !== '') {
			fwrite(STDOUT, str_repeat(' ', 13) . 'Qué hacer: ' . $result['fix'] . "\n");
		}
	}
	fwrite(
		STDOUT,
		sprintf(
			"\nResumen: %d OK, %d %s y %d %s. No se modificó nada.\n",
			$counts[DeploymentCheck::OK],
			$counts[DeploymentCheck::WARNING],
			$counts[DeploymentCheck::WARNING] === 1 ? 'advertencia' : 'advertencias',
			$counts[DeploymentCheck::ERROR],
			$counts[DeploymentCheck::ERROR] === 1 ? 'error' : 'errores',
		),
	);
	if (DeploymentCheck::failed($results)) {
		exit(1);
	}
});
