<?php

declare(strict_types = 1);

/**
 * @var string $author
 * @var string $title
 * @var string $title_link
 * @var string $time
 * @var int $create_time
 * @var string $text
 * @var array<int, array{link: string, title: string}> $tags
 * @var bool $commented
 * @var int $comment_num
 * @var bool $favorite
 * @var string|null $see_also
 */

$tagLinks = [];
foreach ($tags as $tag) {
    $tagLinks[] = '<a href="' . s2_htmlencode($tag['link']) . '">' . s2_htmlencode($tag['title']) . '</a>';
}

?>
<h2 class="preview">
<?php if ($title_link !== '') {?>
    <a href="<?php echo s2_htmlencode($title_link); ?>"><?php echo s2_htmlencode($title); ?></a>
<?php } else {?>
    <?php echo s2_htmlencode($title); ?>
<?php } ?>
</h2>
<div class="preview meta">
    <span class="preview time"><time datetime="<?php echo date(DATE_ATOM, (int)$create_time); ?>"><?php echo s2_htmlencode($time); ?></time></span>
<?php if ($tagLinks !== []) { ?>
    <span class="preview tags"><?php echo implode(', ', $tagLinks); ?></span>
<?php } ?>
</div>
<div class="post body"><?php echo $text; ?></div>
<?php

if (!empty($see_also)) {
    include __DIR__ . '/see_also.php';
}
