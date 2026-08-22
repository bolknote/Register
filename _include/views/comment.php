<?php

declare(strict_types = 1);

/**
 * @var callable $trans
 * @var callable $dateAndTime
 * @var string $text
 * @var int|string $time
 * @var string $nick
 * @var string $email
 * @var bool|int|string $show_email
 * @var bool|int|string $good
 * @var bool $is_author
 * @var int $id
 * @var int $i
 * @var int $depth
 * @var int $visual_depth
 * @var bool $show_addressee
 * @var array{id: int, i: int, nick: string}|null $parent
 * @var string $children
 * @var bool|null $is_preview
 * @var string|null $userpic_url
 * @var string|null $moderation_state
 * @var array<string, mixed>|null $moderation
 * @var array<string, int>|null $reaction_summary
 * @var string|null $presentation_author_url
 * @var string|null $presentation_source_url
 * @var string|null $presentation_source_label
 */

$encodedNick = register_htmlencode($nick);
$name        = !empty($show_email)
    ? \Register\Core\Helper\StringHelper::jsMailTo($encodedNick, $email)
    : $encodedNick;
$isPreview = $is_preview ?? false;
$userpicUrl = $userpic_url ?? null;
$moderationState = $moderation_state ?? 'visible';
$moderationData  = $moderation ?? null;
$isDeleted      = $moderationState === 'deleted';
$hasUserpic     = !$isDeleted;
$avatarInitials = $userpicUrl === null ? \Register\Core\Helper\StringHelper::nameInitials($nick) : '';
$avatarColor    = $userpicUrl === null ? \Register\Core\Helper\StringHelper::stablePaletteIndex($nick, 24) : 0;
$replyQuery = $isPreview ? '' : http_build_query([
    'reply_to'     => $id,
    'reply_number' => $i,
    'reply_name'   => $nick,
]);
$reactionSummary = $reaction_summary ?? [];
$authorUrl       = $presentation_author_url ?? null;
$sourceUrl       = $presentation_source_url ?? null;
$sourceLabel     = $presentation_source_label ?? '';

