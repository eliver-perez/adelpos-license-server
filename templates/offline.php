<?php
use AdelPos\License\Http\View as V;

// Solicitudes sin internet (contrato §9; docs/PANEL.md, sección 3.7): importar arriba y la lista con la
// acción, el cliente, el estado y la fecha. Recibir una solicitud no concede nada: se decide en su revisión.
$crumbs = [['Sin internet', null]];
$actionNames = ['activate' => 'Activación', 'renew' => 'Renovación', 'deactivate' => 'Desactivación'];
$statusNames = ['pending' => 'Pendiente', 'approved' => 'Aprobada', 'rejected' => 'Rechazada'];
$statusTones = ['pending' => 'amber', 'approved' => 'green', 'rejected' => 'gray'];
$filtered = $filters['q'] !== '' || $filters['status'] !== '';
$resultLabel = $listing['total'] === 1 ? '1 solicitud' : $listing['total'] . ' solicitudes';
?>
<div class="page-heading">
	<div>
		<h1>Solicitudes sin internet</h1>
		<p>
			Una computadora sin internet pide activar, renovar o liberar su licencia con un archivo .licreq. Impórtalo,
			revisa qué pasará y entrega el archivo .lic autorizado.
		</p>
	</div>
</div>
<?php if ($actor->canWrite()): ?>
	<section class="panel form-panel mb-4" aria-labelledby="import-title">
		<h2 id="import-title">Importar una solicitud</h2>
		<p class="text-secondary">
			Importar comprueba la firma de la computadora y deja la solicitud pendiente de tu decisión. No activa ni
			renueva nada.
		</p>
		<form
			method="post"
			action="/admin/offline/import"
			enctype="multipart/form-data"
			class="d-flex flex-wrap align-items-end gap-3"
		>
			<?php V::fields($csrf); ?>
			<input type="hidden" name="MAX_FILE_SIZE" value="65536">
			<div class="flex-grow-1">
				<label class="form-label" for="request_file">
					Archivo de solicitud (.licreq) <span class="optional">máximo 64 KiB</span>
				</label>
				<input
					class="form-control"
					type="file"
					id="request_file"
					name="request_file"
					accept=".licreq,application/json"
					required
				>
			</div>
			<button class="btn btn-primary" type="submit">Verificar e importar</button>
		</form>
	</section>
<?php endif; ?>
<section class="panel">
	<form class="filter-bar filter-grid filter-grid-compact" method="get" role="search" aria-label="Buscar solicitudes">
		<div class="filter-search">
			<label class="form-label" for="offline-q">Buscar</label>
			<input
				class="form-control"
				id="offline-q"
				name="q"
				type="search"
				value="<?= V::escape($filters['q']) ?>"
				placeholder="Cliente o identificador de la solicitud"
			>
		</div>
		<div>
			<label class="form-label" for="offline-status">Estado</label>
			<select class="form-select" id="offline-status" name="status">
				<option value="">Todos</option>
				<?php foreach ($statusNames as $value => $label): ?>
					<option value="<?= $value ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="filter-buttons">
			<button class="btn btn-primary">Filtrar</button>
			<?php if ($filtered): ?>
				<a class="btn btn-outline-secondary" href="/admin/offline">Limpiar filtros</a>
			<?php endif; ?>
		</div>
	</form>
	<?php if (!$listing['rows']): ?>
		<div class="empty-state">
			<h3>No hay solicitudes<?= $filtered ? ' con estos filtros' : '' ?></h3>
			<p>Las solicitudes importadas aparecen aquí, de la más reciente a la más antigua.</p>
		</div>
	<?php else: ?>
		<p class="result-count"><?= V::escape($resultLabel) ?></p>
		<div class="table-responsive">
			<table class="table table-stack" data-testid="offline-list">
				<thead>
					<tr>
						<th>Solicitud</th>
						<th>Cliente</th>
						<th>Estado</th>
						<th>Importada</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($listing['rows'] as $row): ?>
						<tr>
							<td data-label="Solicitud">
								<a class="row-title" href="/admin/offline/<?= V::escape($row['id_text']) ?>">
									<?= V::escape($actionNames[$row['action']]) ?>
								</a>
								<small class="cell-sub mono"><?= V::escape($row['request_text']) ?></small>
							</td>
							<td data-label="Cliente">
								<?= V::escape($row['customer_name'] ?? 'Por asignar') ?>
							</td>
							<td data-label="Estado">
								<?= V::badge($statusNames[$row['status']], $statusTones[$row['status']]) ?>
							</td>
							<td data-label="Importada"><?= V::escape(V::date($row['imported_at'])) ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
	<?php require __DIR__ . '/pagination.php'; ?>
</section>
