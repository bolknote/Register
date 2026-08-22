<?php

declare(strict_types = 1);

/**
 * Content of <!-- register_tags --> placeholder.
 * Also used in the register_blog RSS feed.
 *
 * @var string $title
 * @var array $tags
 */

foreach ($tags as &$tag)
	$tag = '<a href="'.register_htmlencode($tag['link']).'">'.register_htmlencode($tag['title']).'</a>';

?>
<p class="article_tags">
	<?php echo $title; ?>:
	<?php echo implode(', ', $tags); ?>
</p>
