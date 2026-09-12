<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $commentQueue */
/** @var array<string, int> $commentQueueCounts */
/** @var array<string, mixed> $filterData */

$html = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$listContextParams = ['queue' => $commentQueue];
foreach (['content_id', 'comment_id'] as $contextKey) {
    if (($filterData[$contextKey] ?? '') !== '') {
        $listContextParams[$contextKey] = $filterData[$contextKey];
    }
}
$listBodyTemplate = __DIR__ . '/cards.php.inc';
$queueFilters = array_filter(
    $filterData,
    static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== [],
);
unset($queueFilters['queue']);
$hasCommentFilters = $queueFilters !== [];

ob_start();
?>
<nav class="section-tabs comment-queues" aria-label="<?= $html($trans('Comment queues')) ?>">
    <?php foreach ([
        'pending' => 'Pending',
        'published' => 'Published comments',
        'hidden' => 'Hidden comments',
        'spam' => 'Spam',
        'all' => 'All comments',
    ] as $queue => $label): ?>
        <a href="?<?= $html(http_build_query(['entity' => 'Comment', 'action' => 'list', ...$queueFilters, 'queue' => $queue, 'apply_filter' => '0'])) ?>"
           <?= $queue === $commentQueue ? 'aria-current="page"' : '' ?>>
            <?= $html($trans($label)) ?> <span class="comment-queue-count"><?= (int)$commentQueueCounts[$queue] ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php if (isset($listContextParams['content_id']) || isset($listContextParams['comment_id'])): ?>
    <p class="comment-context-filter"><?= $html($trans('Comments from the selected context')) ?>
        <a href="?entity=Comment&amp;action=list&amp;queue=all&amp;apply_filter=0"><?= $html($trans('Show all comments')) ?></a>
    </p>
<?php endif; ?>
<?php
$listIntroHtml = (string)ob_get_clean();
require __DIR__ . '/../admin-yard/list.php.inc';
