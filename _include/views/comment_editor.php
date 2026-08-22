<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var string $editorId */
/** @var string $editorValue */
/** @var int|null $editorRows */

$editorRows ??= 9;
$editorHtml = \S2\Cms\Comment\CommentHtml::editorHtml($editorValue, $trans('Wrote'));

?>
<div class="comment-editor" data-comment-editor>
    <div class="comment-editor-toolbar" role="toolbar" aria-label="<?php echo s2_htmlencode($trans('Comment editor toolbar')); ?>" hidden>
        <button type="button" data-comment-command="bold" title="<?php echo s2_htmlencode($trans('Bold')); ?>" aria-label="<?php echo s2_htmlencode($trans('Bold')); ?>"><strong>B</strong></button>
        <button type="button" data-comment-command="italic" title="<?php echo s2_htmlencode($trans('Italic')); ?>" aria-label="<?php echo s2_htmlencode($trans('Italic')); ?>"><em>I</em></button>
        <button type="button" data-comment-command="strikeThrough" title="<?php echo s2_htmlencode($trans('Strike')); ?>" aria-label="<?php echo s2_htmlencode($trans('Strike')); ?>"><s>S</s></button>
        <span class="comment-editor-toolbar-separator" aria-hidden="true"></span>
        <button type="button" data-comment-command="link" title="<?php echo s2_htmlencode($trans('Link')); ?>" aria-label="<?php echo s2_htmlencode($trans('Link')); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9.4 14.6 14.6 9.4M7.1 17H5.8a3.8 3.8 0 0 1 0-7.6h3.1M16.9 7h1.3a3.8 3.8 0 1 1 0 7.6h-3.1" /></svg>
        </button>
        <button type="button" data-comment-command="formatBlock" data-comment-command-value="blockquote" title="<?php echo s2_htmlencode($trans('Quote')); ?>" aria-label="<?php echo s2_htmlencode($trans('Quote')); ?>">“</button>
        <button type="button" data-comment-command="insertUnorderedList" title="<?php echo s2_htmlencode($trans('Bulleted list')); ?>" aria-label="<?php echo s2_htmlencode($trans('Bulleted list')); ?>">•≡</button>
        <button type="button" data-comment-command="insertOrderedList" title="<?php echo s2_htmlencode($trans('Numbered list')); ?>" aria-label="<?php echo s2_htmlencode($trans('Numbered list')); ?>">1≡</button>
        <span class="comment-editor-toolbar-separator" aria-hidden="true"></span>
        <button type="button" data-comment-command="removeFormat" title="<?php echo s2_htmlencode($trans('Clear formatting')); ?>" aria-label="<?php echo s2_htmlencode($trans('Clear formatting')); ?>">Tx</button>
    </div>
    <div class="comment-editor-link-panel" data-comment-link-panel hidden>
        <label>
            <span><?php echo $trans('Link address'); ?></span>
            <input type="url" inputmode="url" autocomplete="url" placeholder="https://" data-comment-link-input>
        </label>
        <div class="comment-editor-link-actions">
            <button type="button" data-comment-link-apply><?php echo $trans('Apply link'); ?></button>
            <button type="button" data-comment-link-remove><?php echo $trans('Remove link'); ?></button>
            <button type="button" data-comment-link-cancel><?php echo $trans('Cancel'); ?></button>
        </div>
    </div>
    <div
        class="comment-editor-surface"
        id="<?php echo s2_htmlencode($editorId); ?>-editor"
        role="textbox"
        aria-multiline="true"
        aria-labelledby="<?php echo s2_htmlencode($editorId); ?>-label"
        data-placeholder="<?php echo s2_htmlencode($trans('Comment editor placeholder')); ?>"
        contenteditable="true"
        spellcheck="true"
        hidden
    ></div>
    <textarea
        class="comment-editor-source"
        id="<?php echo s2_htmlencode($editorId); ?>"
        cols="50"
        rows="<?php echo $editorRows; ?>"
        name="text"
        maxlength="65535"
    ><?php echo s2_htmlencode($editorHtml); ?></textarea>
</div>
