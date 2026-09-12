<?php
/** @var string $basePath */
/** @var callable $trans */
/** @var bool $canViewAnalytics */
/** @var array{from: string, to: string, previous_from: string, previous_to: string, views: int, previous_views: int, daily_readers: float, previous_daily_readers: float, posts: list<array{path: string, title: string, views: int}>} $audience */

$encode = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="overview-audience">
    <section class="overview-panel" aria-labelledby="overview-audience-title">
        <header class="overview-panel-header">
            <h2 id="overview-audience-title"><?= $trans('Overview audience') ?></h2>
            <?php if ($canViewAnalytics): ?>
                <a class="overview-section-link" href="<?= $encode($basePath) ?>/_admin/index.php?entity=Statistics"><?= $trans('Overview all analytics') ?></a>
            <?php endif; ?>
        </header>
        <p class="overview-muted"><?= $trans('Overview complete week') ?> · <?= $encode($audience['from']) ?> — <?= $encode($audience['to']) ?></p>
        <?php if ($audience['views'] === 0 && $audience['previous_views'] === 0): ?>
            <p class="overview-empty"><?= $trans('Overview audience empty') ?></p>
        <?php else: ?>
            <div class="overview-metrics">
                <?php foreach ([
                    [$audience['views'], $audience['previous_views'], $trans('Overview page views')],
                    [$audience['daily_readers'], $audience['previous_daily_readers'], $trans('Overview daily readers')],
                ] as [$value, $previous, $label]): ?>
                    <div class="overview-metric">
                        <span><?= $label ?></span>
                        <strong><?= $encode((string)$value) ?></strong>
                        <span class="overview-delta"><?= $trans('Overview previous period', ['%value%' => $encode((string)$previous)]) ?><?php if ($previous > 0): ?> · <?= $value > $previous ? '+' : '' ?><?= round(100 * ($value - $previous) / $previous, 1) ?>%<?php endif; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="overview-muted"><?= $trans('Overview previous dates', ['%from%' => $encode($audience['previous_from']), '%to%' => $encode($audience['previous_to'])]) ?></p>
            <p class="overview-muted"><?= $trans('Overview daily readers note') ?></p>
        <?php endif; ?>
    </section>
    <section class="overview-panel" aria-labelledby="overview-popular-title">
        <header class="overview-panel-header">
            <h2 id="overview-popular-title"><?= $trans('Overview popular posts') ?></h2>
            <span class="overview-muted"><?= $trans('Overview page views') ?></span>
        </header>
        <?php if ($audience['posts'] === []): ?>
            <p class="overview-empty"><?= $trans('Overview popular posts empty') ?></p>
        <?php else: ?>
            <ol class="overview-list">
                <?php foreach ($audience['posts'] as $post): ?>
                    <?php
                    $safePath = str_starts_with($post['path'], '/')
                        && !str_starts_with($post['path'], '//')
                        && preg_match('/[\\\\\x00-\x20\x7f]/', $post['path']) !== 1;
                    $title = $post['title'] !== '' ? $post['title'] : $post['path'];
                    ?>
                    <li class="overview-item">
                        <?php if ($safePath): ?>
                            <a href="<?= $encode($post['path']) ?>"><?= $encode($title) ?></a>
                        <?php else: ?>
                            <span><?= $encode($title) ?></span>
                        <?php endif; ?>
                        <strong><?= $post['views'] ?></strong>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>
