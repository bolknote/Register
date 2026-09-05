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
/** @var array{action_url: string, token: string, revision: int, return_to: string, create: bool}|null $inplace */

$heading     = empty($title_link) ? 'h1' : 'h2';
$inplaceData = isset($inplace) && \is_array($inplace) ? $inplace : null;
$isCreating  = $inplaceData !== null && ($inplaceData['create'] ?? false) === true;
$postId      = (int)$id;
$editFormId  = 'post-inplace-edit-' . $postId;
$toolsMenuId = 'post-tools-menu-' . $postId;
$tagNames    = array_values(array_map(
    static fn(array $tag): string => (string)($tag['title'] ?? ''),
    \is_array($tags ?? null) ? $tags : [],
));
$analyticsSection = (string)($tagNames[0] ?? '');
?>
<article
    class="post-card<?php echo $inplaceData !== null ? ' is-manageable' : ''; ?><?php echo $isCreating ? ' is-creating' : ''; ?>"
    data-post-id="<?php echo $postId; ?>"
<?php if ($heading === 'h1' && !$isCreating): ?>
    data-analytics-content-type="post"
    data-analytics-content-id="<?php echo $postId; ?>"
    data-analytics-author="<?php echo register_htmlencode($author); ?>"
    data-analytics-section="<?php echo register_htmlencode($analyticsSection); ?>"
    data-analytics-published-at="<?php echo (int)$create_time; ?>"
<?php endif; ?>
<?php if ($isCreating): ?>
    data-post-creating
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
<div class="post-delete-confirmation" role="group" aria-label="<?php echo register_htmlencode(sprintf($trans('Delete warning'), $title)); ?>" hidden>
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
    <button class="post-inplace-button post-tools-menu-toggle" type="button" title="<?php echo register_htmlencode($trans('Post tools')); ?>" aria-label="<?php echo register_htmlencode($trans('Post tools')); ?>" aria-controls="<?php echo $toolsMenuId; ?>" aria-expanded="false">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="6" cy="12" r="1.5" />
            <circle cx="12" cy="12" r="1.5" />
            <circle cx="18" cy="12" r="1.5" />
        </svg>
    </button>
    <div class="post-tools-overflow" id="<?php echo $toolsMenuId; ?>">
    <button class="post-inplace-button post-edit-start" type="button" title="<?php echo $trans('Edit post inplace'); ?>" aria-label="<?php echo $trans('Edit post inplace'); ?>"<?php echo $isCreating ? ' hidden' : ''; ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M5.2 18.8 6.4 14.4 16.6 4.2a2.1 2.1 0 0 1 3 3L9.4 17.4l-4.2 1.4Z" />
            <path d="m14.7 6.1 3.2 3.2M6.4 14.4l3 3" />
        </svg>
        <span class="post-inplace-button-label"><?php echo $trans('Edit post inplace'); ?></span>
    </button>
    <button class="post-inplace-button post-delete-start" type="button" title="<?php echo $trans('Delete post inplace'); ?>" aria-label="<?php echo $trans('Delete post inplace'); ?>"<?php echo $isCreating ? ' hidden' : ''; ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M7.2 8.2 8 19.3h8l.8-11.1M5.2 6.2h13.6M9 6.2V4.5h6v1.7M10.2 10.3v6.4M13.8 10.3v6.4" />
        </svg>
        <span class="post-inplace-button-label"><?php echo $trans('Delete post inplace'); ?></span>
    </button>
    </div>
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
            : ' data-post-inplace-tags-values';
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
