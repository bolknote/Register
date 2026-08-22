<?php

declare(strict_types = 1);

/** @var callable $trans */
?>
<section class="public-auth-panel" aria-labelledby="public-auth-panel-title">
    <header class="public-auth-panel-header">
        <h2 id="public-auth-panel-title"><?php echo $trans('Choose a sign-in method'); ?></h2>
    </header>
    <?php require __DIR__ . '/public_auth_methods.php'; ?>
</section>
