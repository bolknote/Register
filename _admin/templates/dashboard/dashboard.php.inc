<?php
/** @var callable $trans */
/** @var \S2\Cms\Admin\Dashboard\DashboardStatProviderInterface[] $dashboardStatProviders */
/** @var \S2\Cms\Admin\Dashboard\DashboardBlockProviderInterface[] $dashboardBlockProviders */
?>
<header class="dashboard-header">
    <h1 id="dashboard-title"><?= $trans('Overview') ?></h1>
</header>
<section class="admin-stat" aria-labelledby="dashboard-title">
    <div class="stat-items">
        <?php
        foreach ($dashboardStatProviders as $dashboardProvider) {
            echo $dashboardProvider->getHtml();
        }
        ?>
    </div>
</section>
<?php
foreach ($dashboardBlockProviders as $dashboardProvider) {
    echo $dashboardProvider->getHtml();
}
?>
