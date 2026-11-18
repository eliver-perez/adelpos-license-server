<?php
use AdelPos\License\Http\View as V;

// Auditoría: quién hizo cada cambio y cuándo. Se busca por la operación (también por su nombre en español)
// o por el identificador del objeto. Fechas en la zona del panel.
$crumbs = [['Auditoría', null]];
$eventRows = [];
foreach ($listing['rows'] as $event) {
	$payload = json_decode($event['payload_json'], true);
	$eventRows[] = [
		'operation' => V::action($event['operation']),
		'sequence' => (int) $event['sequence'],
		'actor' => $event['actor_name'] ?? 'Sistema o AD-EL POS',
		'occurred_at' => V::date($event['occurred_at']),
		'target_type' => $event['target_type'],
		'target_id' => $event['target_id'] ?? '—',
		'reason' => is_array($payload) && ($payload['reason'] ?? '') !== '' ? (string) $payload['reason'] : '—',
		'metadata' => json_encode(
			is_array($payload) ? $payload['metadata'] ?? [] : [],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
		),
	];
}
?>
<div class="page-heading">
	<div>
		<h1>Auditoría</h1>
		<p>Quién hizo cada cambio y cuándo. Los eventos se encadenan y no se pueden editar ni borrar.</p>
	</div>
	<?= V::badge('Solo consulta', 'gray') ?>
</div>
<section class="panel">
	<form class="filter-bar filter-grid filter-grid-compact" method="get" role="search" aria-label="Buscar eventos">
		<div class="filter-search">
			<label class="form-label" for="q">Buscar</label>
			<input
				class="form-control"
				id="q"
				name="q"
				type="search"
				placeholder="Operación (por ejemplo, «renovada») o identificador del objeto"
				value="<?= V::escape($filters['q']) ?>"
			>
		</div>
		<div class="filter-buttons">
			<button class="btn btn-primary">Buscar</button>
			<?php if ($filters['q'] !== ''): ?>
				<a class="btn btn-outline-secondary" href="/admin/audit">Limpiar</a>
			<?php endif; ?>
		</div>
	</form>
	<?php if ($eventRows === []): ?>
		<div class="empty-state">
			<h3>No hay eventos<?= $filters['q'] !== '' ? ' con esta búsqueda' : '' ?></h3>
			<p>Prueba con otra palabra o con el identificador de una licencia.</p>
		</div>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table table-stack" data-testid="audit-list">
				<thead>
					<tr>
						<th>Evento</th>
						<th>Quién</th>
						<th>Fecha</th>
						<th>Detalle</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($eventRows as $row): ?>
						<tr>
							<td data-label="Evento">
								<strong><?= V::escape($row['operation']) ?></strong>
								<small class="cell-sub">#<?= $row['sequence'] ?></small>
							</td>
							<td data-label="Quién"><?= V::escape($row['actor']) ?></td>
							<td data-label="Fecha"><?= V::escape($row['occurred_at']) ?></td>
							<td data-label="Detalle">
								<details>
									<summary>Consultar</summary>
									<dl class="audit-detail">
										<dt>Objeto</dt>
										<dd><?= V::escape($row['target_type']) ?></dd>
										<dt>Identificador</dt>
										<dd class="mono"><?= V::escape($row['target_id']) ?></dd>
										<dt>Motivo</dt>
										<dd><?= V::escape($row['reason']) ?></dd>
										<dt>Registro</dt>
										<dd class="mono"><?= V::escape($row['metadata']) ?></dd>
									</dl>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
	<?php require __DIR__ . '/pagination.php'; ?>
</section>