?>
<article class="comment-item depth-<?php echo $visual_depth, !empty($good) && !$isDeleted ? ' good' : '', $is_author && !$isDeleted ? ' by-author' : '', $isPreview ? ' comment-preview-item' : '', $hasUserpic ? ' has-userpic' : '', $isDeleted ? ' is-deleted' : '', $moderationState === 'spam' ? ' is-spam' : '', $moderationState === 'hidden' ? ' is-hidden' : ''; ?>"<?php if (!$isPreview): ?>
         id="<?php echo $i; ?>"
         data-comment-id="<?php echo $id; ?>"
         data-comment-depth="<?php echo $depth; ?>"
         data-moderation-state="<?php echo register_htmlencode($moderationState); ?>"
         role="listitem"<?php endif; ?>>
    <?php if ($isDeleted): ?>
        <div class="comment-tombstone">
            <span><?php echo $trans('Comment deleted'); ?></span>
            <a class="comment-permalink" href="#<?php echo $i; ?>" aria-label="<?php echo $trans('Comment permalink', ['%number%' => $i]); ?>">№&nbsp;<?php echo $i; ?></a>
        </div>
    <?php else: ?>
    <?php if ($moderationData !== null): ?>
        <nav class="comment-moderation" aria-label="<?php echo $trans('Comment moderation'); ?>">
            <?php if (!empty($moderationData['can_edit'])): ?>
                <button class="comment-moderation-button comment-edit-start" type="button" title="<?php echo $trans('Edit comment'); ?>" aria-label="<?php echo $trans('Edit comment'); ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M5.2 18.8 6.4 14.4 16.6 4.2a2.1 2.1 0 0 1 3 3L9.4 17.4l-4.2 1.4Z" />
                        <path d="m14.7 6.1 3.2 3.2M6.4 14.4l3 3" />
                    </svg>
                </button>
            <?php endif; ?>
            <?php if (!empty($moderationData['can_delete'])): ?>
                <form class="comment-moderation-action" method="post" action="<?php echo register_htmlencode((string)$moderationData['action_url']); ?>" data-moderation-action="delete" data-confirm="<?php echo $trans('Confirm comment deletion'); ?>">
                    <input type="hidden" name="moderation_action" value="delete">
                    <input type="hidden" name="target_type" value="<?php echo register_htmlencode((string)$moderationData['target']); ?>">
                    <input type="hidden" name="comment_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="comment_anchor" value="<?php echo $i; ?>">
                    <input type="hidden" name="moderation_token" value="<?php echo register_htmlencode((string)$moderationData['token']); ?>">
                    <input type="hidden" name="return_to" value="<?php echo register_htmlencode((string)$moderationData['return_to']); ?>">
                    <button class="comment-moderation-button" type="submit" title="<?php echo $trans('Delete comment'); ?>" aria-label="<?php echo $trans('Delete comment'); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M7.2 8.2 8 19.3h8l.8-11.1M5.2 6.2h13.6M9 6.2V4.5h6v1.7M10.2 10.3v6.4M13.8 10.3v6.4" />
                        </svg>
                    </button>
                </form>
            <?php endif; ?>
            <?php if (!empty($moderationData['can_spam'])): ?>
                <form class="comment-moderation-action" method="post" action="<?php echo register_htmlencode((string)$moderationData['action_url']); ?>" data-moderation-action="spam" data-confirm="<?php echo $trans('Confirm comment spam'); ?>">
                    <input type="hidden" name="moderation_action" value="spam">
                    <input type="hidden" name="target_type" value="<?php echo register_htmlencode((string)$moderationData['target']); ?>">
                    <input type="hidden" name="comment_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="comment_anchor" value="<?php echo $i; ?>">
                    <input type="hidden" name="moderation_token" value="<?php echo register_htmlencode((string)$moderationData['token']); ?>">
                    <input type="hidden" name="return_to" value="<?php echo register_htmlencode((string)$moderationData['return_to']); ?>">
                    <button class="comment-moderation-button" type="submit" title="<?php echo $trans('Mark comment as spam'); ?>" aria-label="<?php echo $trans('Mark comment as spam'); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="m12 3.8 6 2.5v4.9c0 4-2.3 7.1-6 9-3.7-1.9-6-5-6-9V6.3l6-2.5ZM12 8v4.5M12 16h.01" />
                        </svg>
                    </button>
                </form>
            <?php endif; ?>
            <?php if (!empty($moderationData['can_ham'])): ?>
                <form class="comment-moderation-action" method="post" action="<?php echo register_htmlencode((string)$moderationData['action_url']); ?>" data-moderation-action="ham" data-confirm="<?php echo $trans('Confirm comment ham'); ?>">
                    <input type="hidden" name="moderation_action" value="ham">
                    <input type="hidden" name="target_type" value="<?php echo register_htmlencode((string)$moderationData['target']); ?>">
                    <input type="hidden" name="comment_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="comment_anchor" value="<?php echo $i; ?>">
                    <input type="hidden" name="moderation_token" value="<?php echo register_htmlencode((string)$moderationData['token']); ?>">
                    <input type="hidden" name="return_to" value="<?php echo register_htmlencode((string)$moderationData['return_to']); ?>">
                    <button class="comment-moderation-button" type="submit" title="<?php echo $trans('Mark comment as not spam'); ?>" aria-label="<?php echo $trans('Mark comment as not spam'); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="m12 3.8 6 2.5v4.9c0 4-2.3 7.1-6 9-3.7-1.9-6-5-6-9V6.3l6-2.5Z" />
                            <path d="m9 12.2 2 2 4-4" />
                        </svg>
                    </button>
                </form>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
    <div class="comment-userpic" aria-hidden="true">
        <?php if ($userpicUrl !== null): ?>
        <img src="<?php echo register_htmlencode($userpicUrl); ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
        <?php else: ?>
        <span class="comment-userpic-fallback comment-userpic-color-<?php echo $avatarColor; ?>"><?php echo register_htmlencode($avatarInitials); ?></span>
        <?php endif; ?>
    </div>
    <header class="comment-meta">
        <span class="comment-name"><?php if ($authorUrl !== null): ?><a href="<?php echo register_htmlencode($authorUrl); ?>" rel="nofollow ugc noopener noreferrer" referrerpolicy="no-referrer"><?php echo $name; ?></a><?php else: ?><?php echo $name; ?><?php endif; ?></span>
        <?php if ($is_author): ?>
            <span class="comment-author-mark"><?php echo $trans('Site author'); ?></span>
        <?php endif; ?>
        <?php if ($moderationState === 'spam'): ?>
            <span class="comment-state-mark"><?php echo $trans('Comment is spam'); ?></span>
        <?php elseif ($moderationState === 'hidden'): ?>
            <span class="comment-state-mark"><?php echo $trans('Comment is hidden'); ?></span>
        <?php endif; ?>
        <?php if ($isPreview): ?>
            <time datetime="<?php echo gmdate(DATE_ATOM, (int)$time); ?>" data-local-time="datetime" data-locale="<?php echo register_htmlencode($trans('locale')); ?>"><?php echo $dateAndTime((int)$time); ?></time>
        <?php else: ?>
            <a class="comment-permalink" href="#<?php echo $i; ?>" aria-label="<?php echo $trans('Comment permalink', ['%number%' => $i]); ?>">
                <time datetime="<?php echo gmdate(DATE_ATOM, (int)$time); ?>" data-local-time="datetime" data-locale="<?php echo register_htmlencode($trans('locale')); ?>"><?php echo $dateAndTime((int)$time); ?></time><span aria-hidden="true">, </span><span>№&nbsp;<?php echo $i; ?></span>
            </a>
        <?php endif; ?>
    </header>
    <div class="comment-body">
        <?php if ($show_addressee && $parent !== null && $parent['nick'] !== ''): ?>
            <a class="comment-addressee" href="#<?php echo $parent['i']; ?>"><?php echo register_htmlencode($parent['nick']); ?>,</a>
        <?php endif; ?>
        <?php echo \Register\Core\Comment\CommentHtml::render($text, $trans('Wrote')); ?>
        <?php if ($reactionSummary !== []): ?>
        <div class="comment-reaction-summary" aria-label="<?php echo $trans('Reactions'); ?>">
            <?php foreach ($reactionSummary as $reactionEmoji => $reactionCount): ?>
                <span class="comment-reaction-summary-item" title="<?php echo register_htmlencode($reactionEmoji . ': ' . $reactionCount); ?>"><span aria-hidden="true"><?php echo register_htmlencode($reactionEmoji); ?></span><span><?php echo $reactionCount; ?></span></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if (!$isPreview): ?>
        <div class="comment-actions">
            <a class="comment-reply" href="?<?php echo register_htmlencode($replyQuery); ?>#add-comment"
               data-reply-comment="<?php echo $id; ?>"
               data-reply-number="<?php echo $i; ?>"
               data-reply-name="<?php echo $encodedNick; ?>"><?php echo $trans('Reply'); ?></a>
            <?php if ($sourceUrl !== null && $sourceLabel !== ''): ?>
                <a class="comment-source" href="<?php echo register_htmlencode($sourceUrl); ?>" rel="nofollow ugc noopener noreferrer" referrerpolicy="no-referrer"><?php echo register_htmlencode($sourceLabel); ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($moderationData !== null && !empty($moderationData['can_edit'])): ?>
        <form class="comment-edit-form" method="post" action="<?php echo register_htmlencode((string)$moderationData['action_url']); ?>" hidden>
            <span class="visually-hidden" id="comment-edit-<?php echo $id; ?>-label"><?php echo $trans('Your comment'); ?></span>
            <?php
            $editorId    = 'comment-edit-' . $id;
            $editorValue = $text;
            $editorRows  = 7;
            require __DIR__ . '/comment_editor.php';
            ?>
            <input type="hidden" name="moderation_action" value="edit">
            <input type="hidden" name="target_type" value="<?php echo register_htmlencode((string)$moderationData['target']); ?>">
            <input type="hidden" name="comment_id" value="<?php echo $id; ?>">
            <input type="hidden" name="comment_anchor" value="<?php echo $i; ?>">
            <input type="hidden" name="moderation_token" value="<?php echo register_htmlencode((string)$moderationData['token']); ?>">
            <input type="hidden" name="return_to" value="<?php echo register_htmlencode((string)$moderationData['return_to']); ?>">
            <div class="comment-edit-buttons">
                <button type="submit"><?php echo $trans('Save comment changes'); ?></button>
                <button class="comment-edit-cancel" type="button"><?php echo $trans('Cancel comment changes'); ?></button>
            </div>
        </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($children !== ''): ?>
        <div class="comment-children" role="list">
            <?php echo $children; ?>
        </div>
    <?php endif; ?>
</article>
