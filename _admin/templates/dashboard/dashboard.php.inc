<?php
/** @var callable $trans */
/** @var string $version */
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
        <div class="stat-item">
            <h3><?php echo $trans('S2 version'); ?></h3>
            <a href="https://github.com/bolknote/Register" target="_blank" rel="noreferrer">Register <?= $version ?> &uarr;</a><br>
            © 2007–<?= date('Y') ?> Roman Parpalak
        </div>
    </div>
</section>
<?php
foreach ($dashboardBlockProviders as $dashboardProvider) {
    echo $dashboardProvider->getHtml();
}
?>
