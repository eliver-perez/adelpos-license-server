<?php use AdelPos\License\Http\View as V; ?>
<div class="page-heading"><div><span class="eyebrow">SEGUNDO FACTOR VERIFICADO</span><h1>¿Deseas confiar en este dispositivo?</h1><p>Podrás iniciar sesión durante los próximos 30 días sin introducir nuevamente el código de Authenticator. Tu usuario y contraseña seguirán siendo obligatorios.</p></div></div>
<section class="panel form-panel">
    <p>Confía únicamente en un dispositivo de uso personal. Puedes revocar esta autorización desde Seguridad de tu cuenta.</p>
    <?php if ($error !== null): ?><div class="alert alert-warning" role="alert"><?= V::escape($error) ?></div><?php endif; ?>
    <?php if (!$https): ?><div class="alert alert-warning">Para guardar la confianza necesitas una conexión HTTPS. Puedes continuar al panel con «Ahora no».</div><?php endif; ?>
    <form method="post" action="/trust-device" class="stack-form">
        <?php V::fields($csrf); ?>
        <button class="btn btn-primary" type="submit" name="choice" value="trust" <?= $https ? '' : 'disabled' ?>>Confiar en este dispositivo</button>
        <button class="btn btn-outline-secondary" type="submit" name="choice" value="skip">Ahora no</button>
    </form>
</section>
