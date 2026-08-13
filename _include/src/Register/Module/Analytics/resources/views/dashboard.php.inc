<?php
/** @var string $basePath */
?>
<section class="register-analytics" data-endpoint="<?= $basePath ?>/_admin/ajax.php?action=register_analytics_series">
    <h2>Analytics</h2>
    <p class="technical-data">Daily aggregates only. Salted visitor fingerprints are pruned when the next day receives traffic.</p>
    <script src="<?= $basePath ?>/_assets/register/analytics/highstock.js"></script>
    <script src="<?= $basePath ?>/_assets/register/analytics/charts.js"></script>
    <div id="register-analytics-pages" style="height: 320px;"></div>
    <div id="register-analytics-feeds" style="height: 320px;"></div>
</section>
