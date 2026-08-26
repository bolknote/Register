<?php

declare(strict_types = 1);

/**
 * @var \Register\Core\Controller\Rss\FeedItemDto $item
 */

?>
			<item>
				<title><?php echo register_htmlencode($item->title); ?></title>
				<link><?php echo register_htmlencode($item->link); ?></link>
				<description><?php echo register_htmlencode($item->text); ?></description>
<?php if (!empty($item->author)) {?>
				<dc:creator><?php echo register_htmlencode($item->author); ?></dc:creator>
<?php } ?>
				<guid isPermaLink="true"><?php echo register_htmlencode($item->link); ?></guid>
				<pubDate><?php echo gmdate('D, d M Y H:i:s', $item->time) . ' GMT'; ?></pubDate>
				<comments><?php echo register_htmlencode($item->link) . '#comment'; ?></comments>
			</item>
