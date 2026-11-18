<?php
use AdelPos\License\Application\Alerts;
use AdelPos\License\Http\View as V;

// Alertas del panel (contrato §8.4; decisión 4 de la etapa 4): las sin revisar primero y después las
// revisadas, con filtros por estado y por tipo. No se envían correos.
$statusNames = ['open' => 'Sin revisar', 'reviewed' => 'Revisadas'];
$notice = ($_GET['notice'] ?? '') === 'alert-reviewed' ? 'Alerta marcada como revisada.' : '';
$returnTo = '/admin/alerts';
$crumbs = [['Alertas', null]];
$filtered = $filters['status'] !== '' || $filters['type'] !== '';
$resultLabel = $listing['total'] === 1 ? '1 alerta' : $listing['total'] . ' alertas';
?>
<?php if ($notice): ?>
	<div class="alert alert-success" role="status"><?= V::escape($notice) ?></div>
<?php endif; ?>
<div class="page-heading">
	<div>
		<h1>Alertas</h1>
		<p>
			Intentos de transferencia bloqueados y licencias que se transfieren con frecuencia. No afectan a AD-EL POS
			ni se envían por correo: se revisan aquí.
		</p>
	</div>
</div>
<section class="panel">
	<form class="filter-bar filter-grid filter-grid-compact" method="get" aria-label="Filtrar alertas">
		<div>
			<label class="form-label" for="alert-status">Estado</label>
			<select class="form-select" id="alert-status" name="status">
				<option value="">Todas</option>
				<?php foreach ($statusNames as $value => $label): ?>
					<option value="<?= $value ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label class="form-label" for="alert-type">Tipo</label>
			<select class="form-select" id="alert-type" name="type">
				<option value="">Todos</option>
				<?php foreach (Alerts::TYPES as $value => $label): ?>
					<option value="<?= $value ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= V::escape($label) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="filter-buttons">
			<button class="btn btn-primary">Filtrar</button>
			<?php if ($filtered): ?>
				<a class="btn btn-outline-secondary" href="/admin/alerts">Limpiar filtros</a>
			<?php endif; ?>
		</div>
	</form>
	<div class="panel-body" data-testid="alert-list">
		<?php if ($listing['rows']): ?>
			<p class="result-count px-0"><?= V::escape($resultLabel) ?></p>
		<?php endif; ?>
		<?php foreach ($listing['rows'] as $alert): ?>
			<?php require __DIR__ . '/alert-item.php'; ?>
		<?php endforeach; ?>
		<?php if (!$listing['rows']): ?>
			<div class="empty-state">
				<h3>No hay alertas<?= $filtered ? ' con estos filtros' : '' ?></h3>
				<p>
					Cuando AD-EL POS intente transferir una licencia con otra transferencia pendiente, o una licencia se
					transfiera dos veces en 12 meses, la alerta aparecerá aquí y en la campana de la barra superior.
				</p>
			</div>
		<?php endif; ?>
	</div>
	<?php require __DIR__ . '/pagination.php'; ?>
</section>
