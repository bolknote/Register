<?php

declare(strict_types = 1);

use Register\Content\ContentType;

/** @var string|null $value Material title */
/** @var array<string, mixed> $row Full rendered database row */
/** @var callable $trans */

$contentType = ContentType::tryFrom((string)($row['column_content_type'] ?? ''));
$contentId   = (int)($row['column_content_id'] ?? 0);
$entityName  = match ($contentType) {
    ContentType::POST => 'BlogPost',
    ContentType::PAGE => 'Article',
    null => null,
};
$title = trim((string)$value);
?>
<?php if ($entityName !== null && $contentId > 0): ?>
    <a href="?<?= http_build_query(['entity' => $entityName, 'action' => 'edit', 'id' => $contentId]) ?>">
        <?= htmlspecialchars($title !== '' ? $title : '#' . $contentId, ENT_QUOTES, 'UTF-8') ?>
    </a>
<?php else: ?>
    <span class="null"><?= htmlspecialchars($trans('Content unavailable'), ENT_QUOTES, 'UTF-8') ?></span>
<?php endif; ?>
