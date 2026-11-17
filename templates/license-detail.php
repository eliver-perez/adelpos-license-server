<?php
use AdelPos\License\Domain\LicenseTerms;
use AdelPos\License\Http\View as V;

$licenseId = $license['id_text'];
$canChange = $actor->canWrite() && $license['commercial_status'] === 'issued';
$revoked = $license['commercial_status'] === 'revoked';
$notices = [
	'administrative' => 'Información administrativa actualizada. Los derechos y activaciones se conservaron.',
	'document' => 'Constancia generada. Disponible en el historial de documentos.',
	'renew' => 'Renovación registrada con su pago. Se firmó una revisión nueva para la computadora activa.',
	'convert' => 'Conversión registrada con su pago. Si la prueba estaba activada, la computadora recibe una revisión nueva.',
	'plan' => 'Cambio de plan registrado. Si se programó una reducción, se muestra en «Cambio programado».',
	'scheduled-cancel' => 'Cambio programado cancelado. La licencia conserva su plan y sus cupos.',
	'maintenance' => 'Mantenimiento registrado con su pago. Se firmó una revisión nueva para la computadora activa.',
	'terms' => 'Periodo inicial actualizado. Las fechas se fijan al activar la licencia.',
	'transfer-released' =>
		'Contador de autoservicio liberado. La transferencia quedó confirmada a mano y AD-EL POS puede volver a transferir.',
];
$scheduled = $license['scheduled'];
$activated = $license['period_starts_at'] !== null;
$billingPeriods = [
	'monthly' => 'Mensual (1 mes)',
	'quarterly' => 'Trimestral (3 meses)',
	'semiannual' => 'Semestral (6 meses)',
	'annual' => 'Anual (12 meses)',
];
$noticeKey = is_string($_GET['notice'] ?? null) ? $_GET['notice'] : '';
$notice = $notices[$noticeKey] ?? '';
$typeNames = ['perpetual' => 'Licencia perpetua', 'subscription' => 'Suscripción', 'trial' => 'Licencia de prueba'];
$termNotes = [
	'perpetual' => 'El uso de las versiones adquiridas no vence, aunque termine el periodo de actualizaciones.',
	'subscription' =>
		'Después del vencimiento hay ' .
		(int) $license['grace_days'] .
		' días de tolerancia. Al terminar se puede cobrar y cerrar lo capturado, pero no tomar pedidos nuevos.',
	'trial' =>
		'La prueba no tiene tolerancia. Al vencer se puede cobrar y cerrar lo capturado, ' .
		'pero no tomar pedidos nuevos.',
];
?>
<?php if ($notice): ?>
	<div class="alert alert-success" role="status"><?= V::escape($notice) ?></div>
<?php endif; ?>
<div class="page-heading">
	<div>
		<a class="text-link" href="/admin/licenses">← Licencias</a>
		<h1 class="mt-3"><?= V::escape($license['customer_name']) ?></h1>
		<p>
			<?= V::escape($license['product_name']) ?> <span class="mx-2">/</span>
			<?= V::escape($typeNames[$license['license_type']]) ?>
		</p>
	</div>
	<span class="tag large-tag <?= $revoked ? 'tag-red' : 'tag-green' ?>"><?= $revoked ? 'Revocada' : 'Emitida' ?></span>
