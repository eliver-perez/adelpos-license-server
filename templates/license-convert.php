<?php
use AdelPos\License\Domain\{CalendarPeriod, LicenseTerms, Money};
use AdelPos\License\Http\View as V;

// Convertir una prueba en licencia de pago (contrato §5.4; docs/PANEL.md, sección 3.4): primero el destino,
// después el resultado y la confirmación. No hay bonificación.
$licenseId = $license['id_text'];
$licenseUrl = '/admin/licenses/' . $licenseId;
$formUrl = $licenseUrl . '/convert';
$zone = $license['time_zone'];
$crumbs = [['Licencias', '/admin/licenses'], [$license['customer_name'], $licenseUrl], ['Convertir', null]];
$periods = [
	'monthly' => 'Mensual (1 mes)',
	'quarterly' => 'Trimestral (3 meses)',
	'semiannual' => 'Semestral (6 meses)',
	'annual' => 'Anual (12 meses)',
];
$monthsOptions = ['0' => 'Sin actualizaciones', '6' => '6 meses', '12' => '12 meses'];
$chosen = static fn(string $key, string $default): string => is_string($_GET[$key] ?? null) ? $_GET[$key] : $default;
$selectedType = $chosen('license_type', 'subscription');
$selectedPeriod = $chosen('billing_period', 'monthly');
$selectedMonths = $chosen('updates_months', '6');
$selectedPlan = $chosen('plan_id', $license['plan_id']);
$paidLocal = $chosen('paid_at', '');
$limitValues = [];
foreach (array_keys(LicenseTerms::LIMITS) as $limit) {
	$limitValues[$limit] = $chosen($limit, '');
}
$suggestedText = '';
$priceNote = '';
if ($quote !== null) {
	$terms = $quote['terms'];
	$target = $quote['target'];
	$period = $quote['period'];
	$paidLocal = $quote['paid_at']->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i');
	$suggested = $quote['suggested'];
	$suggestedText = $suggested === null ? '' : Money::toSql($suggested);
	$priceNote = 'Precio del catálogo: ' . ($suggested === null ? '' : Money::format($suggested)) . '. No hay bonificación.';
	if ($target['custom']) {
		$priceNote = 'Una licencia personalizada no tiene precio en el catálogo. Escribe el monto cobrado.';
	} elseif ($suggested === null) {
		$priceNote = 'El plan no tiene precio para este tipo de licencia. Escribe el monto cobrado.';
	}
	$hidden = [
		'license_type' => $terms['license_type'],
		'billing_period' => $terms['billing_period'] ?? '',
		'updates_months' => $terms['updates_months'] === null ? '' : (string) $terms['updates_months'],
		'plan_id' => $target['plan']['plan_id'],
		'paid_at' => CalendarPeriod::utc($quote['paid_at']),
	];
	foreach (LicenseTerms::LIMITS as $limit => $label) {
		$hidden[$limit] = (string) $target['limits'][$limit];
	}
	$targetLimits = V::limits($target['limits']);
	$suggestedLabel = $suggestedText === '' ? 'Sin sugerencia' : V::money($suggestedText);
	$paidText = V::date(CalendarPeriod::sql($quote['paid_at']), $zone);
}
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="<?= V::escape($licenseUrl) ?>">← <?= V::escape($license['customer_name']) ?></a>
		<h1 class="mt-3">Convertir la prueba en licencia de pago</h1>
		<p><?= V::escape(V::licenseTerms($license)) ?> · <?= V::escape($license['plan_name']) ?> · Fechas en <?= V::escape($zone) ?></p>
	</div>
</div>
<?php if ($quoteError !== ''): ?>
	<div class="alert alert-danger" role="alert"><?= V::escape($quoteError) ?></div>
