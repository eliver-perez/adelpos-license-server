<?php
use AdelPos\License\Domain\LicenseTerms;
use AdelPos\License\Http\View as V;

$availableProducts = array_values(array_filter($products, fn($product) => $product['state'] === 'active'));
$selectedProduct = $availableProducts[0]['product_id'] ?? '';
// Precio del catálogo de cada plazo, para que el formulario sugiera el monto de alta.
$priceAttributes = function (array $plan): string {
	$attributes = [];
	foreach (LicenseTerms::PRICES as $term => $column) {
		$attributes[] = 'data-price-' . $term . '="' . V::escape($plan[$column] ?? '') . '"';
	}
	return implode(' ', $attributes);
};
?>
<div class="page-heading">
	<div>
		<a class="text-link" href="/admin/licenses">← Licencias</a>
		<h1 class="mt-3">Emitir una licencia</h1>
		<p>Define los derechos del cliente y genera su clave comercial.</p>
	</div>
</div>
<?php if (!$customers || !$availableProducts || !$plans): ?>
	<div class="alert alert-info">
		Necesitas un cliente, un producto activo y un plan para emitir una licencia.
		<a href="/admin/customers/new">Registrar cliente</a>
	</div>
<?php else: ?>
	<form method="post" action="/admin/licenses/create" id="license-form">
		<div class="row g-4">
			<div class="col-xl-8">
				<section class="panel form-panel">
					<?php V::fields($csrf); ?>
					<div class="section-heading">
						<span>01</span>
						<h2>Cliente y producto</h2>
					</div>
					<div class="row g-4">
						<div class="col-md-6">
							<label for="customer_id" class="form-label">Cliente</label>
							<select class="form-select" name="customer_id" id="customer_id" required>
								<option value="">Selecciona un cliente</option>
								<?php foreach ($customers as $customer): ?>
									<option value="<?= V::escape($customer['id_text']) ?>">
										<?= V::escape($customer['display_name']) ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-6">
							<label for="product_id" class="form-label">Producto</label>
							<select class="form-select" name="product_id" id="product_id" required>
								<?php foreach ($availableProducts as $product): ?>
									<option value="<?= V::escape($product['product_id']) ?>">
										<?= V::escape($product['display_name']) ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<div class="section-heading mt-5">
						<span>02</span>
						<h2>Tipo de licencia</h2>
					</div>
					<div class="row g-3">
						<div class="col-md-4">
							<label class="choice-card">
								<input type="radio" name="license_type" value="perpetual" checked>
								<span>
									<strong>Perpetua</strong>
									<small>Pago único · Uso sin vencimiento</small>
								</span>
							</label>
						</div>
						<div class="col-md-4">
							<label class="choice-card">
								<input type="radio" name="license_type" value="subscription">
								<span>
									<strong>Suscripción</strong>
									<small>Se paga por periodo · 5 días de tolerancia</small>
								</span>
							</label>
						</div>
						<div class="col-md-4">
							<label class="choice-card">
								<input type="radio" name="license_type" value="trial">
								<span>
									<strong>Prueba</strong>
									<small>Tiempo limitado · Sin tolerancia</small>
								</span>
							</label>
						</div>
					</div>
					<div class="mt-4" data-license-type="perpetual">
						<label for="updates_months" class="form-label">Actualizaciones incluidas</label>
						<select class="form-select" id="updates_months" name="updates_months">
							<option value="0">Sin actualizaciones</option>
							<option value="6" selected>6 meses</option>
							<option value="12">12 meses</option>
						</select>
						<div class="form-text">
							Se cuentan desde la activación. Al terminar, la versión instalada sigue funcionando.
						</div>
					</div>
					<div class="mt-4" data-license-type="subscription" hidden>
						<label for="billing_period" class="form-label">Periodo de la suscripción</label>
						<select class="form-select" id="billing_period" name="billing_period" disabled>
							<option value="monthly">Mensual</option>
							<option value="quarterly">Trimestral</option>
							<option value="semiannual">Semestral</option>
							<option value="annual">Anual</option>
						</select>
						<div class="form-text">
							Se cuenta en meses de calendario. Después del vencimiento hay 5 días de tolerancia.
						</div>
					</div>
					<div class="mt-4" data-license-type="trial" hidden>
						<label for="trial_duration" class="form-label">Duración de la prueba</label>
						<select class="form-select" id="trial_duration" name="trial_duration" disabled>
							<option value="15d">15 días</option>
							<option value="1m">1 mes</option>
							<option value="3m">3 meses</option>
						</select>
						<div class="form-text">Al terminar la prueba no hay días de tolerancia.</div>
					</div>
					<div class="note-box mt-3">
						Las fechas empiezan al activar la licencia, en la zona horaria del cliente. Hasta entonces no
						tiene vencimiento asignado.
					</div>
					<div class="section-heading mt-5">
						<span>03</span>
						<h2>Plan</h2>
					</div>
					<label for="plan_id" class="form-label">Plan de la licencia</label>
					<select class="form-select" name="plan_id" id="plan_id" required>
						<?php foreach ($plans as $plan): ?>
							<option
								value="<?= V::escape($plan['plan_id']) ?>"
								data-cash_registers="<?= (int) $plan['cash_registers'] ?>"
								data-order_takers="<?= (int) $plan['order_takers'] ?>"
								data-kitchen_displays="<?= (int) $plan['kitchen_displays'] ?>"
								<?= $priceAttributes($plan) ?>
							>
								<?= V::escape($plan['display_name']) ?> · <?= (int) $plan['cash_registers'] ?>
									<?= (int) $plan['cash_registers'] === 1 ? 'caja' : 'cajas' ?> ·
									<?= (int) $plan['order_takers'] ?>
									<?= (int) $plan['order_takers'] === 1 ? 'comandero' : 'comanderos' ?> ·
									<?= (int) $plan['kitchen_displays'] ?> KDS
							</option>
						<?php endforeach; ?>
					</select>
					<div class="form-text">
						El plan llena los cupos de cajas, comanderos y pantallas de cocina (KDS) que se firman en la
						licencia. Puedes ajustarlos: la licencia queda entonces personalizada.
					</div>
					<div class="row g-3 mt-1">
						<?php foreach (LicenseTerms::LIMITS as $limit => $label): ?>
							<div class="col-md-4">
								<label for="<?= $limit ?>" class="form-label"><?= V::escape($label) ?></label>
								<input
									class="form-control"
									type="number"
									id="<?= $limit ?>"
									name="<?= $limit ?>"
									min="0"
									max="<?= LicenseTerms::MAX_LIMIT ?>"
									step="1"
									value="<?= (int) $plans[0][$limit] ?>"
									data-plan-limit
									required
								>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="alert alert-warning mt-3" id="custom-notice" role="status" hidden>
						<strong>Licencia personalizada.</strong>
						Los cupos ya no son los del plan elegido. La licencia se firmará como personalizada y dejará de
						seguir a su plan.
					</div>
					<div class="section-heading mt-5">
						<span>04</span>
						<h2>Módulos autorizados</h2>
					</div>
					<?php foreach ($availableProducts as $product): ?>
						<div
							class="feature-grid"
							data-product="<?= V::escape($product['product_id']) ?>"
							<?= $product['product_id'] !== $selectedProduct ? 'hidden' : '' ?>
						>
							<?php foreach ($capabilities as $feature): ?>
								<?php
								if ($feature['product_id'] !== $product['product_id']) {
									continue;
								}
								?>
								<label class="feature-choice">
									<input
										class="form-check-input"
										type="checkbox"
										name="features[]"
										value="<?= V::escape($feature['capability_key']) ?>"
										data-requires="<?= V::escape($feature['requires_keys']) ?>"
										<?= $product['product_id'] !== $selectedProduct ? 'disabled' : '' ?>
									>
									<span>
										<?= V::escape($feature['display_name']) ?>
										<?php if ($feature['requires_keys']): ?>
											<small>Requiere <?= V::escape($feature['requires_keys']) ?></small>
										<?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
					<div class="section-heading mt-5">
						<span>05</span>
						<h2>Pago de alta</h2>
					</div>
					<div class="row g-3">
						<div class="col-md-4">
							<label for="amount" class="form-label">Monto cobrado (MXN)</label>
							<input
								class="form-control"
								id="amount"
								name="amount"
								inputmode="decimal"
								maxlength="12"
								pattern="\d{1,8}(\.\d{1,2})?"
								placeholder="1500.00"
								required
							>
						</div>
						<div class="col-md-8">
							<label for="paid_at" class="form-label">
								Fecha de pago <span class="optional">opcional</span>
							</label>
							<input class="form-control" type="datetime-local" id="paid_at" name="paid_at">
							<div class="form-text">
								En la zona horaria del cliente. Si la dejas vacía, se usa este momento.
							</div>
						</div>
					</div>
					<div class="note-box mt-3" id="price-note" role="status"></div>
					<div class="mt-3">
						<label for="amount_reason" class="form-label">Motivo del cambio de monto</label>
						<input class="form-control" id="amount_reason" name="amount_reason" maxlength="500" disabled>
						<div class="form-text">
							Solo si cobras un monto distinto del sugerido. No se calcula IVA ni se emiten facturas.
						</div>
					</div>
					<div class="section-heading mt-5">
						<span>06</span>
						<h2>Referencia de la emisión</h2>
					</div>
					<div class="stack-form">
						<div>
							<label for="reference" class="form-label">
								Referencia comercial <span class="optional">opcional</span>
							</label>
							<input
								class="form-control"
								name="reference"
								id="reference"
								maxlength="190"
								placeholder="Pedido, factura o acuerdo"
							>
						</div>
						<div>
							<label for="reason" class="form-label">
								Motivo / nota de autorización <span class="optional">opcional</span>
							</label>
							<textarea
								class="form-control"
								name="reason"
								id="reason"
								rows="2"
								maxlength="500"
							></textarea>
						</div>
					</div>
					<div class="form-footer">
						<a
							class="btn btn-outline-secondary"
							href="/admin/licenses"
						>Cancelar</a><button class="btn btn-primary" type="submit">Emitir y mostrar clave</button>
					</div>
				</section>
			</div>
			<div class="col-xl-4">
				<aside class="panel form-panel sticky-summary">
					<span class="eyebrow">CONDICIONES DE EMISIÓN</span>
					<h2 class="mt-3">Derechos claros desde el inicio</h2>
					<ul class="check-list">
						<li>Una computadora principal a la vez por licencia</li>
						<li>Cupos de cajas, comanderos y KDS según el plan</li>
						<li>Las fechas empiezan al activar</li>
					</ul>
					<ul class="check-list">
						<li>El pago de alta queda en el historial de la licencia, en MXN</li>
					</ul>
					<div class="note-box">
						La renovación de suscripciones y la compra de mantenimiento estarán disponibles próximamente.
					</div>
					<p class="small text-secondary mt-4 mb-0">
						El código 3-4-4-4 solo se muestra a cuentas con permiso especial. Su consulta posterior requiere
						contraseña y verificación en dos pasos.
					</p>
				</aside>
			</div>
		</div>
	</form>
<?php endif; ?>
