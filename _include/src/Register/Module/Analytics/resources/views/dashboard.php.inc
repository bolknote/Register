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
    data-sessions="<?= htmlspecialchars($trans('Sessions'), ENT_QUOTES, 'UTF-8') ?>"
    data-bounces="<?= htmlspecialchars($trans('Bounces'), ENT_QUOTES, 'UTF-8') ?>"
    data-page="<?= htmlspecialchars($trans('Page'), ENT_QUOTES, 'UTF-8') ?>"
    data-source="<?= htmlspecialchars($trans('Source'), ENT_QUOTES, 'UTF-8') ?>"
    data-today="<?= date('Y-m-d') ?>"
    data-source-direct="<?= htmlspecialchars($trans('Direct traffic'), ENT_QUOTES, 'UTF-8') ?>"
    data-source-internal="<?= htmlspecialchars($trans('Internal traffic'), ENT_QUOTES, 'UTF-8') ?>"
    data-source-referral="<?= htmlspecialchars($trans('Referral traffic'), ENT_QUOTES, 'UTF-8') ?>"
    data-source-search="<?= htmlspecialchars($trans('Search traffic'), ENT_QUOTES, 'UTF-8') ?>"
    data-source-social="<?= htmlspecialchars($trans('Social traffic'), ENT_QUOTES, 'UTF-8') ?>"
    data-source-campaign="<?= htmlspecialchars($trans('Campaign traffic'), ENT_QUOTES, 'UTF-8') ?>"
>
    <header class="analytics-dashboard-header">
        <div>
            <h2><?= $trans('Traffic') ?></h2>
            <p class="technical-data analytics-note"><?= $trans('Analytics privacy note') ?></p>
        </div>
        <div class="analytics-range-selector" role="group" aria-label="<?= htmlspecialchars($trans('Analytics period'), ENT_QUOTES, 'UTF-8') ?>">
            <?php foreach ([
                '14' => $trans('Last 14 days'),
                '30' => $trans('Last 30 days'),
                '90' => $trans('Last 90 days'),
                'all' => $trans('All time'),
            ] as $days => $label): ?>
                <button
                    type="button"
                    data-analytics-range-days="<?= $days ?>"
                    aria-pressed="<?= (string)$days === '30' ? 'true' : 'false' ?>"
                ><?= $label ?></button>
            <?php endforeach; ?>
        </div>
    </header>
    <div class="analytics-summary-list">
        <?php foreach ([
            [$uniqueVisitorsTotal, $trans('Total unique visitors')],
            [$todaySummary['views'], $trans('Page views today')],
            [$todaySummary['sessions'], $trans('Sessions today')],
            [number_format($todaySummary['bounce_rate'], 1) . '%', $trans('Bounce rate today')],
        ] as [$value, $label]): ?>
            <article class="analytics-summary" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                <strong class="analytics-summary-value"><?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= $label ?></span>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="analytics-status" data-analytics-loading><?= $trans('Loading analytics') ?></p>
    <p class="analytics-status" data-analytics-empty hidden><?= $trans('No analytics data') ?></p>
    <p class="analytics-status analytics-status-error" data-analytics-error role="alert" hidden></p>
    <script src="<?= $basePath ?>/_assets/register/analytics/highstock.js"></script>
    <script src="<?= $basePath ?>/_assets/register/analytics/charts.js?v=<?= rawurlencode(\Register\Module\Analytics\Manifest::VERSION) ?>"></script>
    <div class="analytics-visual-grid">
        <?php foreach ([
            'pages' => [
                'id' => 'register-analytics-pages',
                'title' => $trans('Page views and visitors'),
                'label' => $trans('Page analytics chart'),
            ],
            'sessions' => [
                'id' => 'register-analytics-sessions',
                'title' => $trans('Sessions and bounces'),
                'label' => $trans('Session analytics chart'),
            ],
            'feeds' => [
                'id' => 'register-analytics-feeds',
                'title' => $trans('RSS readers'),
                'label' => $trans('Blog feed analytics chart'),
            ],
        ] as $panel => $chart): ?>
            <figure
                class="analytics-presentation<?= $panel === 'pages' ? ' analytics-presentation-primary' : '' ?>"
                data-analytics-panel="<?= $panel ?>"
                hidden
            >
                <figcaption><?= $chart['title'] ?></figcaption>
                <div
                    id="<?= $chart['id'] ?>"
                    class="analytics-chart"
                    role="img"
                    aria-label="<?= htmlspecialchars($chart['label'], ENT_QUOTES, 'UTF-8') ?>"
                ></div>
                <details class="analytics-data" hidden>
                    <summary><?= $trans('Show analytics data') ?></summary>
                    <div data-analytics-table="<?= $chart['id'] ?>"></div>
                </details>
            </figure>
        <?php endforeach; ?>
    </div>
    <div class="analytics-ranking-grid" hidden>
        <?php foreach ([
            'pages' => $trans('Popular pages'),
            'sources' => $trans('Traffic sources'),
        ] as $ranking => $title): ?>
            <section class="analytics-ranking-panel" data-analytics-ranking-panel="<?= $ranking ?>" hidden>
                <h3><?= $title ?></h3>
                <div class="analytics-ranking" data-analytics-ranking="<?= $ranking ?>"></div>
            </section>
        <?php endforeach; ?>
    </div>
</section>
