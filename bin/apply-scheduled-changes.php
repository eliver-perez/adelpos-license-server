<?php
declare(strict_types=1);
// Aplica los cambios de plan programados cuyo instante ya llegó (etapa 3, Fase 7). Lo ejecuta la
// tarea diaria de mantenimiento y también se puede ejecutar a mano: cada cambio se aplica una sola
// vez, aunque corran dos ejecuciones al mismo tiempo.
require __DIR__ . '/console.php';

consoleRun(function (): void {
	$app = AdelPos\License\App::boot();
	$result = $app->scheduledChanges->applyAllDue();
	fwrite(
		STDOUT,
		'Cambios programados aplicados: ' .
			$result['applied'] .
			'. Revisiones firmadas: ' .
			$result['revisions_signed'] .
			'. Ya aplicados por otra ejecución o cancelados: ' .
			$result['skipped'] .
			".\n",
	);
});
