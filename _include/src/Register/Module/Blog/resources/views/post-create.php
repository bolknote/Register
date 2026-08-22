<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $post_html */
/** @var bool $always_visible */
?>
<div class="post-create-slot<?php echo $always_visible ? ' is-always-visible' : ''; ?>" data-post-create-slot>
    <button class="post-create-start" type="button" title="<?php echo s2_htmlencode($trans('Create new')); ?>" aria-label="<?php echo s2_htmlencode($trans('Create new')); ?>" data-editor-shortcut="create">
        <svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">
            <circle cx="16" cy="16" r="13"></circle>
            <path d="M16 10v12M10 16h12"></path>
        </svg>
    </button>
    <template class="post-create-template"><?php echo $post_html; ?></template>
</div>
