<?php
use AdelPos\License\Application\LicenseActions;
use AdelPos\License\Domain\LicenseState;
use AdelPos\License\Http\View as V;
use AdelPos\License\Infrastructure\ReadRepository;

// Detalle de una licencia (docs/PANEL.md, sección 3.3): cabecera siempre visible, una sola barra de
// acciones con las que aplican a la licencia y al rol, y siete secciones con su propia dirección.
$licenseId = $license['id_text'];
$licenseUrl = '/admin/licenses/' . $licenseId;
$zone = $license['time_zone'];
$state = LicenseState::of($license, $now);
$activated = $license['period_starts_at'] !== null;
$canChange = $actor->canWrite() && $license['commercial_status'] === 'issued';
$actions = LicenseActions::for($license, $actor);
$crumbs = [['Licencias', '/admin/licenses'], [$license['customer_name'], null]];
$notices = [
	'administrative' => 'Datos comerciales actualizados. Los derechos, las fechas y las computadoras no cambiaron.',
	'document' => 'Constancia generada. Está en «Constancias», lista para descargar.',
	'renew' => 'Renovación registrada con su pago. La computadora activa recibe la revisión nueva al consultar.',
	'convert' =>
		'Conversión registrada con su pago. Si la prueba estaba activada, su computadora recibe una revisión nueva.',
	'plan' => 'Cambio de plan registrado. Si se programó una reducción, está en «Cambio programado».',
	'scheduled-cancel' => 'Cambio programado cancelado. La licencia conserva su plan y sus cupos.',
	'maintenance' => 'Mantenimiento registrado con su pago. La computadora activa recibe la revisión nueva al consultar.',
	'terms' => 'Periodo inicial actualizado. Las fechas se fijan al activar la licencia.',
	'alert-reviewed' => 'Alerta marcada como revisada.',
	'transfer-released' =>
		'Contador de autoservicio liberado. La transferencia quedó confirmada a mano y AD-EL POS puede volver a transferir.',
	'features' => 'Módulos actualizados. Las computadoras activas reciben la revisión nueva al consultar.',
	'reissue' => 'Clave reemplazada. El código anterior ya no sirve para activar ni transferir.',
	'revoke' => 'Licencia revocada. Cada computadora recibe su cierre al consultar o con su siguiente solicitud.',
	'release' => 'Computadora retirada. La plaza quedó libre para activar la licencia en otra computadora.',
];
$noticeKey = is_string($_GET['notice'] ?? null) ? $_GET['notice'] : '';
$notice = $notices[$noticeKey] ?? '';
$expiry = V::dateParts($license['expires_at'], $zone);
if ($license['license_type'] === 'perpetual') {
	$expiry = ['date' => 'Sin vencimiento', 'zone' => ''];
} elseif (!$activated) {
	$expiry = ['date' => 'Empieza al activar', 'zone' => ''];
}
$updates = $activated ? V::dateParts($license['updates_until'], $zone) : ['date' => 'Empieza al activar', 'zone' => ''];
// Operaciones de la barra: la primera que aplica es la principal.
$operations = [
	'renew' => $licenseUrl . '/renew',
	'convert' => $licenseUrl . '/convert',
	'plan' => $licenseUrl . '/plan',
	'maintenance' => $licenseUrl . '/maintenance',
	'terms' => $licenseUrl . '/terms',
];
$primary = null;
foreach (array_keys($operations) as $operation) {
	if ($primary === null && in_array($operation, $actions, true) && $operation !== 'plan') {
		$primary = $operation;
	}
}
$more = array_values(array_intersect(['reveal', 'reissue'], $actions));
$sectionUrl = $licenseUrl . '?section=';
?>
<?php if ($notice): ?>
	<div class="alert alert-success" role="status"><?= V::escape($notice) ?></div>
