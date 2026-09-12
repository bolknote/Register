<?php

declare(strict_types = 1);

/** @var array<string, mixed> $row */
/** @var string $label */
/** @var callable $trans */

$html = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$titleUrl = $row['post_edit_url'] ?? $row['post_public_url'] ?? null;
$author = trim((string)($row['virtual_author_id'] ?? ''));
$tags = trim((string)($row['virtual_tags'] ?? ''));
?>
<div class="blog-list-title">
    <?php if ($titleUrl !== null): ?>
        <a class="blog-list-title-link" href="<?= $html($titleUrl) ?>"><?= $html($label) ?></a>
    <?php else: ?>
        <span><?= $html($label) ?></span>
    <?php endif; ?>
    <?php if ($author !== '' || $tags !== ''): ?>
        <div class="blog-list-meta">
            <?php if ($author !== ''): ?><span><?= $html($author) ?></span><?php endif; ?>
            <?php if ($tags !== ''): ?><span><?= $html($tags) ?></span><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if (($row['post_public_url'] ?? null) !== null): ?>
        <a class="blog-list-preview" href="<?= $html($row['post_public_url']) ?>"><?= $html($trans('View on site')) ?></a>
    <?php endif; ?>
</div>
