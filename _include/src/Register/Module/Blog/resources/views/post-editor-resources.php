<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var array{tag_suggestions_url: string, ai_enabled: bool, ai_alt_enabled: bool} $editor_config */

$config = [
    'editError' => $trans('Post editing failed'),
    'applyError' => $trans('Post update apply failed'),
    'invalidContent' => $trans('Invalid post content'),
    'deletedMessage' => $trans('Post deleted'),
    'listLabel' => $trans('Post list'),
    'titleLabel' => $trans('Post title'),
    'bodyLabel' => $trans('Post text'),
    'tagsLabel' => $trans('Post tags'),
    'tagSuggestionsLabel' => $trans('Post tag suggestions'),
    'removeTagLabel' => $trans('Remove post tag'),
    'invalidTags' => $trans('Invalid post tags'),
    'mediaQueued' => $trans('Post media queued'),
    'mediaOptimizing' => $trans('Post media optimizing'),
    'mediaUploading' => $trans('Post media uploading'),
    'mediaUploadFailed' => $trans('Post media upload failed'),
    'mediaUnsupported' => $trans('Unsupported dropped media'),
    'mediaCaptionPlaceholder' => $trans('Add image caption'),
    'aiWorking' => $trans('AI working'),
    'aiFailed' => $trans('AI request failed'),
    'aiUnchanged' => $trans('AI result unchanged'),
    'aiSourceChanged' => $trans('AI source changed'),
    'aiApplied' => $trans('AI changes applied'),
    'aiProofreadClean' => $trans('AI proofreading clean'),
    'aiAltWorking' => $trans('AI image alt working'),
    'aiAltApplied' => $trans('AI image alt applied'),
    'aiAltFailed' => $trans('AI image alt failed'),
    'invalidLink' => $trans('Invalid link address'),
    'discardChangesWarning' => $trans('Discard post changes warning'),
    'titlePlaceholder' => $trans('New post title'),
    'tagsPlaceholder' => $trans('Post tags placeholder'),
    'deleteWarning' => $trans('Delete warning'),
    'tagSuggestionsUrl' => $editor_config['tag_suggestions_url'],
    'aiAltEnabled' => $editor_config['ai_alt_enabled'],
];
?>
<div id="post-editor-resources" hidden data-config="<?php echo register_htmlencode(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); ?>">
<template class="post-editor-context-menu-template">
    <div class="post-editor-context-menu" role="menu" aria-label="<?php echo register_htmlencode($trans('Editor context menu')); ?>" tabindex="-1">
        <header class="post-editor-context-header">
            <span class="post-editor-context-kicker"><?php echo $trans('Editor'); ?></span>
            <strong data-context-selection-only><?php echo $trans('Selected text'); ?></strong>
            <strong data-context-caret-only><?php echo $trans('Cursor position'); ?></strong>
            <strong data-context-image-only hidden><?php echo $trans('Image'); ?></strong>
        </header>

        <div class="post-editor-context-main">
<?php if ($editor_config['ai_enabled']): ?>
            <section class="post-editor-context-section" data-context-selection-only>
                <h3><span class="post-editor-ai-mark" aria-hidden="true">✦</span> <?php echo $trans('AI for selection'); ?></h3>
                <button type="button" role="menuitem" data-context-ai-action="proofread">
                    <span><?php echo $trans('Proofread'); ?></span>
                </button>
                <button type="button" role="menuitem" data-context-ai-action="improve">
                    <span><?php echo $trans('Improve text'); ?></span>
                </button>
                <button type="button" role="menuitem" data-context-ai-action="shorten">
                    <span><?php echo $trans('Shorten text'); ?></span>
                </button>
            </section>

            <section class="post-editor-context-section" data-context-caret-only>
                <h3><span class="post-editor-ai-mark" aria-hidden="true">✦</span> <?php echo $trans('AI for whole text'); ?></h3>
                <button type="button" role="menuitem" data-context-ai-action="proofread">
                    <span><?php echo $trans('Proofread'); ?></span>
                </button>
                <button type="button" role="menuitem" data-context-ai-action="improve">
                    <span><?php echo $trans('Improve text'); ?></span>
                </button>
                <button type="button" role="menuitem" data-context-ai-action="shorten">
                    <span><?php echo $trans('Shorten text'); ?></span>
                </button>
                <button type="button" role="menuitem" data-context-ai-action="title">
                    <span><?php echo $trans('Suggest title'); ?></span>
                </button>
                <button type="button" role="menuitem" data-context-ai-action="tags">
                    <span><?php echo $trans('Suggest tags'); ?></span>
                </button>
            </section>
