<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $site_name */
/** @var string $tagline */
/** @var string $home_url */
/** @var bool $is_home */
/** @var array{action_url: string, token: string}|null $settings_inplace */
/** @var string|null $create_post_html */

$canEditHeader = isset($settings_inplace) && \is_array($settings_inplace);
$canCreatePost = \is_string($create_post_html ?? null) && $create_post_html !== '';
$titleTag      = $is_home ? 'h1' : 'div';
?>
<div
    class="site-header-shell<?php echo $canEditHeader ? ' is-manageable' : ''; ?>"
    data-site-header
    data-save-error="<?php echo register_htmlencode($trans('Site header editing failed')); ?>"
    data-title-label="<?php echo register_htmlencode($trans('Site title')); ?>"
    data-tagline-label="<?php echo register_htmlencode($trans('Site tagline')); ?>"
    data-tagline-placeholder="<?php echo register_htmlencode($trans('Site tagline placeholder')); ?>"
>
<?php if ($canCreatePost || $canEditHeader): ?>
    <nav class="site-header-tools" aria-label="<?php echo register_htmlencode($trans('Site tools')); ?>">
<?php if ($canCreatePost): ?>
        <button class="post-inplace-button post-create-start" type="button" title="<?php echo register_htmlencode($trans('Create new')); ?>" aria-label="<?php echo register_htmlencode($trans('Create new')); ?>" data-editor-shortcut="create">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="8.2"></circle>
                <path d="M12 8v8M8 12h8"></path>
            </svg>
        </button>
<?php endif; ?>
<?php if ($canEditHeader): ?>
        <button class="post-inplace-button site-header-edit-start" type="button" title="<?php echo register_htmlencode($trans('Edit site header')); ?>" aria-label="<?php echo register_htmlencode($trans('Edit site header')); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M5.2 18.8 6.4 14.4 16.6 4.2a2.1 2.1 0 0 1 3 3L9.4 17.4l-4.2 1.4Z"></path>
                <path d="m14.7 6.1 3.2 3.2M6.4 14.4l3 3"></path>
            </svg>
        </button>
        <button class="post-inplace-button site-header-edit-save" type="button" title="<?php echo register_htmlencode($trans('Save site header')); ?>" aria-label="<?php echo register_htmlencode($trans('Save site header')); ?>" data-editor-shortcut="save" hidden>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="m5 12.2 4.2 4.2L19 6.8"></path>
            </svg>
        </button>
        <button class="post-inplace-button site-header-edit-cancel" type="button" title="<?php echo register_htmlencode($trans('Cancel site header changes')); ?>" aria-label="<?php echo register_htmlencode($trans('Cancel site header changes')); ?>" data-editor-shortcut="cancel" hidden>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="m7 7 10 10M17 7 7 17"></path>
            </svg>
        </button>
<?php endif; ?>
    </nav>
<?php endif; ?>

    <div class="site-header-copy">
        <<?php echo $titleTag; ?> class="site-title">
<?php if ($is_home): ?>
            <span data-site-header-title><?php echo register_htmlencode($site_name); ?></span>
<?php else: ?>
            <a href="<?php echo register_htmlencode($home_url); ?>" data-site-header-link><span data-site-header-title><?php echo register_htmlencode($site_name); ?></span></a>
<?php endif; ?>
        </<?php echo $titleTag; ?>>
        <div class="site-tagline" data-site-header-tagline><?php echo nl2br(register_htmlencode($tagline)); ?></div>
    </div>

<?php if ($canEditHeader): ?>
    <form class="site-header-inplace-form" method="post" action="<?php echo register_htmlencode($settings_inplace['action_url']); ?>" hidden>
        <input type="hidden" name="title" value="<?php echo register_htmlencode($site_name); ?>">
        <textarea name="tagline" hidden><?php echo register_htmlencode($tagline); ?></textarea>
        <input type="hidden" name="inplace_token" value="<?php echo register_htmlencode($settings_inplace['token']); ?>">
    </form>
    <p class="site-header-inplace-error" role="alert" tabindex="-1" hidden></p>
    <p class="site-header-inplace-status" role="status" hidden></p>
<?php endif; ?>

<?php if ($canCreatePost): ?>
    <div class="post-create-slot" data-post-create-slot hidden>
        <template class="post-create-template"><?php echo $create_post_html; ?></template>
    </div>
<?php endif; ?>
</div>
