<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var \Register\Core\Admin\Dashboard\SystemStatusProviderInterface[] $systemStatusProviders */

?>
<header class="dashboard-header">
    <h1 id="system-status-title"><?= $trans('System status') ?></h1>
    <p class="dashboard-intro"><?= $trans('System status intro') ?></p>
</header>
<section class="admin-stat" aria-labelledby="system-status-title">
    <div class="stat-items">
        <?php foreach ($systemStatusProviders as $systemStatusProvider) {
            echo $systemStatusProvider->getHtml();
        } ?>
    </div>
</section>
