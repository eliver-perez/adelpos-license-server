<?php
use AdelPos\License\Domain\{CalendarPeriod, LicenseTerms, Money};
use AdelPos\License\Http\View as V;

// Cambio de plan o de cupos (contrato §11.3 y §11.4; docs/PANEL.md, sección 3.4): primero el destino, después
// qué cambia, desde cuándo, el monto sugerido con el desglose de la bonificación, y la confirmación.
$licenseId = $license['id_text'];
$licenseUrl = '/admin/licenses/' . $licenseId;
$formUrl = $licenseUrl . '/plan';
$zone = $license['time_zone'];
$type = $license['license_type'];
$crumbs = [['Licencias', '/admin/licenses'], [$license['customer_name'], $licenseUrl], ['Cambiar de plan', null]];
$periods = [
	'monthly' => 'Mensual (1 mes)',
	'quarterly' => 'Trimestral (3 meses)',
	'semiannual' => 'Semestral (6 meses)',
	'annual' => 'Anual (12 meses)',
];
$chosen = static fn(string $key, string $default): string => is_string($_GET[$key] ?? null) ? $_GET[$key] : $default;
$selectedPlan = $chosen('plan_id', $license['plan_id']);
$selectedPeriod = $chosen('billing_period', (string) $license['billing_period']);
$paidLocal = $chosen('paid_at', '');
$limitValues = [];
foreach (array_keys(LicenseTerms::LIMITS) as $limit) {
	$limitValues[$limit] = $chosen($limit, '');
}
$typeNotes = [
	'subscription' =>
		'Subir aplica de inmediato con un periodo nuevo desde la fecha de pago y una bonificación por lo que ' .
		'faltaba de lo pagado. Bajar entra en vigor al vencer lo pagado.',
	'perpetual' => 'Una perpetua solo sube de plan, pagando la diferencia de los precios perpetuos.',
	'trial' => 'Durante la prueba, el cambio es libre y sin cobro, y no cambia sus fechas.',
];
$suggestedText = '';
$priceNote = '';
$heading = 'Cambiar de plan o de cupos';
if ($quote !== null) {
	$kind = $quote['kind'];
	$target = $quote['target'];
	$period = $quote['period'];
	$basis = $quote['credit_basis'];
	$suggested = $quote['suggested'];
	$suggestedText = $suggested === null ? '' : Money::toSql($suggested);
	$kindNames = [
		'trial' => 'Cambio de plan de una prueba',
		'perpetual' => 'Cambio de plan: subida de una perpetua',
		'increase' => $quote['down'] === []
			? 'Cambio de plan: aumento con bonificación'
			: 'Cambio de plan: sube unos cupos y baja otros',
		'reduction' => 'Cambio de plan: reducción programada',
		'renewal' => 'Cambio de plan: renovación con plan nuevo',
	];
	$heading = $kindNames[$kind];
	$kindNotes = [
		'trial' => 'Cambiar de plan durante la prueba es libre, sin cobro, y no cambia sus fechas.',
		'perpetual' =>
			'Se paga la diferencia entre los precios perpetuos de los dos planes. El cambio aplica de inmediato y no ' .
			'cambia el fin de las actualizaciones.',
		'increase' =>
			'El periodo nuevo empieza en la fecha de pago, y se descuenta lo que faltaba por usar de los periodos ya ' .
			'pagados, también de uno pagado por adelantado. Los aumentos aplican de inmediato.',
		'reduction' =>
			'El plan y los cupos nuevos entran en vigor cuando vence el tiempo pagado. No se cobra nada ahora: el ' .
			'cobro llega con la renovación, que sugiere el precio del plan nuevo.',
		'renewal' =>
			'Se paga después del vencimiento: no es un cambio de plan sino una renovación con plan nuevo, sin ' .
			'bonificación. El periodo nuevo trae los números nuevos.',
	];
	$limitChanges = [];
	foreach (LicenseTerms::LIMITS as $limit => $label) {
		$limitChanges[] = [$label, (int) $license[$limit], (int) $target['limits'][$limit]];
	}
	$hidden = ['plan_id' => $target['plan']['plan_id'], 'billing_period' => $quote['billing_period'] ?? ''];
	foreach (LicenseTerms::LIMITS as $limit => $label) {
		$hidden[$limit] = (string) $target['limits'][$limit];
	}
	if ($quote['paid_at'] !== null) {
		$hidden['paid_at'] = CalendarPeriod::utc($quote['paid_at']);
		$paidLocal = $quote['paid_at']->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i');
	}
	// Cada periodo pagado que todavía no termina aporta su parte de la bonificación (contrato §11.4).
	$creditRows = [];
	foreach ($basis['parts'] ?? [] as $part) {
		$valueNote = 'Precio del catálogo: el periodo no tiene un pago registrado';
		if ($part['source'] === 'payment') {
			$valueNote = 'Cobrado: ' . Money::format($part['amount']);
		}
		if ($part['source'] === 'payment' && $part['applied_credit'] > 0) {
			$valueNote .= ' + bonificación aplicada: ' . Money::format($part['applied_credit']);
		}
		$creditRows[] = [
			'from' => V::date(CalendarPeriod::sql($part['starts_at']), $zone),
			'until' => V::date(CalendarPeriod::sql($part['ends_at']), $zone),
			'value' => Money::format($part['value']),
			'note' => $valueNote,
			'days' =>
				number_format($part['remaining_seconds'] / 86400, 2) .
				' de ' .
				number_format($part['period_seconds'] / 86400, 2),
			'credit' => Money::format($part['credit']),
		];
	}
	$suggestedLabel = $suggested === null ? 'Sin sugerencia' : Money::format($suggested);
	$scheduledText = '';
	if ($quote['scheduled_at'] !== null) {
		$scheduledText = V::date(CalendarPeriod::sql($quote['scheduled_at']), $zone);
	}
	$confirmLabel = $kind === 'reduction' ? 'Programar el cambio' : 'Registrar el cambio de plan';
	if ($quote['charges'] && $suggested === null) {
		$priceNote =
			'No hay monto sugerido: el plan no tiene precio, la licencia es personalizada o no se conoce lo ' .
			'pagado. Escribe el monto cobrado.';
	}
}
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="<?= V::escape($licenseUrl) ?>">← <?= V::escape($license['customer_name']) ?></a>
		<h1 class="mt-3"><?= V::escape($heading) ?></h1>
		<p><?= V::escape(V::licenseTerms($license)) ?> · <?= V::escape($license['plan_name']) ?> · Fechas en <?= V::escape($zone) ?></p>
	</div>
