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
 */

$encodedNick = s2_htmlencode($nick);
$name        = !empty($show_email)
    ? \S2\Cms\Helper\StringHelper::jsMailTo($encodedNick, $email)
    : $encodedNick;
$isPreview = $is_preview ?? false;
$userpicUrl = $userpic_url ?? null;
$moderationState = $moderation_state ?? 'visible';
$moderationData  = $moderation ?? null;
$isDeleted      = $moderationState === 'deleted';
$hasUserpic     = !$isDeleted && $userpicUrl !== null;
$replyQuery = $isPreview ? '' : http_build_query([
    'reply_to'     => $id,
    'reply_number' => $i,
    'reply_name'   => $nick,
]);

?>
<article class="comment-item depth-<?php echo $visual_depth, !empty($good) && !$isDeleted ? ' good' : '', $is_author && !$isDeleted ? ' by-author' : '', $isPreview ? ' comment-preview-item' : '', $hasUserpic ? ' has-userpic' : '', $isDeleted ? ' is-deleted' : '', $moderationState === 'spam' ? ' is-spam' : '', $moderationState === 'hidden' ? ' is-hidden' : ''; ?>"<?php if (!$isPreview): ?>
         id="<?php echo $i; ?>"
         data-comment-id="<?php echo $id; ?>"
         data-comment-depth="<?php echo $depth; ?>"
         data-moderation-state="<?php echo s2_htmlencode($moderationState); ?>"
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
                <button class="comment-moderation-button comment-edit-start" type="button" title="<?php echo $trans('Edit comment'); ?>" aria-label="<?php echo $trans('Edit comment'); ?>"><span aria-hidden="true">✏️</span></button>
            <?php endif; ?>
            <?php if (!empty($moderationData['can_delete'])): ?>
                <form class="comment-moderation-action" method="post" action="<?php echo s2_htmlencode((string)$moderationData['action_url']); ?>" data-moderation-action="delete" data-confirm="<?php echo $trans('Confirm comment deletion'); ?>">
                    <input type="hidden" name="moderation_action" value="delete">
                    <input type="hidden" name="target_type" value="<?php echo s2_htmlencode((string)$moderationData['target']); ?>">
                    <input type="hidden" name="comment_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="comment_anchor" value="<?php echo $i; ?>">
                    <input type="hidden" name="moderation_token" value="<?php echo s2_htmlencode((string)$moderationData['token']); ?>">
                    <input type="hidden" name="return_to" value="<?php echo s2_htmlencode((string)$moderationData['return_to']); ?>">
                    <button class="comment-moderation-button" type="submit" title="<?php echo $trans('Delete comment'); ?>" aria-label="<?php echo $trans('Delete comment'); ?>"><span aria-hidden="true">🗑️</span></button>
                </form>
            <?php endif; ?>
            <?php if (!empty($moderationData['can_spam'])): ?>
                <form class="comment-moderation-action" method="post" action="<?php echo s2_htmlencode((string)$moderationData['action_url']); ?>" data-moderation-action="spam" data-confirm="<?php echo $trans('Confirm comment spam'); ?>">
                    <input type="hidden" name="moderation_action" value="spam">
                    <input type="hidden" name="target_type" value="<?php echo s2_htmlencode((string)$moderationData['target']); ?>">
                    <input type="hidden" name="comment_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="comment_anchor" value="<?php echo $i; ?>">
                    <input type="hidden" name="moderation_token" value="<?php echo s2_htmlencode((string)$moderationData['token']); ?>">
                    <input type="hidden" name="return_to" value="<?php echo s2_htmlencode((string)$moderationData['return_to']); ?>">
                    <button class="comment-moderation-button" type="submit" title="<?php echo $trans('Mark comment as spam'); ?>" aria-label="<?php echo $trans('Mark comment as spam'); ?>"><span aria-hidden="true">🚫</span></button>
                </form>
            <?php endif; ?>
            <?php if (!empty($moderationData['can_ham'])): ?>
                <form class="comment-moderation-action" method="post" action="<?php echo s2_htmlencode((string)$moderationData['action_url']); ?>" data-moderation-action="ham" data-confirm="<?php echo $trans('Confirm comment ham'); ?>">
                    <input type="hidden" name="moderation_action" value="ham">
                    <input type="hidden" name="target_type" value="<?php echo s2_htmlencode((string)$moderationData['target']); ?>">
                    <input type="hidden" name="comment_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="comment_anchor" value="<?php echo $i; ?>">
                    <input type="hidden" name="moderation_token" value="<?php echo s2_htmlencode((string)$moderationData['token']); ?>">
                    <input type="hidden" name="return_to" value="<?php echo s2_htmlencode((string)$moderationData['return_to']); ?>">
                    <button class="comment-moderation-button" type="submit" title="<?php echo $trans('Mark comment as not spam'); ?>" aria-label="<?php echo $trans('Mark comment as not spam'); ?>"><span aria-hidden="true">✅</span></button>
                </form>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
    <?php if ($hasUserpic): ?>
        <div class="comment-userpic" aria-hidden="true">
            <img src="<?php echo s2_htmlencode($userpicUrl); ?>" alt="" width="40" height="40" loading="lazy" decoding="async">
        </div>
    <?php endif; ?>
    <header class="comment-meta">
        <span class="comment-name"><?php echo $name; ?></span>
        <?php if ($is_author): ?>
            <span class="comment-author-mark"><?php echo $trans('Site author'); ?></span>
        <?php endif; ?>
        <?php if ($moderationState === 'spam'): ?>
            <span class="comment-state-mark"><?php echo $trans('Comment is spam'); ?></span>
        <?php elseif ($moderationState === 'hidden'): ?>
            <span class="comment-state-mark"><?php echo $trans('Comment is hidden'); ?></span>
        <?php endif; ?>
        <?php if ($isPreview): ?>
            <time datetime="<?php echo gmdate(DATE_ATOM, (int)$time); ?>" data-local-time="datetime" data-locale="<?php echo s2_htmlencode($trans('locale')); ?>"><?php echo $dateAndTime((int)$time); ?></time>
        <?php else: ?>
            <a class="comment-permalink" href="#<?php echo $i; ?>" aria-label="<?php echo $trans('Comment permalink', ['%number%' => $i]); ?>">
                <time datetime="<?php echo gmdate(DATE_ATOM, (int)$time); ?>" data-local-time="datetime" data-locale="<?php echo s2_htmlencode($trans('locale')); ?>"><?php echo $dateAndTime((int)$time); ?></time><span aria-hidden="true">, </span><span>№&nbsp;<?php echo $i; ?></span>
            </a>
        <?php endif; ?>
    </header>
    <div class="comment-body">
        <?php if ($show_addressee && $parent !== null && $parent['nick'] !== ''): ?>
            <a class="comment-addressee" href="#<?php echo $parent['i']; ?>"><?php echo s2_htmlencode($parent['nick']); ?>,</a>
        <?php endif; ?>
        <?php echo \S2\Cms\Helper\StringHelper::bbcodeToHtml(s2_htmlencode($text), $trans('Wrote')); ?>
    </div>
    <?php if (!$isPreview): ?>
        <div class="comment-actions">
            <a class="comment-reply" href="?<?php echo s2_htmlencode($replyQuery); ?>#add-comment"
               data-reply-comment="<?php echo $id; ?>"
               data-reply-number="<?php echo $i; ?>"
               data-reply-name="<?php echo $encodedNick; ?>"><?php echo $trans('Reply'); ?></a>
        </div>
    <?php endif; ?>
    <?php if ($moderationData !== null && !empty($moderationData['can_edit'])): ?>
        <form class="comment-edit-form" method="post" action="<?php echo s2_htmlencode((string)$moderationData['action_url']); ?>" hidden>
            <textarea name="text" rows="7" maxlength="65535"><?php echo s2_htmlencode($text); ?></textarea>
            <input type="hidden" name="moderation_action" value="edit">
            <input type="hidden" name="target_type" value="<?php echo s2_htmlencode((string)$moderationData['target']); ?>">
            <input type="hidden" name="comment_id" value="<?php echo $id; ?>">
            <input type="hidden" name="comment_anchor" value="<?php echo $i; ?>">
            <input type="hidden" name="moderation_token" value="<?php echo s2_htmlencode((string)$moderationData['token']); ?>">
            <input type="hidden" name="return_to" value="<?php echo s2_htmlencode((string)$moderationData['return_to']); ?>">
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
