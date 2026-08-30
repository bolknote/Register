<?php
/** @var string $basePath */
/** @var callable $trans */
/** @var array{views: int, sessions: int, unique_count: int, unique_mode: string, bounces: int, engaged_seconds: int, bounce_rate: float, average_engagement: float, pages_per_session: float} $defaultSummary */
/** @var string $defaultFromDay */

$reportEndpoint = $basePath . '/_admin/ajax.php?action=register_analytics_report';
$dataAttributes = [
    'page-views'             => $trans('Page views'),
    'unique-visitors'        => $trans('Unique visitors'),
    'blog-feed-readers'      => $trans('Blog feed readers'),
    'request-failed'         => $trans('Analytics request failed'),
    'load-failed'            => $trans('Unable to load analytics'),
    'day'                    => $trans('Analytics day'),
    'sessions'               => $trans('Sessions'),
    'bounces'                => $trans('Bounces'),
    'page'                   => $trans('Page'),
    'source'                 => $trans('Source'),
    'readers'                => $trans('Readers'),
    'active-time'            => $trans('Average active time'),
    'read-seventy-five'      => $trans('Read at least 75 percent'),
    'read-complete'          => $trans('Read to the end'),
    'bounce-rate'            => $trans('Bounce rate'),
    'events'                 => $trans('Events'),
    'goal'                   => $trans('Goal'),
    'conversion'             => $trans('Conversion'),
    'author'                 => $trans('Author'),
    'section'                => $trans('Section'),
    'views-short'            => $trans('Views'),
    'compared-with-previous' => $trans('Compared with previous period'),
    'new-value'              => $trans('New value'),
    'seconds-short'          => $trans('Seconds short'),
    'source-direct'          => $trans('Direct traffic'),
    'source-internal'        => $trans('Internal traffic'),
    'source-referral'        => $trans('Referral traffic'),
    'source-search'          => $trans('Search traffic'),
    'source-social'          => $trans('Social traffic'),
    'source-campaign'        => $trans('Campaign traffic'),
    'device-desktop'         => $trans('Desktop devices'),
    'device-mobile'          => $trans('Mobile devices'),
    'device-tablet'          => $trans('Tablets'),
    'goal-view'              => $trans('Opened a page'),
    'goal-engaged-thirty'    => $trans('Read actively for 30 seconds'),
    'goal-read-seventy-five' => $trans('Read at least 75 percent'),
    'goal-read-complete'     => $trans('Read to the end'),
    'goal-outbound'          => $trans('Outbound link clicks'),
    'goal-download'          => $trans('File downloads'),
    'goal-search'            => $trans('Site searches'),
    'goal-comment'           => $trans('Comment submissions'),
    'vital-good'             => $trans('Analytics grade good'),
    'vital-needs'            => $trans('Analytics grade needs improvement'),
    'vital-poor'             => $trans('Analytics grade poor'),
    'vital-insufficient'     => $trans('Analytics grade insufficient'),
    'vital-average'          => $trans('Analytics metric average'),
    'vital-good-samples'     => $trans('Analytics good measurements'),
    'vital-no-samples'       => $trans('Analytics no measurements'),
    'vital-lcp-description'  => $trans('Analytics LCP description'),
    'vital-cls-description'  => $trans('Analytics CLS description'),
    'vital-inp-description'  => $trans('Analytics INP description'),
    'milliseconds-short'     => $trans('Milliseconds short'),
    'samples'                => $trans('Samples'),
];
?>
<section
    class="register-analytics"
    data-endpoint="<?= $basePath ?>/_admin/ajax.php?action=register_analytics_series"
    data-report-endpoint="<?= $reportEndpoint ?>"
    data-today="<?= date('Y-m-d') ?>"
    data-default-from="<?= htmlspecialchars($defaultFromDay, ENT_QUOTES, 'UTF-8') ?>"
<?php foreach ($dataAttributes as $name => $value): ?>
    data-<?= $name ?>="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
