<?php

declare(strict_types = 1);

/**
 * Content of <!-- register_blog_back_forward --> placeholder
 *
 * @var array $back
 * @var array $forward
 */

?>
<ul class="back_forward">
<?php if (!empty($back)) { ?>
	<li class="back">
		<span class="arrow">&larr;</span>
		<a href="<?php echo register_htmlencode($back['link']); ?>"><?php echo register_htmlencode($back['title']); ?></a>
	</li>
<?php } else { ?>
	<li class="back empty">
		<span class="arrow">&larr;</span>
	</li>
<?php } ?>
<?php if (!empty($forward)) { ?>
	<li class="forward">
		<span class="arrow">&rarr;</span>
		<a href="<?php echo register_htmlencode($forward['link']); ?>"><?php echo register_htmlencode($forward['title']); ?></a>
	</li>
<?php } else { ?>
	<li class="forward empty">
		<span class="arrow">&rarr;</span>
	</li>
<?php } ?>
</ul>
