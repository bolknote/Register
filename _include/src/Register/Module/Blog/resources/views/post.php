<?php

declare(strict_types = 1);

use Register\Core\Http\TrustedScriptNonceInjector;
use Register\Content\ContentId;
use Register\Module\Blog\Model\DeferredViewCount;

/** @var callable $trans */
/** @var $author string */
/** @var $title string */
/** @var $title_link string */
/** @var $time string */
/** @var $create_time int */
/** @var $display_date string */
/** @var $text string */
/** @var $tags array */
/** @var $link string */
/** @var $commented bool */
/** @var $comment_num int */
/** @var $favorite bool */
/** @var string $favoritePostsUrl */
/** @var bool $showComments */
/** @var bool $enabledComments */
/** @var array{action_url: string, tag_suggestions_url: string, token: string, revision: int, return_to: string, ai_enabled: bool, ai_alt_enabled: bool, create: bool}|null $inplace */

$heading     = empty($title_link) ? 'h1' : 'h2';
$inplaceData = isset($inplace) && \is_array($inplace) ? $inplace : null;
$isCreating  = $inplaceData !== null && ($inplaceData['create'] ?? false) === true;
$postId      = (int)$id;
$editFormId  = 'post-inplace-edit-' . $postId;
$tagNames    = array_values(array_map(
    static fn(array $tag): string => (string)($tag['title'] ?? ''),
    \is_array($tags ?? null) ? $tags : [],
));
?>
<article
    class="post-card<?php echo $inplaceData !== null ? ' is-manageable' : ''; ?><?php echo $isCreating ? ' is-creating' : ''; ?>"
    data-post-id="<?php echo $postId; ?>"
<?php if ($isCreating): ?>
    data-post-creating
<?php endif; ?>
<?php if ($inplaceData !== null): ?>
    data-edit-error="<?php echo register_htmlencode($trans('Post editing failed')); ?>"
    data-apply-error="<?php echo register_htmlencode($trans('Post update apply failed')); ?>"
    data-invalid-content="<?php echo register_htmlencode($trans('Invalid post content')); ?>"
    data-deleted-message="<?php echo register_htmlencode($trans('Post deleted')); ?>"
    data-list-label="<?php echo register_htmlencode($trans('Post list')); ?>"
    data-title-label="<?php echo register_htmlencode($trans('Post title')); ?>"
    data-body-label="<?php echo register_htmlencode($trans('Post text')); ?>"
    data-tags-label="<?php echo register_htmlencode($trans('Post tags')); ?>"
    data-tag-suggestions-label="<?php echo register_htmlencode($trans('Post tag suggestions')); ?>"
    data-tag-suggestions-url="<?php echo register_htmlencode($inplaceData['tag_suggestions_url']); ?>"
    data-remove-tag-label="<?php echo register_htmlencode($trans('Remove post tag')); ?>"
    data-invalid-tags="<?php echo register_htmlencode($trans('Invalid post tags')); ?>"
    data-link-prompt="<?php echo register_htmlencode($trans('Link address')); ?>"
    data-media-optimizing="<?php echo register_htmlencode($trans('Post media optimizing')); ?>"
    data-media-uploading="<?php echo register_htmlencode($trans('Post media uploading')); ?>"
    data-media-upload-failed="<?php echo register_htmlencode($trans('Post media upload failed')); ?>"
    data-media-unsupported="<?php echo register_htmlencode($trans('Unsupported dropped media')); ?>"
    data-media-caption-placeholder="<?php echo register_htmlencode($trans('Add image caption')); ?>"
    data-ai-working="<?php echo register_htmlencode($trans('AI working')); ?>"
    data-ai-failed="<?php echo register_htmlencode($trans('AI request failed')); ?>"
    data-ai-unchanged="<?php echo register_htmlencode($trans('AI result unchanged')); ?>"
    data-ai-source-changed="<?php echo register_htmlencode($trans('AI source changed')); ?>"
    data-ai-applied="<?php echo register_htmlencode($trans('AI changes applied')); ?>"
    data-ai-proofread-clean="<?php echo register_htmlencode($trans('AI proofreading clean')); ?>"
    data-ai-alt-enabled="<?php echo $inplaceData['ai_alt_enabled'] ? '1' : '0'; ?>"
    data-ai-alt-working="<?php echo register_htmlencode($trans('AI image alt working')); ?>"
    data-ai-alt-applied="<?php echo register_htmlencode($trans('AI image alt applied')); ?>"
    data-ai-alt-failed="<?php echo register_htmlencode($trans('AI image alt failed')); ?>"
    data-invalid-link="<?php echo register_htmlencode($trans('Invalid link address')); ?>"
    data-discard-changes-warning="<?php echo register_htmlencode($trans('Discard post changes warning')); ?>"
    data-title-placeholder="<?php echo register_htmlencode($trans('New post title')); ?>"
    data-date-label="<?php echo register_htmlencode($trans('Post publication date')); ?>"
