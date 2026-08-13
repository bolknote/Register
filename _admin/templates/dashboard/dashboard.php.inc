<?php
/** @var callable $trans */
/** @var string $version */
/** @var \S2\Cms\Admin\Dashboard\DashboardStatProviderInterface[] $dashboardStatProviders */
/** @var \S2\Cms\Admin\Dashboard\DashboardBlockProviderInterface[] $dashboardBlockProviders */
?>
<section class="admin-stat">
    <div class="stat-items">
        <?php
        foreach ($dashboardStatProviders as $dashboardProvider) {
            echo $dashboardProvider->getHtml();
        }
        ?>
        <div class="stat-item">
            <h3><?php echo $trans('S2 version'); ?></h3>
            <a href="https://github.com/parpalak/s2" target="_blank" rel="noreferrer">Register <?= $version ?> &uarr;</a><br>
            © 2007–<?= date('Y') ?> Roman Parpalak
        </div>
    </div>
</section>
<?php
foreach ($dashboardBlockProviders as $dashboardProvider) {
    echo $dashboardProvider->getHtml();
}
?>