<?php endif; ?>
<div class="row g-4">
	<div class="col-xl-8">
		<form class="panel form-panel stack-form" method="get" action="<?= V::escape($formUrl) ?>" id="convert-form">
			<h2>Destino de la conversión</h2>
			<p class="small text-secondary mb-0">
				Durante la prueba o después de vencida, a cualquier tipo y plan. Si ya está activada, el periodo nuevo
				empieza en la fecha de pago, sin reinstalar.
			</p>
			<div class="row g-3">
				<div class="col-md-4">
					<label class="form-label" for="license_type">Tipo</label>
					<select class="form-select" id="license_type" name="license_type">
						<option value="subscription" <?= $selectedType === 'subscription' ? 'selected' : '' ?>>Suscripción</option>
						<option value="perpetual" <?= $selectedType === 'perpetual' ? 'selected' : '' ?>>Perpetua</option>
					</select>
				</div>
				<div class="col-md-4">
					<label class="form-label" for="billing_period">Periodo <span class="optional">suscripción</span></label>
					<select class="form-select" id="billing_period" name="billing_period">
						<?php foreach ($periods as $value => $label): ?>
							<option
								value="<?= $value ?>"
								<?= $value === $selectedPeriod ? 'selected' : '' ?>
							><?= V::escape($label) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-4">
					<label class="form-label" for="updates_months">Actualizaciones <span class="optional">perpetua</span></label>
					<select class="form-select" id="updates_months" name="updates_months">
						<?php foreach ($monthsOptions as $value => $label): ?>
							<option
								value="<?= $value ?>"
								<?= (string) $value === $selectedMonths ? 'selected' : '' ?>
							><?= V::escape($label) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<?php require __DIR__ . '/target-fields.php'; ?>
			<div>
				<label class="form-label" for="paid_at">Fecha de pago <span class="optional">vacía: este momento</span></label>
				<input class="form-control" type="datetime-local" id="paid_at" name="paid_at" value="<?= V::escape($paidLocal) ?>">
			</div>
			<div>
				<button class="btn btn-outline-primary">Calcular la conversión</button>
			</div>
		</form>
		<?php if ($quote !== null): ?>
			<section class="panel form-panel mt-4 result-panel" id="conversion-quote" aria-labelledby="conversion-result">
				<h2 id="conversion-result">Resultado</h2>
				<ul class="change-list">
					<li>
						<span>Tipo</span>
						<del><?= V::escape(V::licenseTerms($license)) ?></del>
						<strong><?= V::escape(V::licenseTerms($terms)) ?></strong>
					</li>
					<li>
						<span>Plan</span>
						<del><?= V::escape($license['plan_name']) ?></del>
						<strong>
							<?= V::escape($target['plan']['display_name']) ?>
							<?php if ($target['custom']): ?>
								<span class="tag">Personalizada</span>
							<?php endif; ?>
						</strong>
					</li>
					<li>
						<span>Cupos</span>
						<del><?= V::escape(V::limits($license)) ?></del>
						<strong><?= V::escape($targetLimits) ?></strong>
					</li>
				</ul>
				<dl class="detail-grid">
					<div>
						<dt>Fecha de pago</dt>
						<dd><?= V::escape($paidText) ?></dd>
					</div>
					<div>
						<dt>Monto sugerido</dt>
						<dd data-testid="suggested"><?= V::escape($suggestedLabel) ?></dd>
					</div>
					<?php if ($period !== []): ?>
						<div>
							<dt>Desde cuándo</dt>
							<dd><?= V::escape(V::date(CalendarPeriod::sql($period['period_starts_at']), $zone)) ?></dd>
						</div>
						<div>
							<dt>Vencimiento nuevo</dt>
							<dd data-testid="new-expiry">
								<?php if ($period['expires_at'] === null): ?>
									Sin vencimiento
								<?php else: ?>
									<?= V::escape(V::date(CalendarPeriod::sql($period['expires_at']), $zone)) ?>
								<?php endif; ?>
							</dd>
						</div>
						<div>
							<dt>Actualizaciones hasta</dt>
							<dd><?= V::escape(V::date(CalendarPeriod::sql($period['updates_until']), $zone)) ?></dd>
						</div>
					<?php endif; ?>
				</dl>
				<p class="note-box mb-0">
					<?php if ($period !== []): ?>
						La prueba ya está activada: el periodo nuevo empieza en la fecha de pago y los días de prueba que
						faltaban no se acumulan. La computadora recibe una revisión nueva de su misma activación, sin
						reinstalar.
					<?php else: ?>
						La prueba no está activada: cambian sus condiciones y sus fechas se fijan al activarla.
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
				<?php foreach ($hidden as $name => $value): ?>
					<input type="hidden" name="<?= V::escape($name) ?>" value="<?= V::escape($value) ?>">
				<?php endforeach; ?>
				<h2>Confirmar</h2>
				<?php require __DIR__ . '/payment-fields.php'; ?>
				<button class="btn btn-primary">Registrar conversión</button>
			</form>
		<?php else: ?>
			<section class="panel form-panel">
				<h2>Confirmar</h2>
				<p class="text-secondary mb-0">Elige el destino y calcula la conversión para ver el monto y confirmar.</p>
			</section>
		<?php endif; ?>
	</div>
</div>
