<?php
use AdelPos\License\Http\View as V;

// Campos de cobro de una operación comercial: monto (sugerido por el catálogo), motivo si el monto es otro,
// referencia y nota. Espera $formUrl, $suggestedText y $priceNote; conserva lo escrito si hubo un error.
$amountValue = V::old('amount', $suggestedText, $formUrl);
$amountReasonValue = V::old('amount_reason', '', $formUrl);
$referenceValue = V::old('reference', '', $formUrl);
$noteValue = V::old('reason', '', $formUrl);
?>
<?php if ($priceNote !== ''): ?>
	<p class="small text-secondary mb-0" data-testid="price-note"><?= V::escape($priceNote) ?></p>
<?php endif; ?>
<label>
	Monto cobrado (MXN)
	<input
		class="form-control mt-2"
		name="amount"
		inputmode="decimal"
		maxlength="12"
		pattern="\d{1,8}(\.\d{1,2})?"
		value="<?= V::escape($amountValue) ?>"
		data-suggested="<?= V::escape($suggestedText) ?>"
		required
	>
</label>
<label>
	Motivo del cambio de monto
	<input
		class="form-control mt-2"
		name="amount_reason"
		maxlength="500"
		value="<?= V::escape($amountReasonValue) ?>"
		disabled
	>
</label>
<label>
	Referencia comercial <span class="optional">opcional</span>
	<input class="form-control mt-2" name="reference" maxlength="190" value="<?= V::escape($referenceValue) ?>">
</label>
<label>
	Nota <span class="optional">opcional</span>
	<input class="form-control mt-2" name="reason" maxlength="500" value="<?= V::escape($noteValue) ?>">
</label>
