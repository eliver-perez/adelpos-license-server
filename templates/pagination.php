<?php
use AdelPos\License\Http\View as V;

$previousPage = '?' . V::escape(http_build_query($filters + ['page' => $listing['page'] - 1]));
$nextPage = '?' . V::escape(http_build_query($filters + ['page' => $listing['page'] + 1]));
?>
<div class="pagination-bar">
	<span>
		<?= (int) $listing['total'] ?> registros · Página <?= (int) $listing['page'] ?> de <?= (int) $listing['pages'] ?>
	</span>
	<nav aria-label="Paginación" class="d-flex gap-2">
		<?php if ($listing['page'] > 1): ?>
			<a class="btn btn-sm btn-outline-secondary" href="<?= $previousPage ?>">Anterior</a>
		<?php endif; ?>
		<?php if ($listing['page'] < $listing['pages']): ?>
			<a class="btn btn-sm btn-outline-secondary" href="<?= $nextPage ?>">Siguiente</a>
		<?php endif; ?>
	</nav>
</div>
