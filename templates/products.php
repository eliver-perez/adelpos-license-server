<?php
use AdelPos\License\Http\View as V;

$crumbs = [['Productos', null]];
?>
<div class="page-heading">
	<div>
		<span class="eyebrow">CATÁLOGO</span>
		<h1>Productos</h1>
		<p>Productos y capacidades disponibles para emitir licencias.</p>
	</div>
	<?php if ($actor->role === 'superadmin'): ?>
		<a href="/admin/products/new" class="btn btn-primary">＋ Nuevo producto</a>
	<?php endif; ?>
</div>
<div class="row g-4">
	<?php foreach ($products as $product): ?>
		<div class="col-xl-6">
			<section class="panel product-card">
				<div class="d-flex justify-content-between align-items-start">
					<div class="product-symbol" aria-hidden="true">◇</div>
					<?php $isActive = $product['state'] === 'active'; ?>
					<span class="tag <?= $isActive ? 'tag-green' : '' ?>"><?= $isActive ? 'Activo' : 'Archivado' ?></span>
				</div>
				<?php if ($product['product_id'] === 'adel_pos'): ?>
					<img class="product-logo" src="/assets/brand/adel-pos-tema-claro.png" alt="AD-EL POS">
				<?php else: ?>
					<h2 class="mt-4"><?= V::escape($product['display_name']) ?></h2>
				<?php endif; ?>
				<p><?= V::escape($product['description']) ?></p>
				<div class="product-footer">
					<span>
						<?= (int) $product['license_count'] ?> licencias
						<small><?= V::escape($product['product_id']) ?></small>
					</span>
					<a class="text-link" href="/admin/products/<?= V::escape($product['product_id']) ?>">Ver producto →</a>
				</div>
			</section>
		</div>
	<?php endforeach; ?>
</div>
