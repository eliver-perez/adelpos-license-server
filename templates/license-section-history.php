<?php
use AdelPos\License\Http\View as V;

// Sección «Historial»: los cambios comerciales de la licencia y su auditoría, del más reciente al más antiguo.
$eventRows = [];
foreach ($license['events'] as $event) {
	$payload = json_decode($event['payload_json'], true);
	$eventRows[] = [
		'operation' => V::action($event['operation']),
		'sequence' => (int) $event['sequence'],
		'actor' => $event['actor_name'] ?? 'Sistema o AD-EL POS',
		'reason' => is_array($payload) ? (string) ($payload['reason'] ?? '') : '',
		'occurred_at' => V::date($event['occurred_at']),
	];
}
?>
<section class="panel" id="commercial-history" aria-labelledby="history-title">
	<div class="panel-heading">
		<div>
			<h2 id="history-title">Cambios comerciales</h2>
			<p>Cada operación queda como una versión de la licencia: no se edita ni se sobrescribe.</p>
		</div>
	</div>
	<div class="table-responsive">
		<table class="table table-stack">
			<thead>
				<tr>
					<th>Operación</th>
					<th>Autorizó</th>
					<th>Fecha</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($license['history'] as $change): ?>
					<tr>
						<td data-label="Operación">
							<strong><?= V::escape(V::action($change['action'])) ?></strong>
							<small class="cell-sub"><?= V::escape($change['reason']) ?></small>
						</td>
						<td data-label="Autorizó"><?= V::escape($change['actor_name']) ?></td>
						<td data-label="Fecha"><?= V::escape(V::date($change['created_at'], $zone)) ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>
<section class="panel mt-4" id="license-audit" aria-labelledby="audit-title">
	<div class="panel-heading">
		<div>
			<h2 id="audit-title">Auditoría de la licencia</h2>
			<p>
				Todo lo que se registró sobre esta licencia: operaciones, consultas del código, constancias y
				transferencias. Fechas en la zona del panel.
			</p>
		</div>
		<a class="text-link" href="/admin/audit?q=<?= V::escape($licenseId) ?>">Ver en la auditoría →</a>
	</div>
	<?php if ($eventRows === []): ?>
		<p class="empty-line">Sin eventos registrados.</p>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table table-stack">
				<thead>
					<tr>
						<th>Evento</th>
						<th>Quién</th>
						<th>Fecha</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($eventRows as $row): ?>
						<tr>
							<td data-label="Evento">
								<strong><?= V::escape($row['operation']) ?></strong>
								<small class="cell-sub">#<?= $row['sequence'] ?></small>
								<?php if ($row['reason'] !== ''): ?>
									<small class="cell-sub"><?= V::escape($row['reason']) ?></small>
								<?php endif; ?>
							</td>
							<td data-label="Quién"><?= V::escape($row['actor']) ?></td>
							<td data-label="Fecha"><?= V::escape($row['occurred_at']) ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
