<?php

declare(strict_types = 1);

/** @var string|null $value */
/** @var array<string, mixed> $row */
/** @var callable $trans */

if ($value === null) {
    echo htmlspecialchars($trans('Comment unavailable'), ENT_QUOTES, 'UTF-8');
    return;
}

$commentId = (int)$row['column_comment_id'];
$author = (string)($row['virtual_comment_author'] ?? '');
$plainText = \Register\Core\Comment\CommentHtml::plainText($value, false);
$text = mb_substr($plainText, 0, 280) . (mb_strlen($plainText) > 280 ? '…' : '');
?>
<div class="antispam-comment">
    <a href="?entity=Comment&amp;action=list&amp;queue=all&amp;apply_filter=0&amp;comment_id=<?= $commentId ?>#admin-comment-<?= $commentId ?>">
        <?= htmlspecialchars($author !== '' ? $author : $trans('Comment'), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <p><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?></p>
</div>
