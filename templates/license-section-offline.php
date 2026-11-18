<?php
use AdelPos\License\Http\View as V;

// Sección «Sin internet»: las solicitudes .licreq de esta licencia, con su acción, su decisión y su fecha.
$requestActions = ['activate' => 'Activación', 'renew' => 'Renovación', 'deactivate' => 'Desactivación'];
$requestStates = [
	'pending' => ['Pendiente', 'amber'],
	'approved' => ['Aprobada', 'green'],
	'rejected' => ['Rechazada', 'gray'],
];
?>
<section class="panel" id="offline-requests" aria-labelledby="offline-title">
	<div class="panel-heading">
		<div>
			<h2 id="offline-title">Solicitudes sin internet</h2>
			<p>Las computadoras sin internet piden activar, renovar o liberar con un archivo .licreq.</p>
		</div>
		<a class="text-link" href="/admin/offline">Importar una solicitud →</a>
	</div>
	<?php if (!$license['offline']): ?>
		<p class="empty-line">Esta licencia no tiene solicitudes sin internet.</p>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table table-stack">
				<thead>
					<tr>
						<th>Solicitud</th>
						<th>Estado</th>
						<th>Importada</th>
						<th>Decisión</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($license['offline'] as $request): ?>
						<?php [$requestState, $requestTone] = $requestStates[$request['status']]; ?>
						<tr>
							<td data-label="Solicitud">
								<a class="row-title" href="/admin/offline/<?= V::escape($request['id_text']) ?>">
									<?= V::escape($requestActions[$request['action']]) ?>
								</a>
							</td>
							<td data-label="Estado"><?= V::badge($requestState, $requestTone) ?></td>
							<td data-label="Importada"><?= V::escape(V::date($request['imported_at'], $zone)) ?></td>
							<td data-label="Decisión">
								<?= V::escape(V::date($request['decided_at'], $zone)) ?>
								<?php if (($request['reason'] ?? '') !== ''): ?>
									<small class="cell-sub"><?= V::escape($request['reason']) ?></small>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
