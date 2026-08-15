<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var \S2\Cms\Admin\Dashboard\DashboardBlockProviderInterface[] $dashboardBlockProviders */

?>
<header class="dashboard-header">
    <h1 id="statistics-title"><?= $trans('Analytics') ?></h1>
</header>
<div class="dashboard-statistics" aria-labelledby="statistics-title">
    <?php foreach ($dashboardBlockProviders as $dashboardProvider) {
        echo $dashboardProvider->getHtml();
    } ?>
</div>
