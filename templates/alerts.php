<?php
use AdelPos\License\Application\Alerts;
use AdelPos\License\Http\View as V;

// Alertas del panel (decisión 4 del propietario para la etapa 4): las sin revisar primero y después las
// revisadas, con un filtro por estado y por tipo.
$statusNames = ['open' => 'Sin revisar', 'reviewed' => 'Revisadas'];
$notice = ($_GET['notice'] ?? '') === 'alert-reviewed' ? 'Alerta marcada como revisada.' : '';
$returnTo = '/admin/alerts';
?>
<?php if ($notice): ?>
	<div class="alert alert-success" role="status"><?= V::escape($notice) ?></div>
<?php endif; ?>
<div class="page-heading">
	<div>
		<span class="eyebrow">SEGUIMIENTO</span>
		<h1>Alertas</h1>
		<p>Transferencias por autoservicio bloqueadas y licencias que se transfieren con frecuencia. No se envían correos.</p>
	</div>
</div>
<section class="panel">
	<form class="filter-bar" method="get">
		<select class="form-select" name="status" aria-label="Estado">
			<option value="">Todas</option>
			<?php foreach ($statusNames as $value => $label): ?>
				<option value="<?= $value ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
			<?php endforeach; ?>
		</select>
		<select class="form-select" name="type" aria-label="Tipo de alerta">
			<option value="">Todos los tipos</option>
			<?php foreach (Alerts::TYPES as $value => $label): ?>
				<option value="<?= $value ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= V::escape($label) ?></option>
			<?php endforeach; ?>
		</select>
		<button class="btn btn-outline-primary">Filtrar</button>
	</form>
	<div data-testid="alert-list">
		<?php foreach ($listing['rows'] as $alert): ?>
			<?php require __DIR__ . '/alert-item.php'; ?>
		<?php endforeach; ?>
		<?php if (!$listing['rows']): ?>
			<p class="text-secondary mt-3">No hay alertas con estos filtros.</p>
		<?php endif; ?>
	</div>
	<?php require __DIR__ . '/pagination.php'; ?>
</section>