<?php endif; ?>
>
<?php if ($inplaceData !== null): ?>
<form
    id="<?php echo $editFormId; ?>"
    class="post-inplace-edit-form"
    method="post"
    action="<?php echo register_htmlencode($inplaceData['action_url']); ?>"
    hidden
>
    <input name="title" type="hidden" value="<?php echo register_htmlencode($title); ?>">
    <textarea name="body" hidden><?php echo register_htmlencode($text); ?></textarea>
    <input name="tags" type="hidden" value="<?php echo register_htmlencode(implode(', ', $tagNames)); ?>">
    <input name="published_at" type="hidden" value="<?php echo (int)$create_time; ?>">
    <input name="uploaded_media_ids" type="hidden" value="">
    <input type="hidden" name="inplace_action" value="<?php echo $isCreating ? 'create' : 'edit'; ?>">
    <input type="hidden" name="inplace_token" value="<?php echo register_htmlencode($inplaceData['token']); ?>">
    <input type="hidden" name="revision" value="<?php echo $inplaceData['revision']; ?>">
    <input type="hidden" name="return_to" value="<?php echo register_htmlencode($inplaceData['return_to']); ?>">
</form>
<p class="post-inplace-error post-inplace-edit-error" role="alert" tabindex="-1" hidden></p>
<div class="post-delete-confirmation" role="group" aria-label="<?php echo register_htmlencode(sprintf($trans('Delete warning'), $title)); ?>" data-warning-template="<?php echo register_htmlencode($trans('Delete warning')); ?>" hidden>
    <p><?php echo register_htmlencode(sprintf($trans('Delete warning'), $title)); ?></p>
    <form class="post-inplace-delete-form" method="post" action="<?php echo register_htmlencode($inplaceData['action_url']); ?>">
        <input type="hidden" name="inplace_action" value="delete">
        <input type="hidden" name="inplace_token" value="<?php echo register_htmlencode($inplaceData['token']); ?>">
        <input type="hidden" name="revision" value="<?php echo $inplaceData['revision']; ?>">
        <input type="hidden" name="return_to" value="<?php echo register_htmlencode($inplaceData['return_to']); ?>">
        <p class="post-inplace-error" role="alert" tabindex="-1" hidden></p>
        <div class="post-inplace-actions">
            <button class="post-delete-confirm" type="submit"><?php echo $trans('Confirm post deletion'); ?></button>
            <button class="post-delete-cancel" type="button"><?php echo $trans('Cancel post deletion'); ?></button>
        </div>
    </form>
</div>
<p class="post-inplace-status" role="status" hidden></p>
<template class="post-editor-context-menu-template">
    <div class="post-editor-context-menu" role="menu" aria-label="<?php echo register_htmlencode($trans('Editor context menu')); ?>" tabindex="-1">
        <header class="post-editor-context-header">
            <span class="post-editor-context-kicker"><?php echo $trans('Editor'); ?></span>
            <strong data-context-selection-only><?php echo $trans('Selected text'); ?></strong>
            <strong data-context-caret-only><?php echo $trans('Cursor position'); ?></strong>
            <strong data-context-image-only hidden><?php echo $trans('Image'); ?></strong>
        </header>

        <div class="post-editor-context-main">
