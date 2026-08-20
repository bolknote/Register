<?php

declare(strict_types = 1);

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
/** @var array{action_url: string, admin_edit_url: string, token: string, revision: int, return_to: string}|null $inplace */

$heading     = empty($title_link) ? 'h1' : 'h2';
$inplaceData = isset($inplace) && \is_array($inplace) ? $inplace : null;
$postId      = (int)$id;
$tagNames    = array_values(array_map(
    static fn(array $tag): string => (string)($tag['title'] ?? ''),
    \is_array($tags ?? null) ? $tags : [],
));
?>
<article
    class="post-card<?php echo $inplaceData !== null ? ' is-manageable' : ''; ?>"
    data-post-id="<?php echo $postId; ?>"
<?php if ($inplaceData !== null): ?>
    data-edit-error="<?php echo s2_htmlencode($trans('Post editing failed')); ?>"
    data-apply-error="<?php echo s2_htmlencode($trans('Post update apply failed')); ?>"
    data-invalid-content="<?php echo s2_htmlencode($trans('Invalid post content')); ?>"
    data-deleted-message="<?php echo s2_htmlencode($trans('Post deleted')); ?>"
    data-list-label="<?php echo s2_htmlencode($trans('Post list')); ?>"
    data-title-label="<?php echo s2_htmlencode($trans('Post title')); ?>"
    data-body-label="<?php echo s2_htmlencode($trans('Post text')); ?>"
    data-tags-label="<?php echo s2_htmlencode($trans('Post tags')); ?>"
    data-remove-tag-label="<?php echo s2_htmlencode($trans('Remove post tag')); ?>"
    data-invalid-tags="<?php echo s2_htmlencode($trans('Invalid post tags')); ?>"
    data-link-prompt="<?php echo s2_htmlencode($trans('Link address')); ?>"
    data-media-uploading="<?php echo s2_htmlencode($trans('Post media uploading')); ?>"
    data-media-upload-failed="<?php echo s2_htmlencode($trans('Post media upload failed')); ?>"
    data-media-unsupported="<?php echo s2_htmlencode($trans('Unsupported dropped media')); ?>"
<?php endif; ?>
>
<?php if ($inplaceData !== null): ?>
<form
    class="post-inplace-edit-form"
    method="post"
    action="<?php echo s2_htmlencode($inplaceData['action_url']); ?>"
    hidden
>
    <input name="title" type="hidden" value="<?php echo s2_htmlencode($title); ?>">
    <textarea name="body" hidden><?php echo s2_htmlencode($text); ?></textarea>
    <input name="tags" type="hidden" value="<?php echo s2_htmlencode(implode(', ', $tagNames)); ?>">
    <input type="hidden" name="inplace_action" value="edit">
    <input type="hidden" name="inplace_token" value="<?php echo s2_htmlencode($inplaceData['token']); ?>">
    <input type="hidden" name="revision" value="<?php echo $inplaceData['revision']; ?>">
    <input type="hidden" name="return_to" value="<?php echo s2_htmlencode($inplaceData['return_to']); ?>">
</form>
<p class="post-inplace-error post-inplace-edit-error" role="alert" tabindex="-1" hidden></p>
<div class="post-delete-confirmation" role="group" aria-label="<?php echo s2_htmlencode(sprintf($trans('Delete warning'), $title)); ?>" data-warning-template="<?php echo s2_htmlencode($trans('Delete warning')); ?>" hidden>
    <p><?php echo s2_htmlencode(sprintf($trans('Delete warning'), $title)); ?></p>
    <form class="post-inplace-delete-form" method="post" action="<?php echo s2_htmlencode($inplaceData['action_url']); ?>">
        <input type="hidden" name="inplace_action" value="delete">
        <input type="hidden" name="inplace_token" value="<?php echo s2_htmlencode($inplaceData['token']); ?>">
        <input type="hidden" name="revision" value="<?php echo $inplaceData['revision']; ?>">
        <input type="hidden" name="return_to" value="<?php echo s2_htmlencode($inplaceData['return_to']); ?>">
        <p class="post-inplace-error" role="alert" tabindex="-1" hidden></p>
        <div class="post-inplace-actions">
            <button class="post-delete-confirm" type="submit"><?php echo $trans('Confirm post deletion'); ?></button>
            <button class="post-delete-cancel" type="button"><?php echo $trans('Cancel post deletion'); ?></button>
        </div>
    </form>
