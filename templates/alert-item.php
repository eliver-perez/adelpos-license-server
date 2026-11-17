<?php
use AdelPos\License\Http\View as V;

// Una alerta con su licencia, lo que pasó y su revisión. Quien la incluye define $alert y $returnTo.
$alertReviewed = $alert['reviewed_at'] !== null;
$alertLicenseUrl = '/admin/licenses/' . $alert['license_text'];
$alertReviewUrl = '/admin/alerts/' . $alert['id_text'] . '/review';
$alertState = $alertReviewed ? 'Revisada' : 'Sin revisar';
$alertReview = '';
if ($alertReviewed) {
	$alertReview = 'Revisada por ' . $alert['reviewer_name'] . ' · ' . V::date($alert['reviewed_at']);
}
// La cuenta de consulta solo las ve.
$alertCanReview = !$alertReviewed && $actor->canWrite();
?>
<div class="note-box alert-item mt-3 <?= $alertReviewed ? 'alert-reviewed' : 'alert-open' ?>" data-testid="alert">
	<div class="d-flex justify-content-between gap-3 flex-wrap">
		<div>
			<strong><?= V::escape(V::alertType($alert['alert_type'])) ?></strong>
			<span class="tag ms-2 <?= $alertReviewed ? '' : 'tag-red' ?>"><?= V::escape($alertState) ?></span>
			<div class="mt-1">
				<a class="text-link" href="<?= V::escape($alertLicenseUrl) ?>"><?= V::escape($alert['customer_name']) ?></a>
			</div>
		</div>
		<small class="text-secondary">Último caso: <?= V::escape(V::date($alert['last_seen_at'])) ?></small>
	</div>
	<p class="mt-2 mb-1 small"><?= V::escape(V::alertDetail($alert)) ?></p>
	<?php if ($alertReviewed): ?>
		<small class="cell-sub"><?= V::escape($alertReview) ?></small>
	<?php endif; ?>
	<?php if ($alertCanReview): ?>
		<form method="post" action="<?= V::escape($alertReviewUrl) ?>" class="mt-2">
			<?php V::fields($csrf); ?>
			<input type="hidden" name="return_to" value="<?= V::escape($returnTo) ?>">
			<button class="btn btn-sm btn-outline-primary">Marcar como revisada</button>
		</form>
	<?php endif; ?>
</div>
