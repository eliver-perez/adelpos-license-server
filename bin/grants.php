<?php
declare(strict_types=1);
require __DIR__ . '/console.php';

// Imprime los GRANT de la cuenta de la aplicación. La lista está en RuntimeGrants, que también usa
// bin/verify-deployment.php para comprobar que la cuenta no tenga ni más ni menos.
consoleRun(function (): void {
	$options = getopt('', ['database:', 'user:']);
	$database = $options['database'] ?? 'adelpos_license';
	$user = $options['user'] ?? 'adelpos_license_app';
	if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $database) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $user)) {
		throw new AdelPos\License\Domain\Problem('Usa identificadores SQL simples.');
	}
	foreach (AdelPos\License\Infrastructure\RuntimeGrants::statements($database, $user) as $statement) {
		fwrite(STDOUT, $statement . "\n");
	}
});
