<?php
/** @var string $basePath */
/** @var callable $trans */
/** @var int $uniqueVisitorsTotal */
?>
<section
    class="register-analytics"
    data-endpoint="<?= $basePath ?>/_admin/ajax.php?action=register_analytics_series"
    data-page-views="<?= htmlspecialchars($trans('Page views'), ENT_QUOTES, 'UTF-8') ?>"
    data-unique-visitors="<?= htmlspecialchars($trans('Unique visitors'), ENT_QUOTES, 'UTF-8') ?>"
    data-blog-feed-readers="<?= htmlspecialchars($trans('Blog feed readers'), ENT_QUOTES, 'UTF-8') ?>"
    data-request-failed="<?= htmlspecialchars($trans('Analytics request failed'), ENT_QUOTES, 'UTF-8') ?>"
    data-load-failed="<?= htmlspecialchars($trans('Unable to load analytics'), ENT_QUOTES, 'UTF-8') ?>"
    data-day="<?= htmlspecialchars($trans('Analytics day'), ENT_QUOTES, 'UTF-8') ?>"
>
    <h2><?= $trans('Analytics') ?></h2>
    <div class="analytics-summary" aria-label="<?= htmlspecialchars($trans('Unique visitors'), ENT_QUOTES, 'UTF-8') ?>">
        <strong class="analytics-summary-value"><?= $uniqueVisitorsTotal ?></strong>
        <span><?= $trans('Unique visitors') ?></span>
    </div>
    <p class="technical-data"><?= $trans('Analytics privacy note') ?></p>
    <script src="<?= $basePath ?>/_assets/register/analytics/highstock.js"></script>
    <script src="<?= $basePath ?>/_assets/register/analytics/charts.js"></script>
    <?php foreach ([
        'register-analytics-pages' => $trans('Page analytics chart'),
        'register-analytics-feeds' => $trans('Blog feed analytics chart'),
    ] as $chartId => $chartLabel): ?>
        <figure class="analytics-presentation">
            <div
                id="<?= $chartId ?>"
                class="analytics-chart"
                role="img"
                aria-label="<?= htmlspecialchars($chartLabel, ENT_QUOTES, 'UTF-8') ?>"
            ></div>
            <details class="analytics-data" hidden>
                <summary><?= $trans('Show analytics data') ?></summary>
                <div data-analytics-table="<?= $chartId ?>"></div>
            </details>
        </figure>
    <?php endforeach; ?>
</section>
