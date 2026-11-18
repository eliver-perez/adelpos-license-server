<?php
use AdelPos\License\Http\View as V;

// Lista de clientes (docs/PANEL.md, sección 3.5): búsqueda por nombre, razón social, RFC o correo.
$crumbs = [['Clientes', null]];
$filtered = $filters['q'] !== '' || $filters['state'] !== '';
$resultLabel = $listing['total'] === 1 ? '1 cliente' : $listing['total'] . ' clientes';
?>
<div class="page-heading">
	<div>
		<h1>Clientes</h1>
		<p>Cada cliente con sus licencias, su zona horaria y su historial de pagos.</p>
	</div>
	<?php if ($actor->canWrite()): ?>
		<a class="btn btn-primary" href="/admin/customers/new">Nuevo cliente</a>
	<?php endif; ?>
</div>
<section class="panel">
	<form class="filter-bar filter-grid" method="get" role="search" aria-label="Buscar clientes">
		<div class="filter-search">
			<label class="form-label" for="q">Buscar</label>
			<input
				id="q"
				name="q"
				type="search"
				class="form-control"
				placeholder="Nombre comercial, razón social, RFC o correo"
				value="<?= V::escape($filters['q']) ?>"
			>
		</div>
		<div>
			<label class="form-label" for="state">Estado</label>
			<select class="form-select" id="state" name="state">
				<option value="">Todos</option>
				<option value="active" <?= $filters['state'] === 'active' ? 'selected' : '' ?>>Activos</option>
				<option value="archived" <?= $filters['state'] === 'archived' ? 'selected' : '' ?>>Archivados</option>
			</select>
		</div>
		<div class="filter-buttons">
			<button class="btn btn-primary">Filtrar</button>
			<?php if ($filtered): ?>
				<a class="btn btn-outline-secondary" href="/admin/customers">Limpiar filtros</a>
			<?php endif; ?>
		</div>
	</form>
	<?php if (!$listing['rows']): ?>
		<div class="empty-state">
			<h3>No hay clientes para mostrar</h3>
			<?php if ($filtered): ?>
				<p>Ningún cliente cumple esta búsqueda. Prueba con otra o limpia los filtros.</p>
			<?php else: ?>
				<p>Registra tu primer cliente para emitirle una licencia.</p>
				<?php if ($actor->canWrite()): ?>
					<a class="btn btn-primary" href="/admin/customers/new">Nuevo cliente</a>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php else: ?>
		<p class="result-count"><?= V::escape($resultLabel) ?></p>
		<div class="table-responsive">
			<table class="table table-stack" data-testid="customer-list">
				<thead>
					<tr>
						<th>Cliente</th>
						<th>RFC</th>
						<th>Licencias</th>
						<th>Zona horaria</th>
						<th>Contacto</th>
						<th>Estado</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($listing['rows'] as $customer): ?>
						<?php $isActive = $customer['state'] === 'active'; ?>
						<tr>
							<td data-label="Cliente">
								<a class="row-title" href="/admin/customers/<?= V::escape($customer['id_text']) ?>">
									<?= V::escape($customer['display_name']) ?>
								</a>
								<?php if ($customer['legal_name'] !== ''): ?>
									<small class="cell-sub"><?= V::escape($customer['legal_name']) ?></small>
								<?php endif; ?>
							</td>
							<td data-label="RFC" class="mono"><?= V::escape($customer['tax_id'] ?: '—') ?></td>
							<td data-label="Licencias">
								<?= (int) $customer['license_count'] ?>
								<small class="cell-sub"><?= (int) $customer['active_count'] ?> vigentes</small>
							</td>
							<td data-label="Zona horaria"><?= V::escape($customer['time_zone']) ?></td>
							<td data-label="Contacto">
								<?= V::escape($customer['contact_name']) ?>
								<small class="cell-sub"><?= V::escape($customer['email']) ?></small>
							</td>
							<td data-label="Estado"><?= V::badge($isActive ? 'Activo' : 'Archivado', $isActive ? 'green' : 'gray') ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
	<?php require __DIR__ . '/pagination.php'; ?>
</section>
