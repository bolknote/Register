<?php

declare(strict_types = 1);

/**
 * Content of <!-- register_tags_list --> placeholder and the tags list page text.
 *
 * @var array $tags
 */

foreach ($tags as &$tag)
	$tag = '<a href="'.register_htmlencode($tag['link']).'">'.register_htmlencode($tag['title']).'</a>'.(isset($tag['num']) ? ' ('.$tag['num'].')' : '');
unset($tag);

?>
<div class="tags_list">
	<?php echo implode('<br/>', $tags); ?>
</div>
