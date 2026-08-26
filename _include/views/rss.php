<?php

declare(strict_types = 1);

/**
 * @var string $baseUrl
 * @var string $selfLink
 * @var string $version
 * @var int $maxContentTime
 * @var string $items
 * @var \Register\Core\Controller\Rss\FeedDto $feedInfo
 */

echo '<?xml version="1.0" encoding="utf-8"?>'."\n".
    '<?xml-stylesheet href="'. register_htmlencode($baseUrl) .'/_styles/rss.xslt' .'" type="text/xsl"?>'."\n";

?>
	<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">
		<channel>
			<title><?php echo register_htmlencode($feedInfo->title); ?></title>
			<link><?php echo register_htmlencode($feedInfo->link); ?></link>
			<description><?php echo register_htmlencode($feedInfo->description); ?></description>
			<language><?php echo register_htmlencode(str_replace('_', '-', $feedInfo->language)); ?></language>
			<generator>Register v<?php echo register_htmlencode($version); ?></generator>
			<docs>https://www.rssboard.org/rss-specification</docs>
			<ttl>10</ttl>
			<atom:link href="<?php echo register_htmlencode($selfLink); ?>" rel="self" type="application/rss+xml" />
<?php if ($maxContentTime > 0) { ?>
			<lastBuildDate><?php echo gmdate('D, d M Y H:i:s', $maxContentTime).' GMT'; ?></lastBuildDate>
<?php } ?>
<?php echo $items; ?>
		</channel>
	</rss>
<?php
