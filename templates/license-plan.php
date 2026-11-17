<?php
use AdelPos\License\Domain\{CalendarPeriod, LicenseTerms, Money};
use AdelPos\License\Http\View as V;

$licenseId = $license['id_text'];
$zone = $quote['time_zone'];
$kind = $quote['kind'];
$target = $quote['target'];
$period = $quote['period'];
$basis = $quote['credit_basis'];
$suggested = $quote['suggested'];
$suggestedText = $suggested === null ? '' : Money::toSql($suggested);
$kindNames = [
	'trial' => 'Cambio de plan de una prueba',
	'perpetual' => 'Subida de plan de una perpetua',
	'increase' => $quote['down'] === [] ? 'Aumento' : 'Cambio mixto: sube unos cupos y baja otros',
	'reduction' => 'Reducción programada',
	'renewal' => 'Renovación con plan nuevo',
];
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
$limitsText = [];
foreach (LicenseTerms::LIMITS as $limit => $label) {
	$limitsText[] = $label . ': ' . (int) $license[$limit] . ' → ' . $target['limits'][$limit];
}
$hidden = ['plan_id' => $target['plan']['plan_id'], 'billing_period' => $quote['billing_period'] ?? ''];
foreach (LicenseTerms::LIMITS as $limit => $label) {
	$hidden[$limit] = (string) $target['limits'][$limit];
}
if ($quote['paid_at'] !== null) {
	$hidden['paid_at'] = CalendarPeriod::utc($quote['paid_at']);
}
// Cada periodo pagado que todavía no termina aporta su parte de la bonificación (decisión 3 de la etapa 4).
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
$priceNote = '';
if ($quote['charges'] && $suggested === null) {
	$priceNote = 'No hay monto sugerido: el plan no tiene precio, la licencia es personalizada o no se conoce lo pagado. ' .
		'Escribe el monto cobrado.';
}
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="/admin/licenses/<?= V::escape($licenseId) ?>">← Detalle de la licencia</a>
		<h1 class="mt-3"><?= V::escape($kindNames[$kind]) ?></h1>
		<p>
			<?= V::escape($license['customer_name']) ?> · <?= V::escape($license['plan_name']) ?> →
			<?= V::escape($target['plan']['display_name']) ?>
		</p>
	</div>
</div>
<div class="row g-4">
	<div class="col-xl-8">
		<section class="panel form-panel" id="plan-change-quote">
			<h2>Cálculo del cambio</h2>
			<dl class="detail-grid">
				<div class="wide">
					<dt>Cupos</dt>
					<dd><?= V::escape(implode(' · ', $limitsText)) ?></dd>
				</div>
				<?php if ($quote['paid_at'] !== null): ?>
					<div>
						<dt>Fecha de pago</dt>
						<dd><?= V::escape(V::date(CalendarPeriod::sql($quote['paid_at']), $zone)) ?></dd>
					</div>
				<?php endif; ?>
				<?php if ($creditRows !== []): ?>
					<div class="wide">
						<dt>Periodos pagados que todavía no terminan</dt>
						<dd>
							<div class="table-responsive">
								<table class="table table-sm mb-0" data-testid="credit-parts">
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
												<td>
													<?= V::escape($row['from']) ?>
													<small class="cell-sub">al <?= V::escape($row['until']) ?></small>
												</td>
												<td>
													<?= V::escape($row['value']) ?>
													<small class="cell-sub"><?= V::escape($row['note']) ?></small>
												</td>
												<td data-testid="remaining-days"><?= V::escape($row['days']) ?></td>
												<td><?= V::escape($row['credit']) ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</dd>
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
						<dd data-testid="suggested">
							<?= V::escape($suggested === null ? 'Sin sugerencia' : Money::format($suggested)) ?>
						</dd>
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
						<dd data-testid="scheduled-at"><?= V::escape(V::date(CalendarPeriod::sql($quote['scheduled_at']), $zone)) ?></dd>
					</div>
				<?php endif; ?>
			</dl>
			<div class="note-box"><?= V::escape($kindNotes[$kind]) ?></div>
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
	</div>
	<div class="col-xl-4">
		<form
			class="panel form-panel stack-form"
			method="post"
			action="/admin/licenses/<?= V::escape($licenseId) ?>/plan"
			<?= $quote['charges'] ? 'data-amount-form' : '' ?>
		>
			<?php V::fields($csrf); ?>
			<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
			<?php foreach ($hidden as $name => $value): ?>
				<input type="hidden" name="<?= V::escape($name) ?>" value="<?= V::escape($value) ?>">
			<?php endforeach; ?>
			<h2>Confirmar</h2>
			<?php if ($quote['charges']): ?>
				<?php if ($priceNote !== ''): ?>
					<p class="small text-secondary mb-0"><?= V::escape($priceNote) ?></p>
				<?php endif; ?>
				<label>
					Monto cobrado (MXN)
					<input
						class="form-control mt-2"
						name="amount"
						inputmode="decimal"
						maxlength="12"
						pattern="\d{1,8}(\.\d{1,2})?"
						value="<?= V::escape($suggestedText) ?>"
						data-suggested="<?= V::escape($suggestedText) ?>"
						required
					>
				</label>
				<label>
					Motivo del cambio de monto
					<input class="form-control mt-2" name="amount_reason" maxlength="500" disabled>
				</label>
				<label>
					Referencia comercial <span class="optional">opcional</span>
					<input class="form-control mt-2" name="reference" maxlength="190">
				</label>
			<?php else: ?>
				<p class="small text-secondary mb-0">Sin cobro.</p>
			<?php endif; ?>
			<label>
				Nota <span class="optional">opcional</span>
				<input class="form-control mt-2" name="reason" maxlength="500">
			</label>
			<button class="btn btn-primary"><?= $kind === 'reduction' ? 'Programar el cambio' : 'Registrar el cambio de plan' ?></button>
		</form>
	</div>
</div>