<?php if ($inplaceData['ai_enabled']): ?>
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
                    <button type="button" data-context-action="paragraph" title="<?php echo register_htmlencode($trans('Paragraph')); ?>">¶</button>
                    <button type="button" data-context-action="h2" title="<?php echo register_htmlencode($trans('Header 2')); ?>">H2</button>
                    <button type="button" data-context-action="h3" title="<?php echo register_htmlencode($trans('Header 3')); ?>">H3</button>
                    <button type="button" data-context-action="h4" title="<?php echo register_htmlencode($trans('Header 4')); ?>">H4</button>
                    <button type="button" data-context-action="quote" title="<?php echo register_htmlencode($trans('Quote')); ?>">❝</button>
                    <button type="button" data-context-action="code" title="<?php echo register_htmlencode($trans('CODE')); ?>">&lt;/&gt;</button>
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
<?php if ($inplaceData['ai_alt_enabled']): ?>
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
        <section class="post-media-conflict-dialog post-discard-changes-dialog" role="dialog" aria-modal="true" aria-labelledby="post-discard-changes-title-<?php echo $postId; ?>" tabindex="-1">
            <header>
                <span><?php echo $trans('Editor'); ?></span>
                <h2 id="post-discard-changes-title-<?php echo $postId; ?>"><?php echo $trans('Discard unsaved post changes'); ?></h2>
                <p><?php echo $trans('Discard post changes warning'); ?></p>
            </header>
            <div class="post-media-conflict-actions post-discard-changes-actions">
                <button type="button" class="is-danger" data-discard-changes-action="discard"><?php echo $trans('Discard post changes'); ?></button>
                <button type="button" class="is-primary" data-discard-changes-action="continue"><?php echo $trans('Continue post editing'); ?></button>
            </div>
        </section>
    </div>
</template>
<?php endif; ?>
<div class="post author"><?php if (!empty($author)) echo register_htmlencode($author); ?></div>
<<?php echo $heading; ?> class="post head">
<?php if (!empty($title_link)) {?>
	<a href="<?php echo register_htmlencode($title_link); ?>"><span class="post-title-text"<?php echo $inplaceData !== null ? ' data-post-inplace-title' : ''; ?>><?php echo register_htmlencode($title); ?></span></a>
<?php } else {?>
	<span class="post-title-text"<?php echo $inplaceData !== null ? ' data-post-inplace-title' : ''; ?>><?php echo register_htmlencode($title); ?></span>
<?php } ?>
<?php if (!empty($favorite) && $favorite !== 2) {?>
    <a href="<?php echo $favoritePostsUrl; ?>" class="favorite-star" title="<?php echo $trans('Favorite posts'); ?>">★</a>
<?php } elseif (!empty($favorite)) {?>
    <span class="favorite-star" title="<?php echo $trans('Favorite posts'); ?>">★</span>
<?php } ?>
</<?php echo $heading; ?>>
<div class="post time">
    <time datetime="<?php echo gmdate(DATE_ATOM, (int)$create_time); ?>"<?php if (trim($display_date ?? '') === ''): ?> data-local-time="datetime" data-locale="<?php echo register_htmlencode($trans('locale')); ?>"<?php endif; ?>><?php echo register_htmlencode($time); ?></time>
<?php if ($inplaceData !== null): ?>
    <button class="post-inplace-date-button" type="button" title="<?php echo register_htmlencode($trans('Post publication date')); ?>" aria-label="<?php echo register_htmlencode($trans('Post publication date')); ?>" hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <rect x="4" y="5.5" width="16" height="14" rx="2"></rect>
            <path d="M8 3.5v4M16 3.5v4M4 9.5h16"></path>
        </svg>
    </button>
    <input class="post-inplace-datetime" type="datetime-local" step="1" tabindex="-1" aria-label="<?php echo register_htmlencode($trans('Post publication date')); ?>" hidden>
