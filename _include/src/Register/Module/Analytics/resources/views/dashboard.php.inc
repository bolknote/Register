<?php
/** @var string $basePath */
/** @var callable $trans */
/** @var int $uniqueVisitorsTotal */
/** @var array{views: int, sessions: int, unique_count: int, bounces: int, engaged_seconds: int, bounce_rate: float} $todaySummary */
?>
<section
    class="register-analytics"
    data-endpoint="<?= $basePath ?>/_admin/ajax.php?action=register_analytics_series"
    data-report-endpoint="<?= $basePath ?>/_admin/ajax.php?action=register_analytics_report"
    data-page-views="<?= htmlspecialchars($trans('Page views'), ENT_QUOTES, 'UTF-8') ?>"
    data-unique-visitors="<?= htmlspecialchars($trans('Unique visitors'), ENT_QUOTES, 'UTF-8') ?>"
    data-blog-feed-readers="<?= htmlspecialchars($trans('Blog feed readers'), ENT_QUOTES, 'UTF-8') ?>"
    data-request-failed="<?= htmlspecialchars($trans('Analytics request failed'), ENT_QUOTES, 'UTF-8') ?>"
    data-load-failed="<?= htmlspecialchars($trans('Unable to load analytics'), ENT_QUOTES, 'UTF-8') ?>"
    data-day="<?= htmlspecialchars($trans('Analytics day'), ENT_QUOTES, 'UTF-8') ?>"
    data-period="<?= htmlspecialchars($trans('Analytics period'), ENT_QUOTES, 'UTF-8') ?>"
    data-period-start="<?= htmlspecialchars($trans('Analytics period start'), ENT_QUOTES, 'UTF-8') ?>"
    data-period-end="<?= htmlspecialchars($trans('Analytics period end'), ENT_QUOTES, 'UTF-8') ?>"
    data-sessions="<?= htmlspecialchars($trans('Sessions'), ENT_QUOTES, 'UTF-8') ?>"
    data-bounces="<?= htmlspecialchars($trans('Bounces'), ENT_QUOTES, 'UTF-8') ?>"
    data-page="<?= htmlspecialchars($trans('Page'), ENT_QUOTES, 'UTF-8') ?>"
    data-source="<?= htmlspecialchars($trans('Source'), ENT_QUOTES, 'UTF-8') ?>"
    data-no-data="<?= htmlspecialchars($trans('No analytics data'), ENT_QUOTES, 'UTF-8') ?>"
>
    <h2><?= $trans('Traffic') ?></h2>
    <div class="analytics-summary-list">
        <?php foreach ([
            [$uniqueVisitorsTotal, $trans('Unique visitors')],
            [$todaySummary['views'], $trans('Page views today')],
            [$todaySummary['sessions'], $trans('Sessions today')],
            [number_format($todaySummary['bounce_rate'], 1) . '%', $trans('Bounce rate today')],
        ] as [$value, $label]): ?>
            <div class="analytics-summary" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                <strong class="analytics-summary-value"><?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= $label ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="technical-data"><?= $trans('Analytics privacy note') ?></p>
    <script src="<?= $basePath ?>/_assets/register/analytics/highstock.js"></script>
    <script src="<?= $basePath ?>/_assets/register/analytics/charts.js"></script>
    <?php foreach ([
        'register-analytics-pages' => $trans('Page analytics chart'),
        'register-analytics-sessions' => $trans('Session analytics chart'),
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
    <div class="analytics-ranking-grid">
        <?php foreach ([
            'pages' => $trans('Top pages last 30 days'),
            'sources' => $trans('Top sources last 30 days'),
        ] as $ranking => $title): ?>
            <section>
                <h3><?= $title ?></h3>
                <div class="analytics-ranking" data-analytics-ranking="<?= $ranking ?>"></div>
            </section>
        <?php endforeach; ?>
    </div>
</section>