<?php endif; ?>
<section class="panel record-header" aria-labelledby="license-title">
	<div class="record-title">
		<div>
			<h1 id="license-title">
				<a href="/admin/customers/<?= V::escape($license['customer_text']) ?>"><?= V::escape($license['customer_name']) ?></a>
			</h1>
			<p>
				<?php if ($license['legal_name'] !== ''): ?>
					<?= V::escape($license['legal_name']) ?> ·
				<?php endif; ?>
				Licencia <span class="mono"><?= V::escape($licenseId) ?></span>
			</p>
		</div>
		<div data-testid="license-state"><?= V::stateBadge($state, $license['license_type']) ?></div>
	</div>
	<dl class="record-facts">
		<div>
			<dt>Tipo y periodo</dt>
			<dd><?= V::escape(V::licenseTerms($license)) ?></dd>
		</div>
		<div>
			<dt>Plan y cupos</dt>
			<dd>
				<?= V::escape($license['plan_name']) ?>
				<?php if ($license['plan_custom']): ?>
					<span class="tag">Personalizada</span>
				<?php endif; ?>
				<small><?= V::escape(V::limits($license)) ?></small>
			</dd>
		</div>
		<div>
			<dt>Vencimiento</dt>
			<dd><?= V::escape($expiry['date']) ?><small><?= V::escape($expiry['zone']) ?></small></dd>
		</div>
		<div>
			<dt>Actualizaciones hasta</dt>
			<dd><?= V::escape($updates['date']) ?><small><?= V::escape($updates['zone']) ?></small></dd>
		</div>
		<div>
			<dt>Computadora activa</dt>
			<dd>
				<?php if ($license['active'] !== null): ?>
					<span class="mono" title="<?= V::escape($license['active']['server_id_text']) ?>">
						<?= V::escape(V::shortId($license['active']['server_id_text'])) ?>
					</span>
					<small>AD-EL POS <?= V::escape($license['active']['app_version']) ?></small>
				<?php else: ?>
					Ninguna
				<?php endif; ?>
			</dd>
		</div>
	</dl>
	<?php if ($license['scheduled'] !== null || $license['pending_transfer'] || $license['open_alerts'] > 0): ?>
		<ul class="record-notes">
			<?php if ($license['scheduled'] !== null): ?>
				<li>
					<?= V::badge('Cambio programado', 'blue') ?>
					<?= V::escape($license['scheduled']['plan_name']) ?> desde el
					<?= V::escape(V::date($license['scheduled']['effective_at'], $zone)) ?>
				</li>
			<?php endif; ?>
			<?php if ($license['pending_transfer']): ?>
				<li>
					<?= V::badge('Transferencia pendiente', 'amber') ?>
					<a href="<?= $sectionUrl ?>computers">Ver en «Computadoras y transferencias»</a>
				</li>
			<?php endif; ?>
			<?php if ($license['open_alerts'] > 0): ?>
				<li>
					<?= V::badge($license['open_alerts'] === 1 ? '1 alerta' : $license['open_alerts'] . ' alertas', 'amber') ?>
					<a href="<?= $sectionUrl ?>computers#license-alerts">Revisar</a>
				</li>
			<?php endif; ?>
		</ul>
	<?php endif; ?>
	<?php if ($actions !== []): ?>
		<div class="action-bar" data-testid="license-actions">
			<?php foreach ($operations as $operation => $operationUrl): ?>
				<?php if (in_array($operation, $actions, true)): ?>
					<a
						class="btn <?= $operation === $primary ? 'btn-primary' : 'btn-outline-primary' ?>"
						href="<?= V::escape($operationUrl) ?>"
					><?= LicenseActions::LABELS[$operation] ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if (in_array('download', $actions, true)): ?>
				<a
					class="btn btn-outline-secondary"
					href="<?= V::escape($licenseUrl . '/revisions/' . $license['current_revision'] . '/download') ?>"
				><?= V::icon('download') ?> Descargar .lic vigente</a>
			<?php endif; ?>
			<?php if (in_array('documents', $actions, true)): ?>
				<a class="btn btn-outline-secondary" href="<?= $sectionUrl ?>documents">Constancias</a>
			<?php endif; ?>
			<?php if ($more !== []): ?>
				<div class="dropdown">
					<button
						class="btn btn-outline-secondary dropdown-toggle"
						type="button"
						data-bs-toggle="dropdown"
						aria-expanded="false"
					>Código</button>
					<div class="dropdown-menu">
						<?php foreach ($more as $operation): ?>
							<a class="dropdown-item" href="<?= V::escape($licenseUrl . '/' . $operation) ?>">
								<?= LicenseActions::LABELS[$operation] ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
			<?php if (in_array('revoke', $actions, true)): ?>
				<a class="btn btn-outline-danger action-end" href="<?= V::escape($licenseUrl . '/revoke') ?>">Revocar</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>
<nav class="section-tabs" aria-label="Secciones de la licencia">
	<form class="section-select" method="get" action="<?= V::escape($licenseUrl) ?>">
		<label class="form-label" for="section-select">Sección</label>
		<div class="d-flex gap-2">
			<select class="form-select" id="section-select" name="section">
				<?php foreach (ReadRepository::LICENSE_SECTIONS as $key => $label): ?>
					<option value="<?= $key ?>" <?= $key === $section ? 'selected' : '' ?>><?= V::escape($label) ?></option>
				<?php endforeach; ?>
			</select>
			<button class="btn btn-outline-secondary">Ver</button>
		</div>
	</form>
	<ul>
		<?php foreach (ReadRepository::LICENSE_SECTIONS as $key => $label): ?>
			<li>
				<a
					href="<?= $sectionUrl . $key ?>"
					class="<?= $key === $section ? 'active' : '' ?>"
					<?= $key === $section ? 'aria-current="page"' : '' ?>
				><?= V::escape($label) ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
<div id="section-<?= $section ?>" class="license-section">
	<?php require __DIR__ . '/license-section-' . $section . '.php'; ?>
</div>
