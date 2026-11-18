<?php
use AdelPos\License\Domain\{CalendarPeriod, LicenseState, Money};
use AdelPos\License\Http\View as V;

// Renovar una suscripción (contrato §5.3; docs/PANEL.md, sección 3.4): datos, resultado y confirmación.
// Se calcula de inmediato con la duración vigente y la fecha de pago actual.
$licenseId = $license['id_text'];
$licenseUrl = '/admin/licenses/' . $licenseId;
$formUrl = $licenseUrl . '/renew';
$zone = $license['time_zone'];
$crumbs = [['Licencias', '/admin/licenses'], [$license['customer_name'], $licenseUrl], ['Renovar', null]];
$periods = [
	'monthly' => 'Mensual (1 mes)',
	'quarterly' => 'Trimestral (3 meses)',
	'semiannual' => 'Semestral (6 meses)',
	'annual' => 'Anual (12 meses)',
];
$selectedPeriod = is_string($_GET['billing_period'] ?? null) ? $_GET['billing_period'] : $license['billing_period'];
$paidLocal = is_string($_GET['paid_at'] ?? null) ? $_GET['paid_at'] : '';
$graceLimit = '';
if ($license['expires_at'] !== null) {
	$graceLimit = V::date(
		LicenseState::graceLimit($license['expires_at'], (int) $license['grace_days'])->format('Y-m-d H:i:s.u'),
		$zone,
	);
}
$suggestedText = '';
$priceNote = '';
if ($quote !== null) {
	$dates = $quote['dates'];
	$selectedPeriod = $quote['billing_period'];
	$paidLocal = $quote['paid_at']->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i');
	$suggested = $quote['suggested'];
	$suggestedText = $suggested === null ? '' : Money::toSql($suggested);
	$priceNote = 'Precio del catálogo para esta duración: ' . ($suggested === null ? '' : Money::format($suggested)) . '.';
	if ($license['plan_custom']) {
		$priceNote = 'Una licencia personalizada no tiene precio en el catálogo. Escribe el monto cobrado.';
	} elseif ($suggested === null) {
		$priceNote = 'El plan no tiene precio para esta duración. Escribe el monto cobrado.';
	}
	$newStart = V::date(CalendarPeriod::sql($dates['period_starts_at']), $zone);
	$newExpiry = V::date(CalendarPeriod::sql($dates['expires_at']), $zone);
	$paidText = V::date(CalendarPeriod::sql($quote['paid_at']), $zone);
	$suggestedLabel = $suggestedText === '' ? 'Sin sugerencia' : V::money($suggestedText);
}
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="<?= V::escape($licenseUrl) ?>">← <?= V::escape($license['customer_name']) ?></a>
		<h1 class="mt-3">Renovar la suscripción</h1>
		<p><?= V::escape(V::licenseTerms($license)) ?> · <?= V::escape($license['plan_name']) ?> · Fechas en <?= V::escape($zone) ?></p>
	</div>
</div>
<?php if ($quoteError !== ''): ?>
	<div class="alert alert-danger" role="alert"><?= V::escape($quoteError) ?></div>
<?php endif; ?>
<div class="row g-4">
	<div class="col-xl-8">
		<form class="panel form-panel" method="get" action="<?= V::escape($formUrl) ?>" id="renew-form">
			<h2>Datos de la renovación</h2>
			<div class="row g-3">
				<div class="col-md-6">
					<label class="form-label" for="billing_period">Duración</label>
					<select class="form-select" id="billing_period" name="billing_period">
						<?php foreach ($periods as $value => $label): ?>
							<option
								value="<?= $value ?>"
								<?= $value === $selectedPeriod ? 'selected' : '' ?>
							><?= V::escape($label) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-6">
					<label class="form-label" for="paid_at">Fecha de pago <span class="optional">vacía: este momento</span></label>
					<input
						class="form-control"
						type="datetime-local"
						id="paid_at"
						name="paid_at"
						value="<?= V::escape($paidLocal) ?>"
					>
				</div>
			</div>
			<p class="small text-secondary mt-3 mb-0">
				Si paga antes de que termine la tolerancia, el periodo nuevo empieza en el vencimiento actual. Si no, empieza
				en la fecha de pago. La licencia conserva sus cupos.
			</p>
			<button class="btn btn-outline-primary mt-3">Calcular</button>
		</form>
		<?php if ($quote !== null): ?>
			<section class="panel form-panel mt-4 result-panel" id="renewal-quote" aria-labelledby="renewal-result">
				<h2 id="renewal-result">Resultado</h2>
				<ul class="change-list">
					<li>
						<span>Vencimiento</span>
						<del><?= V::escape(V::date($license['expires_at'], $zone)) ?></del>
						<strong data-testid="new-expiry"><?= V::escape($newExpiry) ?></strong>
					</li>
					<li>
						<span>Duración</span>
						<del><?= V::escape($periods[$license['billing_period']]) ?></del>
						<strong><?= V::escape($periods[$quote['billing_period']]) ?></strong>
					</li>
				</ul>
				<dl class="detail-grid">
					<div>
						<dt>Desde cuándo</dt>
						<dd><?= V::escape($newStart) ?></dd>
					</div>
					<div>
						<dt>Fecha de pago</dt>
						<dd><?= V::escape($paidText) ?></dd>
					</div>
					<div>
						<dt>Fin de la tolerancia actual</dt>
						<dd><?= V::escape($graceLimit) ?></dd>
					</div>
					<div>
						<dt>Monto sugerido</dt>
						<dd data-testid="suggested"><?= V::escape($suggestedLabel) ?></dd>
					</div>
				</dl>
				<p class="note-box mb-0">
					<?php if ($dates['in_time']): ?>
						Pagó antes de que terminara la tolerancia: el periodo nuevo empieza en el vencimiento actual y conserva
						su día y su hora.
					<?php else: ?>
						Pagó después de que terminó la tolerancia: el periodo nuevo empieza en la fecha de pago, y su día y su
						hora pasan a ser los del pago. Los días de tolerancia no se cobran.
					<?php endif; ?>
				</p>
			</section>
		<?php endif; ?>
	</div>
	<div class="col-xl-4">
		<?php if ($quote !== null): ?>
			<form class="panel form-panel stack-form sticky-summary" method="post" action="<?= V::escape($formUrl) ?>" data-amount-form>
				<?php V::fields($csrf); ?>
				<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
				<input type="hidden" name="billing_period" value="<?= V::escape($quote['billing_period']) ?>">
				<input type="hidden" name="paid_at" value="<?= V::escape(CalendarPeriod::utc($quote['paid_at'])) ?>">
				<h2>Confirmar</h2>
				<?php require __DIR__ . '/payment-fields.php'; ?>
				<p class="small text-secondary mb-0">
					Se firma una revisión nueva. La computadora activa la recibe al consultar; una sin internet, con su
					solicitud de renovación o con el archivo .lic.
				</p>
				<button class="btn btn-primary">Registrar renovación</button>
			</form>
		<?php endif; ?>
	</div>
</div>