<?php endif; ?>

            <section class="post-editor-context-section">
                <h3><?php echo $trans('Inline formatting'); ?></h3>
                <div class="post-editor-format-row" role="group" aria-label="<?php echo register_htmlencode($trans('Inline formatting')); ?>">
                    <button type="button" data-context-action="bold" title="<?php echo register_htmlencode($trans('Bold')); ?>" aria-label="<?php echo register_htmlencode($trans('Bold')); ?>"><strong>B</strong></button>
                    <button type="button" data-context-action="italic" title="<?php echo register_htmlencode($trans('Italic')); ?>" aria-label="<?php echo register_htmlencode($trans('Italic')); ?>"><em>I</em></button>
                    <button type="button" data-context-action="strike" title="<?php echo register_htmlencode($trans('Strike')); ?>" aria-label="<?php echo register_htmlencode($trans('Strike')); ?>"><s>S</s></button>
                    <button type="button" data-context-action="inline-code" data-context-selection-only title="<?php echo register_htmlencode($trans('Inline code')); ?>" aria-label="<?php echo register_htmlencode($trans('Inline code')); ?>"><span class="post-editor-inline-code-glyph" aria-hidden="true">tt</span></button>
                    <button type="button" data-context-action="open-link" title="<?php echo register_htmlencode($trans('Link')); ?>" aria-label="<?php echo register_htmlencode($trans('Link')); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 14.5 14.5 9.5M7.4 16.6l-1.2 1.2a3.1 3.1 0 0 1-4.4-4.4l3.4-3.4a3.1 3.1 0 0 1 4.4 0M16.6 7.4l1.2-1.2a3.1 3.1 0 0 1 4.4 4.4L18.8 14a3.1 3.1 0 0 1-4.4 0" /></svg>
                    </button>
                    <button type="button" data-context-action="clear-format" title="<?php echo register_htmlencode($trans('Clear formatting')); ?>" aria-label="<?php echo register_htmlencode($trans('Clear formatting')); ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 19 14-14M9 5h10M14 5 7.8 19M5 19h6" /></svg>
                    </button>
                </div>
            </section>

            <section class="post-editor-context-section">
                <h3><?php echo $trans('Block style'); ?></h3>
                <div class="post-editor-block-row" role="group" aria-label="<?php echo register_htmlencode($trans('Block style')); ?>">
                    <button type="button" data-context-action="paragraph" title="<?php echo register_htmlencode($trans('Paragraph')); ?>" aria-label="<?php echo register_htmlencode($trans('Paragraph')); ?>">¶</button>
                    <button type="button" data-context-action="h2" title="<?php echo register_htmlencode($trans('Header 2')); ?>" aria-label="<?php echo register_htmlencode($trans('Header 2')); ?>">H2</button>
                    <button type="button" data-context-action="h3" title="<?php echo register_htmlencode($trans('Header 3')); ?>" aria-label="<?php echo register_htmlencode($trans('Header 3')); ?>">H3</button>
                    <button type="button" data-context-action="h4" title="<?php echo register_htmlencode($trans('Header 4')); ?>" aria-label="<?php echo register_htmlencode($trans('Header 4')); ?>">H4</button>
                    <button type="button" data-context-action="quote" title="<?php echo register_htmlencode($trans('Quote')); ?>" aria-label="<?php echo register_htmlencode($trans('Quote')); ?>">❝</button>
                    <button type="button" data-context-action="code" title="<?php echo register_htmlencode($trans('CODE')); ?>" aria-label="<?php echo register_htmlencode($trans('CODE')); ?>">&lt;/&gt;</button>
                    <button type="button" data-context-action="unordered-list" title="<?php echo register_htmlencode($trans('UL')); ?>">•≡</button>
                    <button type="button" data-context-action="ordered-list" title="<?php echo register_htmlencode($trans('OL')); ?>">1≡</button>
                </div>
            </section>

            <section class="post-editor-context-section" data-context-caret-only>
                <h3><?php echo $trans('Insert entity'); ?></h3>
                <div class="post-editor-entity-grid">
                    <button type="button" role="menuitem" data-context-action="media">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="13" height="12" rx="2" /><path d="m5.5 13 3-3 4.8 4.8M12.5 7.5h.01M19 8v9.5a2.5 2.5 0 1 1-2-2.45V10l4-1" /></svg>
                        <span><?php echo $trans('Media'); ?></span>
                    </button>
                    <button type="button" role="menuitem" data-context-action="open-link">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 14.5 14.5 9.5M7.4 16.6l-1.2 1.2a3.1 3.1 0 0 1-4.4-4.4l3.4-3.4a3.1 3.1 0 0 1 4.4 0M16.6 7.4l1.2-1.2a3.1 3.1 0 0 1 4.4 4.4L18.8 14a3.1 3.1 0 0 1-4.4 0" /></svg>
                        <span><?php echo $trans('Link'); ?></span>
                    </button>
                    <button type="button" role="menuitem" data-context-action="divider">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h16" /></svg>
                        <span><?php echo $trans('Divider'); ?></span>
                    </button>
                </div>
            </section>

            <section class="post-editor-context-section post-editor-context-utility">
                <button type="button" role="menuitem" data-context-action="undo"><span><?php echo $trans('Undo'); ?></span><kbd>⌘Z</kbd></button>
                <button type="button" role="menuitem" data-context-action="redo"><span><?php echo $trans('Redo'); ?></span><kbd>⇧⌘Z</kbd></button>
                <button type="button" role="menuitem" data-context-action="copy" data-context-selection-only><span><?php echo $trans('Copy'); ?></span><kbd>⌘C</kbd></button>
                <button type="button" role="menuitem" data-context-action="cut" data-context-selection-only><span><?php echo $trans('Cut'); ?></span><kbd>⌘X</kbd></button>
                <button type="button" role="menuitem" data-context-action="select-all" data-context-caret-only><span><?php echo $trans('Select all'); ?></span><kbd>⌘A</kbd></button>
            </section>
        </div>

        <div class="post-editor-link-panel" hidden>
            <button class="post-editor-link-back" type="button" data-context-action="link-back">← <?php echo $trans('Back'); ?></button>
            <label>
                <span><?php echo $trans('Link address'); ?></span>
                <input type="text" inputmode="url" autocomplete="off" data-context-link-input placeholder="https://">
            </label>
            <p class="post-editor-link-error" role="alert" hidden></p>
            <div class="post-editor-link-actions">
                <button type="button" data-context-action="remove-link"><?php echo $trans('Remove link'); ?></button>
                <button type="button" class="post-editor-link-apply" data-context-action="apply-link"><span><?php echo $trans('Apply'); ?></span><kbd>↵</kbd></button>
            </div>
        </div>

        <div class="post-editor-image-panel" hidden>
            <div class="post-editor-image-tools" role="group" aria-label="<?php echo register_htmlencode($trans('Image')); ?>">
                <button type="button" data-context-action="open-link" title="<?php echo register_htmlencode($trans('Link')); ?>" aria-label="<?php echo register_htmlencode($trans('Link')); ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 14.5 14.5 9.5M7.4 16.6l-1.2 1.2a3.1 3.1 0 0 1-4.4-4.4l3.4-3.4a3.1 3.1 0 0 1 4.4 0M16.6 7.4l1.2-1.2a3.1 3.1 0 0 1 4.4 4.4L18.8 14a3.1 3.1 0 0 1-4.4 0" /></svg>
                    <span class="visually-hidden"><?php echo $trans('Link'); ?></span>
                </button>
                <button type="button" data-context-action="edit-image-caption" data-caption-placeholder="<?php echo register_htmlencode($trans('Type image caption')); ?>" title="<?php echo register_htmlencode($trans('Image caption')); ?>" aria-label="<?php echo register_htmlencode($trans('Image caption')); ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M7 15h10M7 11h7" /></svg>
                    <span class="visually-hidden"><?php echo $trans('Image caption'); ?></span>
                </button>
