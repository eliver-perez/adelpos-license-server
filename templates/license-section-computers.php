<?php
use AdelPos\License\Http\View as V;

// Sección «Computadoras y transferencias» (contrato §8.4; etapa 4): la activación vigente, el historial
// de activaciones, las transferencias con su vía y su confirmación, la pendiente con su liberación manual,
// recuperar plaza y las alertas de la licencia. Las fechas van en la zona del cliente.
$activationStates = [
	'active' => ['Activa', 'green'],
	'deactivated' => ['Liberada', 'gray'],
	'transferred' => ['Transferida', 'gray'],
	'revoked' => ['Revocada', 'darkred'],
];
$vias = ['self_service' => 'Autoservicio', 'owner' => 'Propietario'];
$viaNotes = ['self_service' => 'La pidió AD-EL POS', 'owner' => 'Desde el panel'];
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
		'date' => V::date($transfer['created_at'], $zone),
		'via' => $vias[$transfer['via']],
		'via_note' => $viaNotes[$transfer['via']],
		'from' => $transfer['outgoing_server'],
		'to' => $transfer['incoming_server'] ?? 'Sin destino: plaza liberada',
		'state' => $transfer['state'] === 'pending' ? 'Pendiente de confirmar' : 'Confirmada',
		'tone' => $transfer['state'] === 'pending' ? 'amber' : 'gray',
		'form' => $transfer['confirmation'] === null ? '' : $forms[$transfer['confirmation']],
		'confirmed_at' => $transfer['confirmed_at'] === null ? '' : V::date($transfer['confirmed_at'], $zone),
		'detail' => $detail,
	];
}
$isSuperadmin = $actor->role === 'superadmin';
$canRelease = $pendingTransfer !== null && $isSuperadmin;
$canRecover = $license['active'] !== null && $isSuperadmin && $canChange;
// Las alertas de la licencia vuelven a esta sección al marcarse como revisadas.
$returnTo = $licenseUrl . '?section=computers';
?>
<div class="row g-4">
	<div class="col-xl-5">
		<section class="panel form-panel h-100" aria-labelledby="active-computer-title">
			<h2 id="active-computer-title">Computadora activa</h2>
			<?php if ($license['active'] === null): ?>
				<p class="text-secondary">
					<?php if ($activated): ?>
						Ninguna: la licencia se liberó o se retiró. Se puede activar en otra computadora con el mismo código.
					<?php else: ?>
						Esta licencia todavía no se ha activado en ninguna computadora.
					<?php endif; ?>
				</p>
			<?php else: ?>
				<dl class="detail-grid">
					<div class="wide">
						<dt>Computadora (server_id)</dt>
						<dd class="mono" data-testid="active-server"><?= V::escape($license['active']['server_id_text']) ?></dd>
					</div>
					<div>
						<dt>Activada</dt>
						<dd><?= V::escape(V::date($license['active']['activated_at'], $zone)) ?></dd>
					</div>
					<div>
						<dt>Versión de AD-EL POS</dt>
						<dd><?= V::escape($license['active']['app_version']) ?></dd>
					</div>
				</dl>
				<p class="text-secondary small">Una licencia tiene como máximo una computadora activa.</p>
			<?php endif; ?>
			<?php if ($canRecover): ?>
				<a class="btn btn-outline-danger" href="<?= V::escape($licenseUrl) ?>/release">
					Recuperar plaza por computadora averiada
				</a>
			<?php endif; ?>
		</section>
	</div>
	<div class="col-xl-7">
		<section class="panel form-panel h-100" id="transfers" aria-labelledby="transfers-title">
			<h2 id="transfers-title">Transferencia por autoservicio</h2>
			<?php if ($pendingTransfer !== null): ?>
				<div class="note-box note-warning" data-testid="pending-transfer">
					<strong>Transferencia por autoservicio pendiente de confirmar</strong>
					<p class="mt-2 mb-1">
						Desde el <?= V::escape(V::date($pendingTransfer['created_at'], $zone)) ?>. Se confirma cuando la
						computadora anterior consulta o se desactiva, en línea o con una solicitud sin internet. Mientras
						tanto, otra transferencia por autoservicio recibe «Comunícate con soporte».
					</p>
					<div class="small mono mt-2">
						Origen: <?= V::escape($pendingTransfer['outgoing_server']) ?><br>
						Destino: <?= V::escape($pendingTransfer['incoming_server']) ?>
					</div>
				</div>
				<?php if ($canRelease): ?>
					<a class="btn btn-outline-danger mt-3" href="<?= V::escape($licenseUrl) ?>/transfer-release">
						Liberar el contador de autoservicio
					</a>
				<?php endif; ?>
			<?php else: ?>
				<p class="text-secondary mb-0">
					No hay una transferencia pendiente: AD-EL POS puede transferir la licencia a otra computadora por su
					cuenta, una vez, hasta que la anterior la confirme.
				</p>
			<?php endif; ?>
		</section>
	</div>
	<div class="col-12">
		<section class="panel" aria-labelledby="activations-title">
			<div class="panel-heading">
				<div>
					<h2 id="activations-title">Historial de computadoras</h2>
					<p>Cada activación, con su estado. Las fechas de la licencia no cambian al pasar de una a otra.</p>
				</div>
			</div>
			<?php if (!$license['activations']): ?>
				<p class="empty-line">Sin activaciones todavía.</p>
			<?php else: ?>
				<div class="table-responsive">
					<table class="table table-stack" data-testid="activation-history">
						<thead>
							<tr>
								<th>Computadora (server_id)</th>
								<th>Estado</th>
								<th>Activada</th>
								<th>Terminó</th>
								<th>Versión</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($license['activations'] as $activation): ?>
								<?php [$stateLabel, $stateTone] = $activationStates[$activation['state']]; ?>
								<tr>
									<td data-label="Computadora" class="mono"><?= V::escape($activation['server_id_text']) ?></td>
									<td data-label="Estado"><?= V::badge($stateLabel, $stateTone) ?></td>
									<td data-label="Activada"><?= V::escape(V::date($activation['activated_at'], $zone)) ?></td>
									<td data-label="Terminó"><?= V::escape(V::date($activation['ended_at'], $zone)) ?></td>
									<td data-label="Versión"><?= V::escape($activation['app_version']) ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	</div>
	<div class="col-12">
		<section class="panel" aria-labelledby="transfer-history-title">
			<div class="panel-heading">
				<div>
					<h2 id="transfer-history-title">Transferencias</h2>
					<p>Las de autoservicio y las del propietario, con su confirmación.</p>
				</div>
			</div>
			<?php if ($transferRows === []): ?>
				<p class="empty-line">Esta licencia no se ha transferido.</p>
			<?php else: ?>
				<div class="table-responsive">
					<table class="table table-stack" data-testid="transfer-history">
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
									<td data-label="Fecha"><?= V::escape($row['date']) ?></td>
									<td data-label="Vía">
										<?= V::escape($row['via']) ?>
										<small class="cell-sub"><?= V::escape($row['via_note']) ?></small>
									</td>
									<td data-label="Origen y destino" class="small mono">
										<?= V::escape($row['from']) ?><br>→ <?= V::escape($row['to']) ?>
									</td>
									<td data-label="Estado">
										<?= V::badge($row['state'], $row['tone']) ?>
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
		</section>
	</div>
	<div class="col-12">
		<section class="panel" id="license-alerts" aria-labelledby="license-alerts-title">
			<div class="panel-heading">
				<div>
					<h2 id="license-alerts-title">Alertas de esta licencia</h2>
					<p>Intentos de transferencia bloqueados y transferencias frecuentes. No afectan a AD-EL POS.</p>
				</div>
			</div>
			<div class="panel-body">
				<?php foreach ($license['alerts'] as $alert): ?>
					<?php require __DIR__ . '/alert-item.php'; ?>
				<?php endforeach; ?>
				<?php if (!$license['alerts']): ?>
					<p class="empty-line">Sin alertas.</p>
				<?php endif; ?>
			</div>
		</section>
	</div>
</div>