</div>
<?php if ($quoteError !== ''): ?>
	<div class="alert alert-danger" role="alert"><?= V::escape($quoteError) ?></div>
<?php endif; ?>
<div class="row g-4">
	<div class="col-xl-8">
		<form class="panel form-panel stack-form" method="get" action="<?= V::escape($formUrl) ?>" id="plan-change-form">
			<h2>Plan y cupos nuevos</h2>
			<p class="small text-secondary mb-0"><?= V::escape($typeNotes[$type]) ?></p>
			<?php require __DIR__ . '/target-fields.php'; ?>
			<?php if ($type === 'subscription'): ?>
				<div>
					<label class="form-label" for="billing_period">Duración del periodo nuevo</label>
					<select class="form-select" id="billing_period" name="billing_period">
						<?php foreach ($periods as $value => $label): ?>
							<option
								value="<?= $value ?>"
								<?= $value === $selectedPeriod ? 'selected' : '' ?>
							><?= V::escape($label) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>
			<?php if ($type !== 'trial'): ?>
				<div>
					<label class="form-label" for="paid_at">Fecha de pago <span class="optional">vacía: este momento</span></label>
					<input class="form-control" type="datetime-local" id="paid_at" name="paid_at" value="<?= V::escape($paidLocal) ?>">
				</div>
			<?php endif; ?>
			<div>
				<button class="btn btn-outline-primary">Calcular el cambio</button>
			</div>
		</form>
		<?php if ($quote !== null): ?>
			<section class="panel form-panel mt-4 result-panel" id="plan-change-quote" aria-labelledby="plan-result">
				<h2 id="plan-result">Resultado</h2>
				<ul class="change-list">
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
					<?php foreach ($limitChanges as [$label, $before, $after]): ?>
						<li>
							<span><?= V::escape($label) ?></span>
							<del><?= $before ?></del>
							<strong><?= $after ?></strong>
						</li>
					<?php endforeach; ?>
				</ul>
				<dl class="detail-grid">
					<?php if ($quote['paid_at'] !== null): ?>
						<div>
							<dt>Fecha de pago</dt>
							<dd><?= V::escape(V::date(CalendarPeriod::sql($quote['paid_at']), $zone)) ?></dd>
						</div>
					<?php endif; ?>
					<?php if ($period !== null): ?>
						<div>
							<dt>Periodo nuevo</dt>
							<dd>
								<?= V::escape(V::date(CalendarPeriod::sql($period['period_starts_at']), $zone)) ?> al
								<?= V::escape(V::date(CalendarPeriod::sql($period['expires_at']), $zone)) ?>
							</dd>
						</div>
					<?php endif; ?>
					<?php if ($quote['scheduled_at'] !== null): ?>
						<div>
							<dt><?= $kind === 'reduction' ? 'Entra en vigor' : 'Las reducciones entran en vigor' ?></dt>
							<dd data-testid="scheduled-at"><?= V::escape($scheduledText) ?></dd>
						</div>
					<?php endif; ?>
					<?php if ($kind === 'increase' || $kind === 'perpetual'): ?>
						<div>
							<dt><?= $kind === 'perpetual' ? 'Precio perpetuo del plan actual' : 'Bonificación' ?></dt>
							<dd data-testid="credit"><?= V::escape(Money::format($quote['credit'])) ?></dd>
						</div>
					<?php endif; ?>
					<?php if ($quote['catalog'] !== null): ?>
						<div>
							<dt>Precio del plan nuevo</dt>
							<dd><?= V::escape(Money::format($quote['catalog'])) ?></dd>
						</div>
					<?php endif; ?>
					<?php if ($quote['charges']): ?>
						<div>
							<dt>Monto sugerido a cobrar</dt>
							<dd data-testid="suggested"><?= V::escape($suggestedLabel) ?></dd>
						</div>
					<?php endif; ?>
				</dl>
				<?php if ($creditRows !== []): ?>
					<h3 class="h6">Desglose de la bonificación: periodos pagados que todavía no terminan</h3>
					<div class="table-responsive">
						<table class="table table-sm table-stack mb-3" data-testid="credit-parts">
							<thead>
								<tr>
									<th>Periodo</th>
									<th>Valor del periodo</th>
									<th>Días sin usar</th>
									<th>Bonificación</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($creditRows as $row): ?>
									<tr>
										<td data-label="Periodo">
											<?= V::escape($row['from']) ?>
											<small class="cell-sub">al <?= V::escape($row['until']) ?></small>
										</td>
										<td data-label="Valor del periodo">
											<?= V::escape($row['value']) ?>
											<small class="cell-sub"><?= V::escape($row['note']) ?></small>
										</td>
										<td data-label="Días sin usar" data-testid="remaining-days"><?= V::escape($row['days']) ?></td>
										<td data-label="Bonificación"><?= V::escape($row['credit']) ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
				<p class="note-box mb-0"><?= V::escape($kindNotes[$kind]) ?></p>
				<?php if ($kind === 'increase' && $quote['down'] !== []): ?>
					<p class="small text-secondary mt-3 mb-0">
						Hasta entonces, la licencia conserva el número mayor de los cupos que bajan.
					</p>
				<?php endif; ?>
				<?php if ($license['scheduled'] !== null && $kind !== 'trial'): ?>
					<p class="small text-secondary mt-3 mb-0">
						Este cambio sustituye al cambio programado para el
						<?= V::escape(V::date($license['scheduled']['effective_at'], $zone)) ?>.
					</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>
	</div>
	<div class="col-xl-4">
		<?php if ($quote !== null): ?>
			<form
				class="panel form-panel stack-form sticky-summary"
				method="post"
				action="<?= V::escape($formUrl) ?>"
				<?= $quote['charges'] ? 'data-amount-form' : '' ?>
			>
				<?php V::fields($csrf); ?>
				<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
				<?php foreach ($hidden as $name => $value): ?>
					<input type="hidden" name="<?= V::escape($name) ?>" value="<?= V::escape($value) ?>">
				<?php endforeach; ?>
				<h2>Confirmar</h2>
				<?php if ($quote['charges']): ?>
					<?php require __DIR__ . '/payment-fields.php'; ?>
				<?php else: ?>
					<p class="small text-secondary mb-0">Sin cobro.</p>
					<label>
						Nota <span class="optional">opcional</span>
						<input class="form-control mt-2" name="reason" maxlength="500">
					</label>
				<?php endif; ?>
				<button class="btn btn-primary"><?= V::escape($confirmLabel) ?></button>
			</form>
		<?php else: ?>
			<section class="panel form-panel">
				<h2>Confirmar</h2>
				<p class="text-secondary mb-0">Elige el plan y calcula el cambio para ver el monto y confirmar.</p>
			</section>
		<?php endif; ?>
	</div>
</div>