<?php if ($editor_config['ai_alt_enabled']): ?>
                <button type="button" data-context-action="generate-image-alt" title="<?php echo register_htmlencode($trans('Generate image alt with AI')); ?>" aria-label="<?php echo register_htmlencode($trans('Generate image alt with AI')); ?>">
                    <span aria-hidden="true">✦</span>
                    <span class="visually-hidden"><?php echo $trans('Generate image alt with AI'); ?></span>
                </button>
<?php endif; ?>
            </div>
            <label class="post-editor-image-alt">
                <span><?php echo $trans('Alternative text'); ?></span>
                <input type="text" autocomplete="off" data-context-image-alt-input>
            </label>
        </div>
    </div>
</template>
<template class="post-image-caption-toolbar-template">
    <div class="post-media-caption-toolbar" role="toolbar" aria-label="<?php echo register_htmlencode($trans('Caption tools')); ?>" contenteditable="false">
        <div class="post-media-caption-toolbar-actions">
            <button type="button" data-caption-action="commit" title="<?php echo register_htmlencode($trans('Finish caption')); ?>" aria-label="<?php echo register_htmlencode($trans('Finish caption')); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6" /></svg>
            </button>
            <button type="button" data-caption-action="cancel" title="<?php echo register_htmlencode($trans('Cancel')); ?> — Esc" aria-label="<?php echo register_htmlencode($trans('Cancel')); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
            </button>
        </div>
        <div class="post-media-caption-fonts" role="group" aria-label="<?php echo register_htmlencode($trans('Caption fonts')); ?>">
            <button type="button" class="is-font-sans" data-caption-font="sans" title="<?php echo register_htmlencode($trans('Sans-serif')); ?>" aria-label="<?php echo register_htmlencode($trans('Sans-serif')); ?>">Aa</button>
            <button type="button" class="is-font-serif" data-caption-font="serif" title="<?php echo register_htmlencode($trans('Serif')); ?>" aria-label="<?php echo register_htmlencode($trans('Serif')); ?>">Aa</button>
            <button type="button" class="is-font-mono" data-caption-font="mono" title="<?php echo register_htmlencode($trans('Monospace')); ?>" aria-label="<?php echo register_htmlencode($trans('Monospace')); ?>">Aa</button>
            <button type="button" class="is-font-display" data-caption-font="display" title="<?php echo register_htmlencode($trans('Display font')); ?>" aria-label="<?php echo register_htmlencode($trans('Display font')); ?>">Aa</button>
        </div>
        <div class="post-media-caption-backgrounds" role="group" aria-label="<?php echo register_htmlencode($trans('Caption backgrounds')); ?>">
            <button type="button" data-caption-background="none" title="<?php echo register_htmlencode($trans('No background')); ?>" aria-label="<?php echo register_htmlencode($trans('No background')); ?>"><span class="is-none"></span></button>
            <button type="button" data-caption-background="dark" title="<?php echo register_htmlencode($trans('Dark background')); ?>" aria-label="<?php echo register_htmlencode($trans('Dark background')); ?>"><span class="is-dark"></span></button>
            <button type="button" data-caption-background="light" title="<?php echo register_htmlencode($trans('Light background')); ?>" aria-label="<?php echo register_htmlencode($trans('Light background')); ?>"><span class="is-light"></span></button>
            <button type="button" data-caption-background="accent" title="<?php echo register_htmlencode($trans('Accent background')); ?>" aria-label="<?php echo register_htmlencode($trans('Accent background')); ?>"><span class="is-accent"></span></button>
        </div>
    </div>
</template>
<template class="post-discard-changes-template">
    <div class="post-media-conflict-backdrop post-discard-changes-backdrop">
        <section class="post-media-conflict-dialog post-discard-changes-dialog" role="dialog" aria-modal="true" aria-label="<?php echo register_htmlencode($trans('Discard unsaved post changes')); ?>" tabindex="-1">
            <header>
                <span><?php echo $trans('Editor'); ?></span>
                <h2><?php echo $trans('Discard unsaved post changes'); ?></h2>
                <p><?php echo $trans('Discard post changes warning'); ?></p>
            </header>
            <div class="post-media-conflict-actions post-discard-changes-actions">
                <button type="button" class="is-danger" data-discard-changes-action="discard"><?php echo $trans('Discard post changes'); ?></button>
                <button type="button" class="is-primary" data-discard-changes-action="continue"><?php echo $trans('Continue post editing'); ?></button>
            </div>
        </section>
    </div>
</template>
</div>