</div>
<div class="row g-4">
	<div class="col-xl-8">
		<section class="panel form-panel">
			<h2>Derechos comerciales</h2>
			<dl class="detail-grid">
				<div class="wide">
					<dt>Identificador de licencia</dt>
					<dd class="mono"><?= V::escape($licenseId) ?></dd>
				</div>
				<div>
					<dt>Tipo</dt>
					<dd><?= V::escape(V::licenseTerms($license)) ?></dd>
				</div>
				<div>
					<dt>Plan</dt>
					<dd>
						<?= V::escape($license['plan_name']) ?>
						<?php if ($license['plan_custom']): ?>
							<span class="tag">Personalizada</span>
						<?php endif; ?>
					</dd>
				</div>
				<?php foreach (LicenseTerms::LIMITS as $limit => $label): ?>
					<div>
						<dt><?= V::escape($label) ?></dt>
						<dd><?= (int) $license[$limit] ?></dd>
					</div>
				<?php endforeach; ?>
				<div>
					<dt>Computadoras principales</dt>
					<dd>1 a la vez</dd>
				</div>
				<div>
					<dt>Inicio del periodo</dt>
					<dd><?= V::escape(V::licenseDate($license, 'period_starts_at')) ?></dd>
				</div>
				<div>
					<dt>Vencimiento</dt>
					<dd><?= V::escape(V::expiry($license)) ?></dd>
				</div>
				<div>
					<dt>Tolerancia</dt>
					<dd><?= (int) $license['grace_days'] ?> días</dd>
				</div>
				<div>
					<dt>Actualizaciones hasta</dt>
					<dd><?= V::escape(V::licenseDate($license, 'updates_until')) ?></dd>
				</div>
				<div class="wide">
					<dt>Zona horaria del cliente</dt>
					<dd><?= V::escape($license['time_zone']) ?> · las fechas de esta licencia se muestran en esta zona</dd>
				</div>
			</dl>
			<?php if ($license['plan_custom']): ?>
				<div class="alert alert-warning" role="note">
					<strong>Licencia personalizada.</strong>
					Sus cupos se ajustaron al emitirla y ya no siguen a su plan.
				</div>
			<?php endif; ?>
			<div class="note-box">
				<?= V::escape($termNotes[$license['license_type']]) ?>
			</div>
		</section>
		<section class="panel form-panel mt-4">
			<h2>Módulos</h2>
			<?php if ($canChange): ?>
				<form method="post" action="/admin/licenses/<?= V::escape($licenseId) ?>/features">
					<?php V::fields($csrf); ?>
					<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
				<?php endif; ?>
				<div class="feature-grid mt-3">
					<?php foreach ($capabilities as $feature): ?>
						<?php
						if ($feature['product_id'] !== $license['product_id']) {
							continue;
						}
						$enabled = false;
						foreach ($license['features'] as $current) {
							if ($current['capability_key'] === $feature['capability_key']) {
								$enabled = (bool) $current['enabled'];
							}
						}
						?>
						<label class="feature-choice">
							<input
								class="form-check-input"
								type="checkbox"
								name="features[]"
								value="<?= V::escape($feature['capability_key']) ?>"
								data-requires="<?= V::escape($feature['requires_keys']) ?>"
								<?= $enabled ? 'checked' : '' ?>
								<?= !$canChange ? 'disabled' : '' ?>
							>
							<span><?= V::escape($feature['display_name']) ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<?php if ($canChange): ?>
					<div class="mt-4">
						<label class="form-label" for="feature_reason">Motivo del cambio</label>
						<input class="form-control" name="reason" id="feature_reason" maxlength="500" required>
					</div>
					<button class="btn btn-outline-primary mt-3">Guardar módulos</button>
				</form>
			<?php endif; ?>
		</section>
		<?php require __DIR__ . '/license-commercial.php'; ?>
		<?php require __DIR__ . '/license-activations.php'; ?>
		<?php require __DIR__ . '/license-transfers.php'; ?>
		<?php require __DIR__ . '/license-offline.php'; ?>
		<?php require __DIR__ . '/license-payments.php'; ?>
		<section class="panel mt-4" id="commercial-history">
			<div class="panel-heading">
				<div>
					<h2>Historial comercial</h2>
					<p>Las operaciones se conservan sin editar ni sobrescribir.</p>
				</div>
			</div>
			<div class="table-responsive">
				<table class="table">
					<thead>
						<tr>
							<th>Operación</th>
							<th>Autorización</th>
							<th>Fecha</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($license['history'] as $change): ?>
							<tr>
								<td>
									<strong><?= V::escape(V::action($change['action'])) ?></strong>
									<small class="cell-sub"><?= V::escape($change['reason']) ?></small>
								</td>
								<td><?= V::escape($change['actor_name']) ?></td>
								<td><?= V::escape(V::date($change['created_at'])) ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	</div>
	<div class="col-xl-4">
		<?php if ($canChange): ?>
			<?php if ($license['license_type'] === 'subscription' && $activated): ?>
				<form
					class="panel form-panel stack-form mb-4"
					id="renew-form"
					method="get"
					action="/admin/licenses/<?= V::escape($licenseId) ?>/renew"
				>
					<span class="eyebrow">RENOVACIÓN</span>
					<h2>Renovar la suscripción</h2>
					<p class="text-secondary small mb-0">
						Si paga antes de que termine la tolerancia, el periodo nuevo empieza en el vencimiento actual. Si no,
						empieza en la fecha de pago. La licencia conserva sus cupos.
					</p>
					<label>
						Duración
						<select class="form-select mt-2" name="billing_period">
							<?php foreach ($billingPeriods as $value => $label): ?>
								<option value="<?= $value ?>" <?= $value === $license['billing_period'] ? 'selected' : '' ?>>
									<?= V::escape($label) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						Fecha de pago <span class="optional">vacía: este momento</span>
						<input class="form-control mt-2" type="datetime-local" name="paid_at">
					</label>
					<button class="btn btn-primary">Revisar renovación</button>
				</form>
			<?php endif; ?>
			<?php if ($license['license_type'] === 'trial'): ?>
				<form
					class="panel form-panel stack-form mb-4"
					id="convert-form"
					method="get"
					action="/admin/licenses/<?= V::escape($licenseId) ?>/convert"
				>
					<span class="eyebrow">CONVERSIÓN</span>
					<h2>Convertir en licencia de pago</h2>
					<p class="text-secondary small mb-0">
						Durante la prueba o después de vencida, a cualquier tipo y plan. Si ya está activada, el periodo
						nuevo empieza en la fecha de pago, sin reinstalar.
					</p>
					<label>
						Tipo
						<select class="form-select mt-2" name="license_type">
							<option value="subscription">Suscripción</option>
							<option value="perpetual">Perpetua</option>
						</select>
					</label>
					<label>
						Periodo de la suscripción
						<select class="form-select mt-2" name="billing_period">
							<?php foreach ($billingPeriods as $value => $label): ?>
								<option value="<?= $value ?>"><?= V::escape($label) ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						Actualizaciones de la perpetua
						<select class="form-select mt-2" name="updates_months">
							<option value="0">Sin actualizaciones</option>
							<option value="6" selected>6 meses</option>
							<option value="12">12 meses</option>
						</select>
					</label>
					<?php
					$selectedPlan = $license['plan_id'];
					require __DIR__ . '/target-fields.php';
					?>
					<label>
						Fecha de pago <span class="optional">vacía: este momento</span>
						<input class="form-control mt-2" type="datetime-local" name="paid_at">
					</label>
					<button class="btn btn-primary">Revisar conversión</button>
				</form>
			<?php endif; ?>
			<?php if ($scheduled !== null): ?>
				<section class="panel form-panel mb-4" id="scheduled-change">
					<span class="eyebrow">CAMBIO PROGRAMADO</span>
					<h2>
						<?= V::escape($scheduled['plan_name']) ?>
						<?php if ($scheduled['plan_custom']): ?>
							<span class="tag">Personalizada</span>
						<?php endif; ?>
					</h2>
					<p class="small mb-2">
						Entra en vigor el <?= V::escape(V::date($scheduled['effective_at'], $license['time_zone'])) ?>:
						<?= (int) $scheduled['cash_registers'] ?> cajas ·
						<?= (int) $scheduled['order_takers'] ?> comanderos ·
						<?= (int) $scheduled['kitchen_displays'] ?> KDS.
					</p>
					<p class="text-secondary small">
						Hasta entonces la licencia conserva sus cupos. Programó: <?= V::escape($scheduled['actor_name']) ?>.
					</p>
					<form method="post" action="/admin/licenses/<?= V::escape($licenseId) ?>/scheduled-cancel">
						<?php V::fields($csrf); ?>
						<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
						<button class="btn btn-outline-danger">Cancelar el cambio programado</button>
					</form>
				</section>
			<?php endif; ?>
			<?php if ($activated || $license['license_type'] === 'trial'): ?>
				<form
					class="panel form-panel stack-form mb-4"
					id="plan-change-form"
					method="get"
					action="/admin/licenses/<?= V::escape($licenseId) ?>/plan"
				>
					<span class="eyebrow">CAMBIO DE PLAN</span>
					<h2>Cambiar de plan o de cupos</h2>
					<p class="text-secondary small mb-0">
						<?php if ($license['license_type'] === 'subscription'): ?>
							Subir aplica de inmediato con un periodo nuevo desde la fecha de pago y una bonificación por lo que
							faltaba. Bajar entra en vigor al vencer lo pagado.
						<?php elseif ($license['license_type'] === 'perpetual'): ?>
							Una perpetua solo sube de plan, pagando la diferencia de los precios perpetuos.
						<?php else: ?>
							Durante la prueba, el cambio es libre y sin cobro.
						<?php endif; ?>
					</p>
					<?php
					$selectedPlan = $license['plan_id'];
					require __DIR__ . '/target-fields.php';
					?>
					<?php if ($license['license_type'] === 'subscription'): ?>
						<label>
							Duración del periodo nuevo
							<select class="form-select mt-2" name="billing_period">
								<?php foreach ($billingPeriods as $value => $label): ?>
									<option value="<?= $value ?>" <?= $value === $license['billing_period'] ? 'selected' : '' ?>>
										<?= V::escape($label) ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
					<?php if ($license['license_type'] !== 'trial'): ?>
						<label>
							Fecha de pago <span class="optional">vacía: este momento</span>
							<input class="form-control mt-2" type="datetime-local" name="paid_at">
						</label>
					<?php endif; ?>
					<button class="btn btn-primary">Revisar cambio de plan</button>
				</form>
			<?php endif; ?>
			<?php if ($license['license_type'] === 'perpetual' && $activated): ?>
				<form
					class="panel form-panel stack-form mb-4"
					id="maintenance-form"
					method="get"
					action="/admin/licenses/<?= V::escape($licenseId) ?>/maintenance"
				>
					<span class="eyebrow">MANTENIMIENTO</span>
					<h2>Comprar 12 meses de actualizaciones</h2>
					<p class="text-secondary small mb-0">
						Se cuentan desde la fecha posterior entre la fecha de pago y el fin actual de las actualizaciones.
					</p>
					<label>
						Fecha de pago <span class="optional">vacía: este momento</span>
						<input class="form-control mt-2" type="datetime-local" name="paid_at">
					</label>
					<button class="btn btn-primary">Revisar mantenimiento</button>
				</form>
			<?php endif; ?>
			<?php if (!$activated): ?>
				<section class="panel form-panel mb-4" id="initial-terms">
					<span class="eyebrow">PERIODO INICIAL</span>
					<h2>Editar antes de activar</h2>
					<p class="text-secondary small">
						Cambia el tipo, el periodo o la duración, los meses de actualizaciones, el plan y los cupos. Las
						fechas se fijan al activarla.
					</p>
					<a class="btn btn-outline-primary" href="/admin/licenses/<?= V::escape($licenseId) ?>/terms">
						Editar el periodo inicial
					</a>
				</section>
			<?php endif; ?>
			<section class="panel form-panel mt-4">
				<h2>Clave comercial</h2>
				<p class="text-secondary small">El código puede consultarse con permiso especial.</p>
				<?php if ($actor->allows('credentials.reveal')): ?>
					<details>
						<summary>Consultar código de activación</summary>
						<form
							class="stack-form mt-3"
							method="post"
							action="/admin/licenses/<?= V::escape($licenseId) ?>/reveal"
						>
							<?php V::fields($csrf); ?>
							<?php require __DIR__ . '/reauth-fields.php'; ?>
							<button class="btn btn-outline-primary">Revelar código</button>
						</form>
					</details>
				<?php endif; ?>
				<details>
					<summary>Reemplazar clave</summary>
					<form
						class="stack-form mt-3"
						method="post"
						action="/admin/licenses/<?= V::escape($licenseId) ?>/reissue"
					>
						<?php V::fields($csrf); ?>
						<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
						<label>
							Motivo
							<input class="form-control mt-2" name="reason" maxlength="500" required>
						</label>
						<label>
							Tu contraseña
							<input
								class="form-control mt-2"
								name="password"
								type="password"
								autocomplete="current-password"
								required
							>
						</label>
						<label>
							Código de autenticación nuevo
							<input
								class="form-control mt-2"
								name="code"
								inputmode="numeric"
								maxlength="6"
								autocomplete="one-time-code"
								required
							>
						</label>
						<button class="btn btn-outline-primary">Generar nueva clave</button>
					</form>
				</details>
			</section>
			<?php if ($actor->role === 'superadmin'): ?>
				<section class="panel form-panel mt-4 danger-panel">
					<h2>Revocación</h2>
					<p class="text-secondary small">
						Impide nuevas activaciones y deja constancia del motivo. Las copias desconectadas no conocen una
						revocación instantáneamente.
					</p>
					<details>
						<summary>Revocar esta licencia</summary>
						<form
							class="stack-form mt-3"
							method="post"
							action="/admin/licenses/<?= V::escape($licenseId) ?>/revoke"
							data-confirm="Se revocará esta licencia. Esta acción no tiene rehabilitación automática."
						>
							<?php V::fields($csrf); ?>
							<input type="hidden" name="version" value="<?= (int) $license['row_version'] ?>">
							<label>
								Motivo de revocación
								<textarea
									class="form-control mt-2"
									name="reason"
									rows="2"
									maxlength="500"
									required
								></textarea>
							</label>
							<label>
								Tu contraseña
								<input
									class="form-control mt-2"
									type="password"
									name="password"
									autocomplete="current-password"
									required
								>
							</label>
							<label>
								Código de autenticación nuevo
								<input
									class="form-control mt-2"
									name="code"
									maxlength="6"
									inputmode="numeric"
									autocomplete="one-time-code"
									required
								>
							</label>
							<button class="btn btn-outline-danger">Confirmar revocación</button>
						</form>
					</details>
				</section>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
