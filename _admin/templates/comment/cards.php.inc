<?php

declare(strict_types = 1);

/** @var list<array<string, mixed>> $rows */
/** @var bool $hasBulkSelectableRows */
/** @var callable $html */
/** @var callable $trans */
/** @var string $commentQueue */
/** @var bool $hasCommentFilters */

$stateLabels = [
    'pending' => 'Pending',
    'published' => 'Published',
    'hidden' => 'Hidden comments',
    'spam' => 'Spam',
];
?>
<?php if ($hasBulkSelectableRows): ?>
    <label class="comment-select-all"><input type="checkbox" data-bulk-select-all> <?= $html($trans('Select all rows')) ?></label>
<?php endif; ?>
<div class="comment-list" role="list">
    <?php foreach ($rows as $index => $row): ?>
        <?php
        $comment = $row['comment'];
        $selectable = $hasBulkSelectableRows && str_contains((string)($row['rendered_actions'] ?? ''), 'data-bulk-row-permitted');
        ?>
        <article class="comment-card" role="listitem" id="admin-comment-<?= $comment['id'] ?>"
                 data-comment-state="<?= $html($comment['state']) ?>" <?= $selectable ? 'data-bulk-row' : '' ?>>
            <header class="comment-card-header">
                <?php if ($selectable): ?>
                    <input type="checkbox" data-bulk-row-select
                           data-primary-key="<?= $html(json_encode($row['primary_key'], JSON_THROW_ON_ERROR)) ?>"
                           data-row-csrf-token="<?= $html((string)$row['csrf_token']) ?>"
                           aria-label="<?= $html(sprintf($trans('Select row'), $index + 1)) ?>">
                <?php endif; ?>
                <strong class="comment-author"><?= $html($comment['name']) ?></strong>
                <span class="comment-meta"><?= $row['cells']['time']['content'] ?? '' ?></span>
                <span class="comment-status is-<?= $html($comment['state']) ?>"><?= $html($trans($stateLabels[$comment['state']])) ?></span>
            </header>
            <div class="comment-context">
                <?php if ($comment['discussion_url'] !== null): ?>
                    <a href="<?= $html($comment['discussion_url']) ?>"><?= $html($comment['content_title'] !== '' ? $comment['content_title'] : $trans('Open discussion')) ?></a>
                <?php else: ?>
                    <span><?= $html($trans('Content unavailable')) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($comment['parent_text'] !== ''): ?>
                <details class="comment-parent">
                    <summary><?= $html(sprintf($trans('In reply to author'), $comment['parent_name'])) ?></summary>
                    <p><?= $html($comment['parent_text']) ?></p>
                </details>
            <?php endif; ?>
            <div class="comment-body"><?= $comment['body_html'] ?></div>
            <footer class="comment-card-footer">
                <div class="comment-actions"><?= $row['rendered_actions'] ?></div>
                <details class="comment-details">
                    <summary><?= $html($trans('Comment details')) ?></summary>
                    <dl>
                        <?php if ($comment['spam_score'] !== null): ?>
                            <dt><?= $html($trans('Spam score')) ?></dt><dd><?= (int)$comment['spam_score'] ?></dd>
                            <dt><?= $html($trans('Spam reasons')) ?></dt><dd><?= $row['cells']['spam_reasons']['content'] ?? '—' ?></dd>
                        <?php endif; ?>
                        <?php if ($comment['email'] !== null && $comment['email'] !== ''): ?>
                            <dt><?= $html($trans('Email')) ?></dt><dd><?= $html($comment['email']) ?></dd>
                        <?php endif; ?>
                        <?php if ($comment['ip'] !== null && $comment['ip'] !== ''): ?>
                            <dt><?= $html($trans('IP address')) ?></dt><dd><?= $html($comment['ip']) ?></dd>
                        <?php endif; ?>
                        <?php if (isset($row['cells']['good'])): ?>
                            <dt><?= $html($trans('Good comment')) ?></dt><dd><?= $row['cells']['good']['content'] ?></dd>
                        <?php endif; ?>
                    </dl>
                    <?php if ($comment['discussion_url'] !== null): ?>
                        <a href="<?= $html($comment['discussion_url']) ?>"><?= $html($trans('Open discussion')) ?></a>
                    <?php endif; ?>
                </details>
            </footer>
        </article>
    <?php endforeach; ?>
</div>
<?php if ($rows === []): ?>
    <div class="list-empty-state">
        <strong><?= $html($trans($hasCommentFilters ? 'No matching results' : ($commentQueue === 'pending' ? 'No comments awaiting review' : 'No matching comments'))) ?></strong>
    </div>
<?php endif; ?>
