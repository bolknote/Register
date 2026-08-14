<?php

declare(strict_types = 1);

/**
 * @var string $link
 * @var int|null $modify_time
 */

?>
    <url>
        <loc><?php echo s2_htmlencode($link); ?></loc>
<?php if ($modify_time !== null) { ?>
        <lastmod><?php echo gmdate('c', $modify_time); ?></lastmod>
<?php } ?>
    </url>
