<?php

declare(strict_types = 1);

/** @var array $row */
/** @var string $value From database, normalized and converted to view format */
/** @var string $label Calculated SQL expression for the label */
/** @var array $linkParams Additional parameters for the link when $linkToAction is set */

$escapedLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

if ($linkParams !== null): ?>
    <a href="?<?= htmlspecialchars(http_build_query($linkParams), ENT_QUOTES, 'UTF-8') ?>"><?= $escapedLabel ?></a>
<?php elseif ($value === null): ?>
    <span class="null">null</span>
<?php else: ?>
    <?= $escapedLabel ?>
<?php
endif;
?>
<?php if (($row['virtual_author_id'] ?? '') !== '' || ($row['virtual_tags'] ?? '') !== ''): ?>
    <div class="content-list-meta">
        <?php if (($row['virtual_author_id'] ?? '') !== ''): ?>
            <span><?= htmlspecialchars((string)$row['virtual_author_id'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <?php if (($row['virtual_tags'] ?? '') !== ''): ?>
            <span><?= htmlspecialchars((string)$row['virtual_tags'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>
<?php endif; ?>