</div>
<p class="post-inplace-status" role="status" hidden></p>
<?php endif; ?>
<div class="post author"><?php if (!empty($author)) echo s2_htmlencode($author); ?></div>
<<?php echo $heading; ?> class="post head">
<?php if (!empty($title_link)) {?>
	<a href="<?php echo s2_htmlencode($title_link); ?>"><span class="post-title-text"<?php echo $inplaceData !== null ? ' data-post-inplace-title' : ''; ?>><?php echo s2_htmlencode($title); ?></span></a>
<?php } else {?>
	<span class="post-title-text"<?php echo $inplaceData !== null ? ' data-post-inplace-title' : ''; ?>><?php echo s2_htmlencode($title); ?></span>
<?php } ?>
<?php if (!empty($favorite) && $favorite !== 2) {?>
    <a href="<?php echo $favoritePostsUrl; ?>" class="favorite-star" title="<?php echo $trans('Favorite posts'); ?>">★</a>
<?php } elseif (!empty($favorite)) {?>
    <span class="favorite-star" title="<?php echo $trans('Favorite posts'); ?>">★</span>
<?php } ?>
</<?php echo $heading; ?>>
<div class="post time"><time datetime="<?php echo gmdate(DATE_ATOM, (int)$create_time); ?>"<?php if (trim($display_date ?? '') === ''): ?> data-local-time="datetime" data-locale="<?php echo s2_htmlencode($trans('locale')); ?>"<?php endif; ?>><?php echo s2_htmlencode($time); ?></time></div>
<?php if ($inplaceData !== null): ?>
<nav class="post-inplace-tools" aria-label="<?php echo $trans('Post tools'); ?>">
    <a class="post-inplace-button post-edit-start" href="<?php echo s2_htmlencode($inplaceData['admin_edit_url']); ?>" title="<?php echo $trans('Edit post inplace'); ?>" aria-label="<?php echo $trans('Edit post inplace'); ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M5.2 18.8 6.4 14.4 16.6 4.2a2.1 2.1 0 0 1 3 3L9.4 17.4l-4.2 1.4Z" />
            <path d="m14.7 6.1 3.2 3.2M6.4 14.4l3 3" />
        </svg>
    </a>
    <button class="post-inplace-button post-delete-start" type="button" title="<?php echo $trans('Delete post inplace'); ?>" aria-label="<?php echo $trans('Delete post inplace'); ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M7.2 8.2 8 19.3h8l.8-11.1M5.2 6.2h13.6M9 6.2V4.5h6v1.7M10.2 10.3v6.4M13.8 10.3v6.4" />
        </svg>
    </button>
    <button class="post-inplace-button post-edit-save" type="button" title="<?php echo $trans('Save post changes'); ?>" aria-label="<?php echo $trans('Save post changes'); ?>" hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m5 12.2 4.2 4.2L19 6.8" />
        </svg>
    </button>
    <button class="post-inplace-button post-edit-cancel" type="button" title="<?php echo $trans('Cancel post changes'); ?>" aria-label="<?php echo $trans('Cancel post changes'); ?>" hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m7 7 10 10M17 7 7 17" />
        </svg>
    </button>
</nav>
<?php endif; ?>
<?php
	echo '<div class="post body" data-post-inplace-body>' . $text . '</div>';
	if (!empty($see_also))
		include __DIR__ . '/see_also.php';
?>
<div class="post foot">
<!-- register_reactions:post:<?php echo (int)$id; ?> -->
<?php
	$footer = [];

	if ($commented && $showComments) {
        if ($comment_num) {
            $commentLabel = $trans('N Comments', ['%count%' => $comment_num, '{{ count }}' => $comment_num]);
            $footer['comments'] = '<span class="post-foot-comments"><a href="' . $link . '#comment" data-comment-count="' . $comment_num . '" aria-label="' . s2_htmlencode($commentLabel) . '">' . $commentLabel . '</a></span>';
        } else {
            $commentLabel = $enabledComments ? $trans('Post comment') : '';
            $footer['comments'] = '<span class="post-foot-comments"><a href="' . $link . '#add-comment" data-comment-count="0"' . ($commentLabel !== '' ? ' aria-label="' . s2_htmlencode($commentLabel) . '"' : '') . '>' . $commentLabel . '</a></span>';
        }
    }

    if ($tagNames !== [] || $inplaceData !== null) {
        $tagLinks = [];
        foreach (\is_array($tags ?? null) ? $tags : [] as $tag) {
            $tagLinks[] = '<a href="' . s2_htmlencode((string)($tag['link'] ?? '')) . '">' . s2_htmlencode((string)($tag['title'] ?? '')) . '</a>';
        }

        $emptyClass = $tagNames === [] ? ' is-empty' : '';
        $editAttributes = $inplaceData === null
            ? ''
            : ' data-post-inplace-tags-values data-placeholder="' . s2_htmlencode($trans('Post tags placeholder')) . '"';
        $footer['tags'] = '<span class="post-foot-tags' . $emptyClass . '"><span class="post-foot-tags-label">'
            . s2_htmlencode($trans('Tags')) . ':</span> <span class="post-tag-values"' . $editAttributes . '>'
            . implode(', ', $tagLinks) . '</span></span>';
    }

	echo implode("\n", $footer);
?>
</div>
</article>
