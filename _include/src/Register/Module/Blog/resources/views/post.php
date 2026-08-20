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
?>
<article
    class="post-card<?php echo $inplaceData !== null ? ' is-manageable' : ''; ?>"
    data-post-id="<?php echo $postId; ?>"
<?php if ($inplaceData !== null): ?>
    data-edit-error="<?php echo s2_htmlencode($trans('Post editing failed')); ?>"
    data-apply-error="<?php echo s2_htmlencode($trans('Post update apply failed')); ?>"
    data-deleted-message="<?php echo s2_htmlencode($trans('Post deleted')); ?>"
    data-list-label="<?php echo s2_htmlencode($trans('Post list')); ?>"
<?php endif; ?>
>
<?php if ($inplaceData !== null): ?>
<nav class="post-inplace-tools" aria-label="<?php echo $trans('Post tools'); ?>">
    <a class="post-inplace-button post-edit-start" href="<?php echo s2_htmlencode($inplaceData['admin_edit_url']); ?>" title="<?php echo $trans('Edit post inplace'); ?>" aria-label="<?php echo $trans('Edit post inplace'); ?>"><span aria-hidden="true">✏️</span></a>
    <button class="post-inplace-button post-delete-start" type="button" title="<?php echo $trans('Delete post inplace'); ?>" aria-label="<?php echo $trans('Delete post inplace'); ?>"><span aria-hidden="true">🗑️</span></button>
</nav>
<form class="post-inplace-edit-form" method="post" action="<?php echo s2_htmlencode($inplaceData['action_url']); ?>" hidden>
    <label class="post-inplace-field post-inplace-title-field">
        <span><?php echo $trans('Post title'); ?></span>
        <input name="title" type="text" value="<?php echo s2_htmlencode($title); ?>" maxlength="255" required>
    </label>
    <label class="post-inplace-field post-inplace-body-field">
        <span><?php echo $trans('Post text'); ?></span>
        <textarea name="body" rows="18"><?php echo s2_htmlencode($text); ?></textarea>
    </label>
    <input type="hidden" name="inplace_action" value="edit">
    <input type="hidden" name="inplace_token" value="<?php echo s2_htmlencode($inplaceData['token']); ?>">
    <input type="hidden" name="revision" value="<?php echo $inplaceData['revision']; ?>">
    <input type="hidden" name="return_to" value="<?php echo s2_htmlencode($inplaceData['return_to']); ?>">
    <p class="post-inplace-error" role="alert" tabindex="-1" hidden></p>
    <div class="post-inplace-actions">
        <button type="submit"><?php echo $trans('Save post changes'); ?></button>
        <button class="post-edit-cancel" type="button"><?php echo $trans('Cancel post changes'); ?></button>
    </div>
</form>
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
	<a href="<?php echo s2_htmlencode($title_link); ?>"><span class="post-title-text"><?php echo s2_htmlencode($title); ?></span></a>
<?php } else {?>
	<span class="post-title-text"><?php echo s2_htmlencode($title); ?></span>
<?php } ?>
<?php if (!empty($favorite) && $favorite !== 2) {?>
    <a href="<?php echo $favoritePostsUrl; ?>" class="favorite-star" title="<?php echo $trans('Favorite posts'); ?>">★</a>
<?php } elseif (!empty($favorite)) {?>
    <span class="favorite-star" title="<?php echo $trans('Favorite posts'); ?>">★</span>
<?php } ?>
</<?php echo $heading; ?>>
<div class="post time"><time datetime="<?php echo gmdate(DATE_ATOM, (int)$create_time); ?>"<?php if (trim($display_date ?? '') === ''): ?> data-local-time="datetime" data-locale="<?php echo s2_htmlencode($trans('locale')); ?>"<?php endif; ?>><?php echo s2_htmlencode($time); ?></time></div>
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

	if (!empty($tags))
	{
		foreach ($tags as &$tag)
			$tag = '<a href="'.$tag['link'].'">'.$tag['title'].'</a>';
		unset($tag);

		$footer['tags'] = '<span class="post-foot-tags">' . $trans('Tags') . ': ' . implode(', ', $tags) . '</span>';
	}

	echo implode("\n", $footer);
?>
</div>
</article>
