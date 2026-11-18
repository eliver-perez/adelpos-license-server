<?php
use AdelPos\License\Http\View as V;

// Una alerta con su licencia, lo que pasó y su revisión. Quien la incluye define $alert y $returnTo.
// Las fechas van en la zona horaria del cliente de la licencia.
$alertReviewed = $alert['reviewed_at'] !== null;
$alertZone = $alert['time_zone'] ?? null;
$alertLicenseUrl = '/admin/licenses/' . $alert['license_text'] . '?section=computers';
$alertReviewUrl = '/admin/alerts/' . $alert['id_text'] . '/review';
$alertState = $alertReviewed ? 'Revisada' : 'Sin revisar';
$alertReview = '';
if ($alertReviewed) {
	$alertReview = 'Revisada por ' . $alert['reviewer_name'] . ' · ' . V::date($alert['reviewed_at'], $alertZone);
}
// La cuenta de consulta solo las ve.
$alertCanReview = !$alertReviewed && $actor->canWrite();
?>
<article class="alert-item <?= $alertReviewed ? 'alert-reviewed' : 'alert-open' ?>" data-testid="alert">
	<div class="alert-item-head">
		<div>
			<strong><?= V::escape(V::alertType($alert['alert_type'])) ?></strong>
			<?= V::badge($alertState, $alertReviewed ? 'gray' : 'amber') ?>
			<div class="mt-1">
				<a class="text-link" href="<?= V::escape($alertLicenseUrl) ?>"><?= V::escape($alert['customer_name']) ?></a>
			</div>
		</div>
		<small class="text-secondary">Último caso: <?= V::escape(V::date($alert['last_seen_at'], $alertZone)) ?></small>
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
</article>
