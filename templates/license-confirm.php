<?php
use AdelPos\License\Domain\LicenseState;
use AdelPos\License\Http\View as V;

// Confirmación explícita de una operación sensible (docs/PANEL.md, sección 3.3): qué va a pasar, una
// casilla «Entiendo…», el motivo cuando aplica y la nueva autenticación. Espera $license y $operation.
$licenseId = $license['id_text'];
$licenseUrl = '/admin/licenses/' . $licenseId;
$zone = $license['time_zone'];
$state = LicenseState::of($license, $now);
$crumbs = [['Licencias', '/admin/licenses'], [$license['customer_name'], $licenseUrl], [$title, null]];
$pendingTransfer = null;
foreach ($license['transfers'] ?? [] as $transfer) {
	if ($transfer['state'] === 'pending') {
		$pendingTransfer = $transfer;
	}
}
$pages = [
	'reveal' => [
		'consequences' => [
			'Verás el código comercial de la licencia. Es secreto: entrégalo solo al cliente autorizado.',
			'La consulta queda en la auditoría con tu nombre.',
		],
		'confirm' => 'Entiendo que el código es secreto y que la consulta queda registrada.',
		'reason' => false,
		'button' => 'Revelar el código',
		'danger' => false,
	],
	'reissue' => [
		'consequences' => [
			'Se genera un código nuevo y se muestra una sola vez.',
			'El código anterior deja de servir para activar y para transferir.',
			'Las computadoras ya activadas siguen funcionando: no se firma una revisión nueva.',
		],
		'confirm' => 'Entiendo que el código anterior deja de servir.',
		'reason' => 'Motivo del reemplazo',
		'button' => 'Reemplazar la clave',
		'danger' => false,
	],
	'revoke' => [
		'consequences' => [
			'La licencia queda revocada y no se puede volver a activar ni transferir.',
			'Cada computadora recibe su revisión de cierre al consultar o con su siguiente solicitud sin internet.',
			'Una computadora que no se vuelva a conectar sigue funcionando con su última revisión hasta su ' .
			'vencimiento, o para siempre si es perpetua: es una limitación conocida del contrato.',
		],
		'confirm' => 'Entiendo que revocar no se puede deshacer.',
		'reason' => 'Motivo de la revocación',
		'button' => 'Revocar la licencia',
		'danger' => true,
	],
	'release' => [
		'consequences' => [
			'Se retira la licencia de la computadora activa y la plaza queda libre para otra computadora.',
			'Queda en «Transferencias» como una transferencia del propietario: no consume el autoservicio.',
			'La computadora retirada recibe su cierre si vuelve a conectarse; mientras siga desconectada, conserva ' .
			'su último archivo.',
		],
		'confirm' => '',
		'reason' => 'Motivo de la recuperación',
		'button' => 'Retirar la computadora y liberar la plaza',
		'danger' => true,
	],
	'transfer-release' => [
		'consequences' => [
			'La transferencia por autoservicio pendiente queda confirmada a mano, y AD-EL POS puede volver a ' .
			'transferir la licencia por su cuenta.',
			'Úsalo solo si la computadora anterior nunca va a volver a conectarse, por ejemplo porque se dañó.',
			'Si la computadora anterior regresa, recibe su revisión de cierre.',
		],
		'confirm' => 'Entiendo que la computadora anterior no va a confirmar la transferencia.',
		'reason' => 'Motivo de la liberación',
		'button' => 'Liberar el contador',
		'danger' => true,
	],
];
$page = $pages[$operation];
$formUrl = $licenseUrl . '/' . $operation;
$reasonValue = V::old('reason', '', $formUrl);
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="<?= V::escape($licenseUrl) ?>">← <?= V::escape($license['customer_name']) ?></a>
		<h1 class="mt-3"><?= V::escape($title) ?></h1>
		<p>
			<?= V::escape(V::licenseTerms($license)) ?> · <?= V::escape($license['plan_name']) ?> ·
			<?= V::stateBadge($state, $license['license_type']) ?>
		</p>
	</div>
</div>
<div class="row g-4">
	<div class="col-xl-7">
		<form
			class="panel form-panel stack-form confirm-panel <?= $page['danger'] ? 'confirm-danger' : '' ?>"
			method="post"
			action="<?= V::escape($formUrl) ?>"
			data-testid="confirm-form"
		>
			<?php V::fields($csrf); ?>
			<h2>Qué va a pasar</h2>
			<ul class="consequence-list">
				<?php foreach ($page['consequences'] as $consequence): ?>
					<li><?= V::escape($consequence) ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if (in_array($operation, ['reissue', 'revoke', 'release'], true)): ?>
				<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
			<?php endif; ?>
			<?php if ($operation === 'release'): ?>
				<input type="hidden" name="activation_id" value="<?= V::escape($license['active']['id_text']) ?>">
				<p class="mono small mb-0">Computadora: <?= V::escape($license['active']['server_id_text']) ?></p>
			<?php endif; ?>
			<?php if ($operation === 'transfer-release'): ?>
				<input type="hidden" name="transfer_id" value="<?= V::escape($pendingTransfer['id_text']) ?>">
				<p class="mono small mb-0">
					Origen: <?= V::escape($pendingTransfer['outgoing_server']) ?><br>
					Destino: <?= V::escape($pendingTransfer['incoming_server']) ?>
				</p>
			<?php endif; ?>
			<?php if ($page['reason'] !== false): ?>
				<label>
					<?= V::escape($page['reason']) ?>
					<textarea
						class="form-control mt-2"
						name="reason"
						rows="2"
						maxlength="500"
						required
					><?= V::escape($reasonValue) ?></textarea>
				</label>
			<?php endif; ?>
			<?php if ($operation === 'release'): ?>
				<?php require __DIR__ . '/offline-confirm.php'; ?>
			<?php else: ?>
				<label class="form-check confirm-check">
					<input class="form-check-input" type="checkbox" name="understood" value="1" required>
					<?= V::escape($page['confirm']) ?>
				</label>
				<?php require __DIR__ . '/reauth-fields.php'; ?>
			<?php endif; ?>
			<div class="form-actions">
				<a class="btn btn-outline-secondary" href="<?= V::escape($licenseUrl) ?>">Cancelar</a>
				<button class="btn <?= $page['danger'] ? 'btn-danger' : 'btn-primary' ?>"><?= V::escape($page['button']) ?></button>
			</div>
		</form>
	</div>
	<div class="col-xl-5">
		<section class="panel form-panel">
			<h2>Licencia</h2>
			<dl class="detail-grid">
				<div class="wide">
					<dt>Cliente</dt>
					<dd><?= V::escape($license['customer_name']) ?></dd>
				</div>
				<div class="wide">
					<dt>Licencia</dt>
					<dd class="mono"><?= V::escape($licenseId) ?></dd>
				</div>
				<div>
					<dt>Vencimiento</dt>
					<dd><?= V::escape(V::expiry($license)) ?></dd>
				</div>
				<div>
					<dt>Computadora activa</dt>
					<dd class="mono"><?= V::escape($license['active']['server_id_text'] ?? 'Ninguna') ?></dd>
				</div>
			</dl>
		</section>
	</div>
</div>