<?php endif; ?>
</div>
<?php if ($inplaceData !== null): ?>
<nav class="post-inplace-tools" aria-label="<?php echo $trans('Post tools'); ?>">
    <button class="post-inplace-button post-edit-start" type="button" title="<?php echo $trans('Edit post inplace'); ?>" aria-label="<?php echo $trans('Edit post inplace'); ?>"<?php echo $isCreating ? ' hidden' : ''; ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M5.2 18.8 6.4 14.4 16.6 4.2a2.1 2.1 0 0 1 3 3L9.4 17.4l-4.2 1.4Z" />
            <path d="m14.7 6.1 3.2 3.2M6.4 14.4l3 3" />
        </svg>
    </button>
    <button class="post-inplace-button post-delete-start" type="button" title="<?php echo $trans('Delete post inplace'); ?>" aria-label="<?php echo $trans('Delete post inplace'); ?>"<?php echo $isCreating ? ' hidden' : ''; ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M7.2 8.2 8 19.3h8l.8-11.1M5.2 6.2h13.6M9 6.2V4.5h6v1.7M10.2 10.3v6.4M13.8 10.3v6.4" />
        </svg>
    </button>
    <button class="post-inplace-button post-edit-save" type="button" title="<?php echo $trans('Save post changes'); ?>" aria-label="<?php echo $trans('Save post changes'); ?>" data-editor-shortcut="save" hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m5 12.2 4.2 4.2L19 6.8" />
        </svg>
    </button>
    <button class="post-inplace-button post-edit-cancel" type="button" title="<?php echo $trans('Cancel post changes'); ?>" aria-label="<?php echo $trans('Cancel post changes'); ?>" data-editor-shortcut="cancel" hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m7 7 10 10M17 7 7 17" />
        </svg>
    </button>
</nav>
<?php endif; ?>
<?php
	echo '<div class="post body" data-post-inplace-body>'
        . TrustedScriptNonceInjector::markTrustedHtml($text)
        . '</div>';
	if (!empty($see_also))
		include __DIR__ . '/see_also.php';
?>
<div class="post foot">
<!-- register_reactions:post:<?php echo (int)$id; ?> -->
<?php
	$footer = [];

    if ($postId > 0) {
        $footer['views'] = DeferredViewCount::placeholder(ContentId::post($postId));
    } else {
        $viewCount = (int)($view_count ?? 0);
        $viewLabel = $trans('N Views', ['%count%' => $viewCount, '{{ count }}' => $viewCount]);
        $encodedViewLabel = register_htmlencode($viewLabel);
        $footer['views'] = '<span class="post-foot-views" aria-label="' . $encodedViewLabel
            . '" title="' . $encodedViewLabel . '"><span class="post-foot-views-count" aria-hidden="true">'
            . $viewCount . '</span></span>';
    }

	if ($commented && $showComments) {
        if ($comment_num) {
            $commentLabel = $trans('N Comments', ['%count%' => $comment_num, '{{ count }}' => $comment_num]);
            $footer['comments'] = '<span class="post-foot-comments"><a href="' . $link . '#comments-title" data-comment-count="' . $comment_num . '" aria-label="' . register_htmlencode($commentLabel) . '">' . $commentLabel . '</a></span>';
        } else {
            $commentLabel = $enabledComments ? $trans('Post comment') : '';
            $footer['comments'] = '<span class="post-foot-comments"><a href="' . $link . '#add-comment" data-comment-count="0"' . ($commentLabel !== '' ? ' aria-label="' . register_htmlencode($commentLabel) . '"' : '') . '>' . $commentLabel . '</a></span>';
        }
    }

    if ($tagNames !== [] || $inplaceData !== null) {
        $tagLinks = [];
        foreach (\is_array($tags ?? null) ? $tags : [] as $tag) {
            $tagLinks[] = '<a class="post-tag-link" href="' . register_htmlencode((string)($tag['link'] ?? '')) . '">' . register_htmlencode((string)($tag['title'] ?? '')) . '</a>';
        }

        $emptyClass = $tagNames === [] ? ' is-empty' : '';
        $editAttributes = $inplaceData === null
            ? ''
            : ' data-post-inplace-tags-values data-placeholder="' . register_htmlencode($trans('Post tags placeholder')) . '"';
        $tagLabel = register_htmlencode($trans('Tags'));
        $visibleEditorLabel = $inplaceData === null
            ? ''
            : '<span class="post-foot-tags-label">' . $tagLabel . ':</span> ';
        $footer['tags'] = '<span class="post-foot-tags post-tag-list' . $emptyClass . '" aria-label="' . $tagLabel . '">'
            . $visibleEditorLabel . '<span class="post-tag-values"' . $editAttributes . '>'
            . implode('', $tagLinks) . '</span></span>';
    }

    echo $footer['comments'] ?? '';
    echo '<div class="post-foot-meta">'
        . ($footer['tags'] ?? '')
        . $footer['views']
        . '</div>';
?>
</div>
</article>
