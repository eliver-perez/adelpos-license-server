<?php
use AdelPos\License\Http\View as V;

// Verificación pública de una constancia (etapa 5, Fase 4): folio, tipo, revisión, fecha, estado actual de la
// licencia y SHA-256 del PDF original. No muestra datos del titular ni de la licencia.
$stateNames = [
	'issued' => ['Vigente', 'green'],
	'pending' => ['Sin activar', 'outline'],
	'revoked' => ['Revocada', 'darkred'],
	'grace' => ['En tolerancia', 'amber'],
	'expired' => ['Vencida', 'red'],
];
$typeNames = ['licensing' => 'Constancia de licenciamiento', 'delivery' => 'Constancia de activación y entrega'];
?>
<!doctype html>
<html lang="es">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<meta name="robots" content="noindex,nofollow">
		<title>Verificación de constancia · AD-EL POS — Licencias</title>
		<link rel="icon" type="image/png" href="/assets/brand/icon.png">
		<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
		<link rel="stylesheet" href="/assets/app.css">
	</head>
	<body class="auth-body">
		<main class="container py-5">
			<section class="panel form-panel">
				<img src="/assets/brand/adel-pos-tema-claro.png" alt="AD-EL POS" width="360">
				<h1 class="mt-4">Verificación de constancia</h1>
				<?php if ($verification === null): ?>
					<p>No se encontró una constancia con esta referencia.</p>
				<?php else: ?>
					<p>
						Constancia emitida por este servidor. Comprueba que el folio y la huella del PDF coincidan con
						el documento recibido.
					</p>
					<dl class="detail-grid">
						<div class="wide">
							<dt>Folio</dt>
							<dd><?= V::escape($verification['folio']) ?></dd>
						</div>
						<div>
							<dt>Tipo</dt>
							<dd><?= V::escape($typeNames[$verification['type']]) ?></dd>
						</div>
						<div>
							<dt>Revisión de la constancia</dt>
							<dd><?= (int) $verification['revision'] ?></dd>
						</div>
						<div>
							<dt>Generada</dt>
							<dd><?= V::escape(V::date($verification['generated_at'])) ?></dd>
						</div>
						<div>
							<dt>Estado actual de la licencia</dt>
							<?php [$stateLabel, $stateTone] = $stateNames[$verification['current_state']]; ?>
							<dd><?= V::badge($stateLabel, $stateTone) ?></dd>
						</div>
						<div class="wide">
							<dt>SHA-256 del PDF original</dt>
							<dd class="mono"><?= V::escape($verification['pdf_sha256']) ?></dd>
						</div>
					</dl>
					<?php if (!$verification['delivery_current']): ?>
						<div class="alert alert-warning">
							La credencial o revisión técnica asociada fue reemplazada o retirada. Esta entrega histórica
							no debe utilizarse como autorización vigente.
						</div>
					<?php endif; ?>
					<p class="note-box">
						Esta consulta acredita el documento histórico, no demuestra que un equipo esté activado
						actualmente. Calcula el SHA-256 de tu PDF y compáralo con la huella anterior; un QR copiado a
						otro documento no valida su contenido.
					</p>
				<?php endif; ?>
			</section>
		</main>
	</body>
</html>
