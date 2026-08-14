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
            <h3>Register</h3>
            <a href="https://github.com/bolknote/Register" target="_blank" rel="noreferrer"><?= $trans('Register source code') ?> &uarr;</a>
            <small class="technical-data">
                <?php printf(
                    $trans('Register is based on %s by Roman Parpalak'),
                    '<a href="https://github.com/parpalak/s2" target="_blank" rel="noreferrer">S2 ' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . ' &uarr;</a>',
                ) ?>
            </small>
        </div>
    </div>
</section>
<?php
foreach ($dashboardBlockProviders as $dashboardProvider) {
    echo $dashboardProvider->getHtml();
}
?>
