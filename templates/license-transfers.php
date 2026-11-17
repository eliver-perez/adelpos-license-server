<?php
use AdelPos\License\Http\View as V;

// Historial de transferencias de la licencia (contrato §8.4; etapa 4): vía, origen, destino, fecha, estado
// y forma de confirmación, la transferencia por autoservicio pendiente y la liberación manual del contador.
$vias = [
	'self_service' => 'Autoservicio',
	'owner' => 'Propietario',
];
$viaNotes = [
	'self_service' => 'La pidió AD-EL POS',
	'owner' => 'Desde el panel',
];
$forms = [
	'refresh' => 'La computadora anterior consultó en línea',
	'deactivate' => 'La computadora anterior se desactivó en línea',
	'offline_renew' => 'Solicitud de renovación sin internet de la computadora anterior',
	'offline_deactivate' => 'Solicitud de desactivación sin internet de la computadora anterior',
	'manual' => 'Liberada a mano',
	'owner' => 'Hecha por el propietario: no queda pendiente',
];
$pendingTransfer = null;
$transferRows = [];
foreach ($license['transfers'] as $transfer) {
	if ($transfer['state'] === 'pending') {
		$pendingTransfer = $transfer;
	}
	$detail = $transfer['via'] === 'owner' ? $transfer['actor_name'] . ' · ' . $transfer['reason'] : '';
	if ($transfer['confirmation'] === 'manual') {
		$detail = $transfer['releaser_name'] . ' · ' . $transfer['release_reason'];
	}
	$transferRows[] = [
		'date' => V::date($transfer['created_at']),
		'via' => $vias[$transfer['via']],
		'via_note' => $viaNotes[$transfer['via']],
		'from' => $transfer['outgoing_server'],
		'to' => $transfer['incoming_server'] ?? 'Sin destino: plaza liberada',
		'state' => $transfer['state'] === 'pending' ? 'Pendiente de confirmar' : 'Confirmada',
		'form' => $transfer['confirmation'] === null ? '' : $forms[$transfer['confirmation']],
		'confirmed_at' => $transfer['confirmed_at'] === null ? '' : V::date($transfer['confirmed_at']),
		'detail' => $detail,
	];
}
$canRelease = $pendingTransfer !== null && $actor->role === 'superadmin';
$releaseUrl = '/admin/licenses/' . $licenseId . '/transfer-release';
?>
<section class="panel form-panel mt-4" id="transfers">
	<h2>Transferencias</h2>
	<?php if ($pendingTransfer !== null): ?>
		<div class="note-box mt-3" data-testid="pending-transfer">
			<strong>Transferencia por autoservicio pendiente de confirmar</strong>
			<p class="mt-2 mb-1">
				Desde el <?= V::escape(V::date($pendingTransfer['created_at'])) ?>. Se confirma cuando la computadora
				anterior consulta o se desactiva, en línea o con una solicitud sin internet. Mientras tanto, otra
				transferencia por autoservicio recibe «Comunícate con soporte».
			</p>
			<div class="small mono mt-2">
				Origen: <?= V::escape($pendingTransfer['outgoing_server']) ?><br>
				Destino: <?= V::escape($pendingTransfer['incoming_server']) ?>
			</div>
		</div>
	<?php elseif ($canChange): ?>
		<p class="text-secondary small">
			No hay una transferencia por autoservicio pendiente: AD-EL POS puede transferir la licencia a otra computadora.
		</p>
	<?php endif; ?>
	<?php if ($transferRows === []): ?>
		<p class="text-secondary small mb-0">Esta licencia no se ha transferido.</p>
	<?php else: ?>
		<div class="table-responsive mt-3">
			<table class="table" data-testid="transfer-history">
				<thead>
					<tr>
						<th>Fecha</th>
						<th>Vía</th>
						<th>Origen y destino</th>
						<th>Estado</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($transferRows as $row): ?>
						<tr>
							<td><?= V::escape($row['date']) ?></td>
							<td>
								<?= V::escape($row['via']) ?>
								<small class="cell-sub"><?= V::escape($row['via_note']) ?></small>
							</td>
							<td class="small mono">
								<?= V::escape($row['from']) ?><br>→ <?= V::escape($row['to']) ?>
							</td>
							<td>
								<?= V::escape($row['state']) ?>
								<small class="cell-sub"><?= V::escape($row['form']) ?></small>
								<small class="cell-sub"><?= V::escape($row['confirmed_at']) ?></small>
								<small class="cell-sub"><?= V::escape($row['detail']) ?></small>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
	<?php if ($canRelease): ?>
		<details class="mt-4">
			<summary>Liberar el contador de autoservicio</summary>
			<p class="small text-secondary mt-3">
				Úsalo solo si la computadora anterior nunca va a volver a conectarse, por ejemplo porque se dañó. La
				transferencia queda confirmada a mano y AD-EL POS vuelve a poder transferir por autoservicio. Si la
				computadora anterior regresa, recibe su revisión de cierre.
			</p>
			<form class="stack-form" method="post" action="<?= V::escape($releaseUrl) ?>">
				<?php V::fields($csrf); ?>
				<input type="hidden" name="transfer_id" value="<?= V::escape($pendingTransfer['id_text']) ?>">
				<label>
					Motivo de la liberación
					<textarea class="form-control mt-2" name="reason" maxlength="500" rows="2" required></textarea>
				</label>
				<?php require __DIR__ . '/reauth-fields.php'; ?>
				<button class="btn btn-outline-danger">Liberar el contador</button>
			</form>
		</details>
	<?php endif; ?>
</section>
