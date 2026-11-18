<?php
// Plan y cupos de destino de una operación comercial. Espera $plans (los que se ofrecen), $selectedPlan (el
// plan_id elegido) y, si se eligieron, $limitValues (cupo → número). Los cupos vacíos son los del plan.
use AdelPos\License\Domain\LicenseTerms;
use AdelPos\License\Http\View as V;

$limitValues = $limitValues ?? [];
?>
<div>
	<label class="form-label" for="target_plan">Plan</label>
	<select class="form-select" id="target_plan" name="plan_id" required>
		<?php foreach ($plans as $plan): ?>
			<?php $planLabel = $plan['display_name'] . ' · ' . V::limits($plan); ?>
			<option value="<?= V::escape($plan['plan_id']) ?>" <?= $plan['plan_id'] === $selectedPlan ? 'selected' : '' ?>>
				<?= V::escape($planLabel) ?>
			</option>
		<?php endforeach; ?>
	</select>
</div>
<div class="row g-2">
	<?php foreach (LicenseTerms::LIMITS as $limit => $label): ?>
		<div class="col-4">
			<label class="form-label small" for="target_<?= $limit ?>"><?= V::escape($label) ?></label>
			<input
				class="form-control"
				type="number"
				id="target_<?= $limit ?>"
				name="<?= $limit ?>"
				min="0"
				max="<?= LicenseTerms::MAX_LIMIT ?>"
				step="1"
				placeholder="Del plan"
				value="<?= V::escape($limitValues[$limit] ?? '') ?>"
			>
		</div>
	<?php endforeach; ?>
</div>
<p class="small text-secondary mb-0">
	Vacíos, los cupos son los del plan. Un número distinto deja la licencia personalizada y sin precio de catálogo.
</p>
