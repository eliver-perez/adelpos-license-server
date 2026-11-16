<?php use AdelPos\License\Http\View as V; ?>
<div class="error-state">
	<span class="eyebrow">SOLICITUD <?= (int) $status ?></span>
	<h1><?= V::escape($title) ?></h1>
	<p><?= V::escape($message) ?></p>
	<div class="d-flex gap-2">
		<button class="btn btn-outline-secondary" type="button" data-go-back>Regresar</button>
		<?php if ($actor): ?>
			<a class="btn btn-primary" href="/admin">Ir al resumen</a>
		<?php else: ?>
			<a class="btn btn-primary" href="/login">Iniciar sesión</a>
		<?php endif; ?>
	</div>
</div>
