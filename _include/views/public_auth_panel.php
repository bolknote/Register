<?php

declare(strict_types = 1);

/** @var callable $trans */
$auth_is_dialog = false;
$auth_title_id = 'public-auth-panel-title';
?>
<section class="public-auth-panel" aria-labelledby="public-auth-panel-title">
    <?php require __DIR__ . '/public_auth_methods.php'; ?>
</section>
