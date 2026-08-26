<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var bool $can_create_post */
?>
<nav class="site-header-tools" aria-label="<?php echo register_htmlencode($trans('Site tools')); ?>">
<?php if ($can_create_post): ?>
    <button class="post-inplace-button post-create-start" type="button" title="<?php echo register_htmlencode($trans('Create new')); ?>" aria-label="<?php echo register_htmlencode($trans('Create new')); ?>" data-editor-shortcut="create">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="8.2"></circle>
            <path d="M12 8v8M8 12h8"></path>
        </svg>
    </button>
<?php endif; ?>
</nav>
