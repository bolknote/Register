<?php

declare(strict_types = 1);

/**
 * Sidebar block. Used in <!-- menu_... -->
 *
 * @var string $title block title
 * @var array $menu links
 * @var string $class placeholder name
 */

$class = isset($class) && $class !== '' ? ' ' . $class : '';

?>
<div class="header<?php echo $class; ?>"><?php echo $title; ?></div>
<?php

if (empty($menu))
	return;

?>
<ul class="menu-block<?php echo $class; ?>">
<?php

foreach ($menu as $item)
{
	if (!empty($item['is_current']))
	{
?>
	<li class="menu-item active"><span><?php echo register_htmlencode($item['title']); ?></span></li>
<?php
	}
	else
	{
?>
	<li class="menu-item">
		<a href="<?php echo register_htmlencode($item['link']); ?>"<?php if (!empty($item['hint'])) echo ' title="', register_htmlencode($item['hint']), '"'; ?>>
			<?php echo register_htmlencode($item['title']); ?>

		</a>
	</li>
<?php
	}
}

?>
</ul>