<?php endforeach; ?>
>
    <header class="analytics-dashboard-header">
        <div class="analytics-dashboard-heading">
            <p class="analytics-eyebrow"><?= $trans('First-party analytics') ?></p>
            <h2><?= $trans('Blog analytics') ?></h2>
            <p class="technical-data analytics-note"><?= $trans('Analytics privacy note') ?></p>
        </div>
        <aside class="analytics-realtime" aria-label="<?= htmlspecialchars($trans('Realtime analytics'), ENT_QUOTES, 'UTF-8') ?>">
            <span class="analytics-live-indicator" aria-hidden="true"></span>
            <span><?= $trans('Right now') ?></span>
            <strong data-analytics-realtime-visitors>0</strong>
            <span><?= $trans('Active visitors') ?></span>
            <span class="analytics-realtime-divider" aria-hidden="true"></span>
            <strong data-analytics-realtime-views>0</strong>
            <span><?= $trans('Views in 30 minutes') ?></span>
        </aside>
    </header>

    <div class="analytics-toolbar">
        <div class="analytics-range-selector" role="group" aria-label="<?= htmlspecialchars($trans('Analytics period'), ENT_QUOTES, 'UTF-8') ?>">
            <?php foreach ([
                '7' => $trans('Last 7 days'),
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
        <span class="analytics-range-caption" data-analytics-range-caption></span>
    </div>

    <div class="analytics-summary-list">
        <?php foreach ([
            'views' => [$defaultSummary['views'], $trans('Page views')],
            'unique_count' => [$defaultSummary['unique_count'], $trans('Unique visitors')],
            'sessions' => [$defaultSummary['sessions'], $trans('Sessions')],
            'pages_per_session' => [$defaultSummary['pages_per_session'], $trans('Pages per session')],
            'average_engagement' => [$defaultSummary['average_engagement'], $trans('Average active time')],
            'bounce_rate' => [$defaultSummary['bounce_rate'], $trans('Bounce rate')],
        ] as $metric => [$value, $label]): ?>
            <article class="analytics-summary" data-analytics-summary-card="<?= $metric ?>">
                <span class="analytics-summary-label"><?= $label ?></span>
                <strong class="analytics-summary-value" data-analytics-summary="<?= $metric ?>"><?= htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') ?></strong>
                <span class="analytics-summary-delta" data-analytics-summary-delta="<?= $metric ?>" hidden></span>
            </article>
        <?php endforeach; ?>
    </div>

    <p class="analytics-status" data-analytics-loading><?= $trans('Loading analytics') ?></p>
    <p class="analytics-status" data-analytics-empty hidden><?= $trans('No analytics data') ?></p>
    <p class="analytics-status analytics-status-error" data-analytics-error role="alert" hidden></p>

    <script src="<?= $basePath ?>/_assets/register/analytics/highstock.js"></script>
    <script src="<?= $basePath ?>/_assets/register/analytics/charts.js?v=<?= rawurlencode(\Register\Module\Analytics\Manifest::VERSION) ?>"></script>

    <div class="analytics-visual-grid" data-analytics-group hidden>
        <?php foreach ([
            'pages' => [
                'id' => 'register-analytics-pages',
                'title' => $trans('Audience dynamics'),
                'label' => $trans('Page analytics chart'),
            ],
            'sessions' => [
                'id' => 'register-analytics-sessions',
                'title' => $trans('Session quality'),
                'label' => $trans('Session analytics chart'),
            ],
            'feeds' => [
                'id' => 'register-analytics-feeds',
                'title' => $trans('RSS audience'),
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

    <div class="analytics-insight-grid" data-analytics-group hidden>
        <section class="analytics-panel analytics-funnel-panel" data-analytics-funnel-panel hidden>
            <header class="analytics-panel-header">
                <div>
                    <p class="analytics-eyebrow"><?= $trans('Content quality') ?></p>
                    <h3><?= $trans('Reader funnel') ?></h3>
                </div>
                <p><?= $trans('Reader funnel hint') ?></p>
            </header>
            <ol class="analytics-funnel" data-analytics-funnel></ol>
        </section>
        <section class="analytics-panel analytics-vitals-panel" data-analytics-vitals-panel hidden>
            <header class="analytics-panel-header">
                <div>
                    <p class="analytics-eyebrow"><?= $trans('Real user monitoring') ?></p>
                    <h3><?= $trans('Core Web Vitals') ?></h3>
                </div>
                <p><?= $trans('Core Web Vitals hint') ?></p>
            </header>
            <div class="analytics-vitals" data-analytics-vitals></div>
        </section>
    </div>

    <div class="analytics-ranking-grid analytics-ranking-grid-content" data-analytics-group hidden>
        <section class="analytics-ranking-panel analytics-ranking-panel-wide" data-analytics-ranking-panel="pages" hidden>
            <header class="analytics-panel-header">
                <div>
                    <p class="analytics-eyebrow"><?= $trans('Content') ?></p>
                    <h3><?= $trans('Material effectiveness') ?></h3>
                </div>
                <a class="analytics-export" data-analytics-export="pages" href="<?= $reportEndpoint ?>&amp;report=pages&amp;format=csv"><?= $trans('Download CSV') ?></a>
            </header>
            <div class="analytics-ranking" data-analytics-ranking="pages"></div>
        </section>
    </div>

    <div class="analytics-ranking-grid" data-analytics-group hidden>
        <?php foreach ([
            'sources' => [$trans('Acquisition channels'), $trans('Traffic sources')],
            'goals' => [$trans('Actions'), $trans('Goals and events')],
        ] as $ranking => [$eyebrow, $title]): ?>
            <section class="analytics-ranking-panel" data-analytics-ranking-panel="<?= $ranking ?>" hidden>
                <header class="analytics-panel-header">
                    <div>
                        <p class="analytics-eyebrow"><?= $eyebrow ?></p>
                        <h3><?= $title ?></h3>
                    </div>
                    <a class="analytics-export" data-analytics-export="<?= $ranking ?>" href="<?= $reportEndpoint ?>&amp;report=<?= $ranking ?>&amp;format=csv"><?= $trans('CSV') ?></a>
                </header>
                <div class="analytics-ranking" data-analytics-ranking="<?= $ranking ?>"></div>
            </section>
        <?php endforeach; ?>
    </div>

    <div class="analytics-ranking-grid" data-analytics-group hidden>
        <?php foreach ([
            'authors' => [$trans('Authors'), $trans('Author')],
            'sections' => [$trans('Sections'), $trans('Section')],
        ] as $ranking => [$title, $label]): ?>
            <section class="analytics-ranking-panel" data-analytics-ranking-panel="<?= $ranking ?>" hidden>
                <header class="analytics-panel-header">
                    <h3><?= $title ?></h3>
                    <a class="analytics-export" data-analytics-export="<?= $ranking ?>" href="<?= $reportEndpoint ?>&amp;report=<?= $ranking ?>&amp;format=csv"><?= $trans('CSV') ?></a>
                </header>
                <div class="analytics-ranking" data-analytics-ranking="<?= $ranking ?>" data-analytics-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"></div>
            </section>
        <?php endforeach; ?>
    </div>

    <section class="analytics-panel analytics-technology-panel" data-analytics-technology-panel hidden>
        <header class="analytics-panel-header">
            <div>
                <p class="analytics-eyebrow"><?= $trans('Audience') ?></p>
                <h3><?= $trans('Technology') ?></h3>
            </div>
            <p><?= $trans('Technology privacy hint') ?></p>
        </header>
        <div class="analytics-technology-grid">
            <?php foreach ([
                'devices' => $trans('Devices'),
                'browsers' => $trans('Browsers'),
                'systems' => $trans('Operating systems'),
            ] as $technology => $title): ?>
                <section data-analytics-technology="<?= $technology ?>" hidden>
                    <h4><?= $title ?></h4>
                    <div data-analytics-ranking="<?= $technology ?>"></div>
                </section>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="analytics-panel analytics-realtime-pages" data-analytics-realtime-pages-panel hidden>
        <header class="analytics-panel-header">
            <div>
                <p class="analytics-eyebrow"><?= $trans('Right now') ?></p>
                <h3><?= $trans('Pages being read now') ?></h3>
            </div>
        </header>
        <div data-analytics-realtime-pages></div>
    </section>
</section>
