<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $postListState */
/** @var array<string, int> $postListCounts */
/** @var array<string, mixed> $filterData */

$postTabs = [
    'all' => 'All',
    'draft' => 'Drafts',
    'scheduled' => 'Scheduled posts',
    'published' => 'Published posts',
];
$tabFilterData = array_intersect_key($filterData, array_flip(['search', 'tags', 'author_id', 'created_from', 'created_to']));
ob_start();
?>
<nav class="section-tabs blog-list-tabs" aria-label="<?= htmlspecialchars($trans('Publication status'), ENT_QUOTES, 'UTF-8') ?>">
    <?php foreach ($postTabs as $state => $label): ?>
        <a href="?<?= htmlspecialchars(http_build_query([
            'entity' => 'BlogPost',
            'action' => 'list',
            ...$tabFilterData,
            'state' => $state,
            'apply_filter' => '1',
        ]), ENT_QUOTES, 'UTF-8') ?>"
           <?= $postListState === $state || ($state === 'scheduled' && $postListState === 'overdue') ? 'aria-current="page"' : '' ?>>
            <?= htmlspecialchars($trans($label), ENT_QUOTES, 'UTF-8') ?>
            <span class="section-tab-count"><?= (int)($postListCounts[$state] ?? 0) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php if ($postListState === 'unpublished' || $postListState === 'overdue'): ?>
    <p class="list-context-note"><?= htmlspecialchars($trans($postListState === 'overdue' ? 'Publication overdue' : 'Unpublished posts'), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>
<?php
$listIntroHtml = (string)ob_get_clean();
require __DIR__ . '/admin-yard/list.php.inc';
