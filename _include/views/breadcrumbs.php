<?php

declare(strict_types = 1);

/**
 * Content of <!-- breadcrumbs --> placeholder
 *
 * @var array $breadcrumbs
 */

$num = 0;
foreach ($breadcrumbs as $item)
{
	if ($num > 0)
		echo ' &rarr; ';

	if (!empty($item['link']))
		echo '<a class="bread-crumb-item" href="'.register_htmlencode($item['link']).'">'.register_htmlencode($item['title']).'</a>';
	else
		echo '<span class="bread-crumb-item">'.register_htmlencode($item['title']).'</span>';

	$num++;
}
