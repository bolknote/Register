<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var bool $can_create_post */
/** @var int $pending_comments_num */
/** @var string $comments_url */
/** @var string|null $live_region */

$commentsLabel = $pending_comments_num > 0
    ? $trans('N pending comments', [
        '{{ count }}' => $pending_comments_num,
        '%count%'     => $pending_comments_num,
    ])
    : '';
?>
<nav
    class="site-header-tools"
    aria-label="<?php echo register_htmlencode($trans('Site tools')); ?>"
<?php if ($live_region !== null): ?>
    data-live-region="<?php echo register_htmlencode($live_region); ?>"
<?php endif; ?>
>
<?php if ($can_create_post): ?>
    <button class="post-inplace-button post-create-start" type="button" title="<?php echo register_htmlencode($trans('Create new')); ?>" aria-label="<?php echo register_htmlencode($trans('Create new')); ?>" data-editor-shortcut="create">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="8.2"></circle>
            <path d="M12 8v8M8 12h8"></path>
        </svg>
    </button>
<?php endif; ?>
<?php if ($pending_comments_num > 0): ?>
    <a
        class="post-inplace-button site-header-new-comments"
        href="<?php echo register_htmlencode($comments_url); ?>"
        title="<?php echo register_htmlencode($commentsLabel); ?>"
        aria-label="<?php echo register_htmlencode($commentsLabel); ?>"
        data-pending-comments-count="<?php echo $pending_comments_num; ?>"
        data-register-native-navigation
    >
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M20.5 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h9.5a4 4 0 0 1 4 4Z"></path>
        </svg>
        <span class="site-header-comment-count" aria-hidden="true"><?php echo $pending_comments_num; ?></span>
    </a>
<?php endif; ?>
</nav>
