<?php
use AdelPos\License\Domain\LicenseState;
use AdelPos\License\Http\View as V;

// Lista de licencias (docs/PANEL.md, sección 3.2): búsqueda, filtros, orden y paginación.
$crumbs = [['Licencias', null]];
$typeNames = ['subscription' => 'Suscripción', 'perpetual' => 'Perpetua', 'trial' => 'Prueba'];
$sortNames = [
	'created_desc' => 'Alta: más reciente',
	'created_asc' => 'Alta: más antigua',
	'expiry_asc' => 'Vencimiento: más próximo',
	'expiry_desc' => 'Vencimiento: más lejano',
];
$filtered = array_filter(
	$filters,
	static fn($value, $key): bool => $value !== '' && $key !== 'sort',
	ARRAY_FILTER_USE_BOTH,
);
$resultLabel = $listing['total'] === 1 ? '1 licencia' : $listing['total'] . ' licencias';
?>
<div class="page-heading">
	<div>
		<h1>Licencias</h1>
		<p>Busca por cliente, razón social, licencia, computadora o referencia, y filtra por su estado real.</p>
	</div>
</div>
<section class="panel">
	<form method="get" class="filter-bar filter-grid" role="search" aria-label="Buscar licencias">
		<div class="filter-search">
			<label for="q" class="form-label">Buscar</label>
			<input
				class="form-control"
				id="q"
				name="q"
				type="search"
				placeholder="Cliente, razón social, licencia, computadora (server_id) o referencia"
				value="<?= V::escape($filters['q']) ?>"
			>
		</div>
		<div>
			<label for="type" class="form-label">Tipo</label>
			<select name="type" id="type" class="form-select">
				<option value="">Todos</option>
				<?php foreach ($typeNames as $value => $label): ?>
					<option value="<?= $value ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= $label ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label for="state" class="form-label">Estado</label>
			<select name="state" id="state" class="form-select">
				<option value="">Todos</option>
				<?php foreach (LicenseState::LABELS as $value => $label): ?>
					<option value="<?= $value ?>" <?= $filters['state'] === $value ? 'selected' : '' ?>><?= $label ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label for="plan" class="form-label">Plan</label>
			<select name="plan" id="plan" class="form-select">
				<option value="">Todos</option>
				<?php foreach ($plans as $plan): ?>
					<option
						value="<?= V::escape($plan['plan_id']) ?>"
						<?= $filters['plan'] === $plan['plan_id'] ? 'selected' : '' ?>
					><?= V::escape($plan['display_name']) ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label for="sort" class="form-label">Ordenar por</label>
			<select name="sort" id="sort" class="form-select">
				<?php foreach ($sortNames as $value => $label): ?>
					<option value="<?= $value ?>" <?= $filters['sort'] === $value ? 'selected' : '' ?>><?= $label ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="filter-checks">
			<label class="form-check">
				<input
					class="form-check-input"
					type="checkbox"
					name="custom"
					value="1"
					<?= $filters['custom'] === '1' ? 'checked' : '' ?>
				> Solo personalizadas
			</label>
			<label class="form-check">
				<input
					class="form-check-input"
					type="checkbox"
					name="transfer"
					value="pending"
					<?= $filters['transfer'] === 'pending' ? 'checked' : '' ?>
				> Con transferencia pendiente
			</label>
		</div>
		<div class="filter-buttons">
			<button class="btn btn-primary">Filtrar</button>
			<?php if ($filtered !== []): ?>
				<a class="btn btn-outline-secondary" href="/admin/licenses">Limpiar filtros</a>
			<?php endif; ?>
		</div>
	</form>
	<?php if (!$listing['rows']): ?>
		<div class="empty-state">
			<h3>No hay licencias para mostrar</h3>
			<?php if ($filtered !== []): ?>
				<p>Ninguna licencia cumple estos filtros. Prueba con otra búsqueda o limpia los filtros.</p>
			<?php else: ?>
				<p>Todavía no hay licencias. Registra un cliente y emite su primera licencia.</p>
			<?php endif; ?>
			<?php if ($actor->canWrite() && $filtered === []): ?>
				<a class="btn btn-primary" href="/admin/licenses/new">Emitir licencia</a>
			<?php endif; ?>
		</div>
	<?php else: ?>
		<p class="result-count" role="status"><?= V::escape($resultLabel) ?></p>
		<div class="table-responsive">
			<table class="table table-stack" data-testid="license-list">
				<thead>
					<tr>
						<th>Cliente</th>
						<th>Tipo y periodo</th>
						<th>Plan y cupos</th>
						<th>Estado</th>
						<th>Vencimiento</th>
						<th>Computadora activa</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($listing['rows'] as $license): ?>
						<?php
						$licenseUrl = '/admin/licenses/' . $license['id_text'];
						$expiry = $license['license_type'] === 'perpetual'
							? ['date' => 'Sin vencimiento', 'zone' => '']
							: V::dateParts($license['expires_at'], $license['time_zone']);
						if ($license['state'] === LicenseState::PENDING) {
							$expiry = ['date' => 'Empieza al activar', 'zone' => ''];
						}
						?>
						<tr>
							<td data-label="Cliente">
								<a class="row-title" href="<?= V::escape($licenseUrl) ?>">
									<?= V::escape($license['customer_name']) ?>
								</a>
								<?php if ($license['legal_name'] !== ''): ?>
									<small class="cell-sub"><?= V::escape($license['legal_name']) ?></small>
								<?php endif; ?>
							</td>
							<td data-label="Tipo y periodo"><?= V::escape(V::licenseTerms($license)) ?></td>
							<td data-label="Plan y cupos">
								<?= V::escape($license['plan_name']) ?>
								<?php if ($license['plan_custom']): ?>
									<span class="tag">Personalizada</span>
								<?php endif; ?>
								<small class="cell-sub"><?= V::escape(V::limits($license)) ?></small>
							</td>
							<td data-label="Estado">
								<?= V::stateBadge($license['state'], $license['license_type']) ?>
								<?php if ($license['transfer_pending']): ?>
									<small class="cell-sub">Transferencia pendiente</small>
								<?php endif; ?>
							</td>
							<td data-label="Vencimiento">
								<?= V::escape($expiry['date']) ?>
								<small class="cell-sub"><?= V::escape($expiry['zone']) ?></small>
							</td>
							<td data-label="Computadora activa">
								<?php if ($license['active_server'] !== null): ?>
									<span class="mono" title="<?= V::escape($license['active_server']) ?>">
										<?= V::escape(V::shortId($license['active_server'])) ?>
									</span>
								<?php else: ?>
									<span class="text-secondary">Ninguna</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
	<?php require __DIR__ . '/pagination.php'; ?>
</section>
