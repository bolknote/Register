<?php
/** @var callable $trans */
/** @var \Register\Admin\Dashboard\DashboardStatProviderInterface[] $dashboardStatProviders */
/** @var string $basePath */
?>
<header class="dashboard-header">
    <h1 id="dashboard-title"><?= $trans('Overview') ?></h1>
    <p class="dashboard-intro"><?= $trans('Overview introduction') ?></p>
    <a class="overview-section-link" href="<?= htmlspecialchars($basePath . '/', ENT_QUOTES, 'UTF-8') ?>"><?= $trans('Overview open blog') ?> →</a>
</header>
<section class="blog-overview" aria-labelledby="dashboard-title">
        <?php
        foreach ($dashboardStatProviders as $dashboardProvider) {
            echo $dashboardProvider->getHtml();
        }
        ?>
</section>
