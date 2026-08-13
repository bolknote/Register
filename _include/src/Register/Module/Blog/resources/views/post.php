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

?>
<div class="post author"><?php if (!empty($author)) echo s2_htmlencode($author); ?></div>
<h2 class="post head">
<?php if (!empty($title_link)) {?>
	<a href="<?php echo s2_htmlencode($title_link); ?>"><?php echo s2_htmlencode($title); ?></a>
<?php } else {?>
	<?php echo s2_htmlencode($title); ?>
<?php } ?>
<?php if (!empty($favorite) && $favorite !== 2) {?>
    <a href="<?php echo $favoritePostsUrl; ?>" class="favorite-star" title="<?php echo $trans('Favorite posts'); ?>">★</a>
<?php } elseif (!empty($favorite)) {?>
    <span class="favorite-star" title="<?php echo $trans('Favorite posts'); ?>">★</span>
<?php } ?>
</h2>
<div class="post time"><time datetime="<?php echo gmdate(DATE_ATOM, (int)$create_time); ?>"<?php if (trim($display_date ?? '') === ''): ?> data-local-time="datetime" data-locale="<?php echo s2_htmlencode($trans('locale')); ?>"<?php endif; ?>><?php echo s2_htmlencode($time); ?></time></div>
<?php
	echo $text;
	if (!empty($see_also))
		include __DIR__ . '/see_also.php';
?>
<div class="post foot">
<?php
	$footer = [];

	if ($commented && $showComments) {
        if ($comment_num) {
            $footer['comments'] = '<span class="post-foot-comments"><a href="' . $link . '#comment">' . $trans('N Comments', ['%count%' => $comment_num, '{{ count }}' => $comment_num]) . '</a></span>';
        } else {
            $footer['comments'] = '<span class="post-foot-comments"><a href="' . $link . '#add-comment">' . ($enabledComments ? $trans('Post comment') : '') . '</a></span>';
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
