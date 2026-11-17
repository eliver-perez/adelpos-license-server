<?php
// Plan y cupos de destino de una operación comercial. Espera $plans (los que se ofrecen) y
// $selectedPlan (el plan_id elegido). Los cupos vacíos son los del plan.
use AdelPos\License\Domain\LicenseTerms;
use AdelPos\License\Http\View as V;
?>
<label>
	Plan
	<select class="form-select mt-2" name="plan_id" required>
		<?php foreach ($plans as $plan): ?>
			<?php
			$planLabel =
				$plan['display_name'] .
				' · ' .
				(int) $plan['cash_registers'] .
				' cajas · ' .
				(int) $plan['order_takers'] .
				' comanderos · ' .
				(int) $plan['kitchen_displays'] .
				' KDS';
			?>
			<option value="<?= V::escape($plan['plan_id']) ?>" <?= $plan['plan_id'] === $selectedPlan ? 'selected' : '' ?>>
				<?= V::escape($planLabel) ?>
			</option>
		<?php endforeach; ?>
	</select>
</label>
<div class="row g-2">
	<?php foreach (LicenseTerms::LIMITS as $limit => $label): ?>
		<div class="col-4">
			<label class="small">
				<?= V::escape($label) ?>
				<input
					class="form-control mt-1"
					type="number"
					name="<?= $limit ?>"
					min="0"
					max="<?= LicenseTerms::MAX_LIMIT ?>"
					step="1"
					placeholder="Del plan"
				>
			</label>
		</div>
	<?php endforeach; ?>
</div>
<p class="small text-secondary mb-0">
	Vacíos, los cupos son los del plan. Un número distinto deja la licencia personalizada y sin precio de catálogo.
</p>
