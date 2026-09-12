<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var array<string, mixed> $overview */
/** @var int $now */
/** @var bool $canWrite */
/** @var bool $canModerate */

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$postLink = static function (array $post) use ($escape, $trans): string {
    $title = $escape($post['title'] !== '' ? $post['title'] : $trans('Overview untitled'));
    return $post['url'] === null
        ? '<span>' . $title . '</span> <span class="overview-muted">(' . $trans('Overview missing address') . ')</span>'
        : '<a href="' . $escape($post['url']) . '">' . $title . '</a>';
};
$date = static function (int $timestamp) use ($escape): string {
    return '<time data-local-time datetime="' . gmdate('Y-m-d\TH:i:s\Z', $timestamp) . '">'
        . $escape(gmdate('Y-m-d H:i \U\T\C', $timestamp)) . '</time>';
};
?>
<div class="overview-editorial">
    <?php if ($canModerate): ?>
    <section class="overview-panel overview-moderation" aria-labelledby="overview-moderation-title">
        <header class="overview-panel-header">
            <h2 id="overview-moderation-title"><?= $trans('Overview moderation') ?> <span class="overview-count"><?= (int)$overview['pendingCount'] ?></span></h2>
        </header>
        <p class="overview-muted"><?= $trans('Overview moderation hint') ?></p>
        <?php if ($overview['pending'] === []): ?>
            <p class="overview-empty"><?= $trans('Overview moderation empty') ?></p>
        <?php else: ?>
            <ol class="overview-list">
            <?php foreach ($overview['pending'] as $comment): ?>
                <li class="overview-item">
                    <div class="overview-item-meta"><strong><?= $escape($comment['nick']) ?></strong> · <?= $date((int)$comment['time']) ?></div>
                    <a class="overview-comment-link" href="<?= $escape($comment['url']) ?>"><?= $escape($comment['snippet']) ?></a>
                    <span class="overview-muted"><?= $escape($comment['title']) ?></span>
                </li>
            <?php endforeach; ?>
            </ol>
            <?php if ($overview['pendingCount'] > count($overview['pending'])): ?>
                <p class="overview-muted"><?= $trans('Overview first pending', ['%shown%' => count($overview['pending']), '%total%' => (int)$overview['pendingCount']]) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="overview-panel overview-comments" aria-labelledby="overview-comments-title">
        <header class="overview-panel-header"><h2 id="overview-comments-title"><?= $trans('Overview recent comments') ?></h2></header>
        <p class="overview-muted"><?= $trans('Overview comments hint') ?></p>
        <?php if ($overview['comments'] === []): ?>
            <p class="overview-empty"><?= $trans('Overview comments empty') ?></p>
        <?php else: ?>
            <ol class="overview-list">
            <?php foreach ($overview['comments'] as $comment): ?>
                <li class="overview-item">
                    <div class="overview-item-meta"><strong><?= $escape($comment['nick']) ?></strong> · <?= $date((int)$comment['time']) ?></div>
                    <a class="overview-comment-link" href="<?= $escape($comment['url']) ?>"><?= $escape($comment['snippet']) ?></a>
                    <span class="overview-muted"><?= $escape($comment['title']) ?></span>
                </li>
            <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <?php if ($canWrite): ?>
    <section class="overview-panel overview-drafts" aria-labelledby="overview-drafts-title">
        <header class="overview-panel-header">
            <h2 id="overview-drafts-title"><?= $trans('Overview continue writing') ?> <span class="overview-count"><?= (int)$overview['queue']['drafts'] ?></span></h2>
            <a class="overview-section-link" href="?entity=BlogPost&amp;action=list&amp;is_active=0&amp;apply_filter=1"><?= $trans('Overview unpublished posts') ?></a>
        </header>
        <p class="overview-muted"><?= $trans('Overview drafts hint') ?></p>
        <?php if ($overview['drafts'] === []): ?>
            <p class="overview-empty"><?= $trans('Overview drafts empty') ?></p>
        <?php else: ?>
            <ol class="overview-list">
            <?php foreach ($overview['drafts'] as $post): ?>
                <li class="overview-item">
                    <?= $postLink($post) ?>
                    <span class="overview-item-meta"><?= $trans('Overview edited') ?> · <?= $date((int)$post['updated_at']) ?></span>
                </li>
            <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <section class="overview-panel overview-schedule" aria-labelledby="overview-schedule-title">
        <header class="overview-panel-header"><h2 id="overview-schedule-title"><?= $trans('Overview publication plan') ?></h2></header>
        <?php if ($overview['queue']['overdue'] > 0): ?>
            <p class="overview-warning" role="status"><?= $trans('Overview overdue', ['%count%' => (int)$overview['queue']['overdue']]) ?></p>
        <?php endif; ?>
        <?php if ($overview['scheduled'] === []): ?>
            <p class="overview-empty"><?= $trans('Overview schedule empty') ?></p>
        <?php else: ?>
            <p class="overview-muted"><?= $trans('Overview scheduled total', ['%count%' => (int)$overview['queue']['scheduled']]) ?></p>
            <ol class="overview-list">
            <?php foreach ($overview['scheduled'] as $post): ?>
                <li class="overview-item">
                    <?= $postLink($post) ?>
                    <span class="overview-item-meta<?= (int)$post['scheduled_at'] <= $now ? ' overview-warning' : '' ?>"><?= $date((int)$post['scheduled_at']) ?></span>
                </li>
            <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="overview-panel overview-recent-posts" aria-labelledby="overview-recent-title">
        <header class="overview-panel-header">
            <h2 id="overview-recent-title"><?= $trans('Overview recent posts') ?></h2>
            <a class="overview-section-link" href="?entity=BlogPost&amp;action=list"><?= $trans('Overview all posts') ?></a>
        </header>
        <?php if ($overview['recent'] === []): ?>
            <p class="overview-empty"><?= $trans('Overview posts empty') ?></p>
        <?php else: ?>
            <ol class="overview-list">
            <?php foreach ($overview['recent'] as $post): ?>
                <li class="overview-item">
                    <?= $postLink($post) ?>
                    <span class="overview-item-meta"><?= $date((int)$post['published_at']) ?></span>
                </li>
            <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>
