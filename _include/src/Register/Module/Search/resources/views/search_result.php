<?php

declare(strict_types = 1);

/**
 * @var callable $thumbnailHtml
 * @var callable $formatDate
 * @var $plainTitle string
 * @var $title string
 * @var $link string
 * @var $descr string
 * @var $time string
 * @var $images \Register\Rose\Entity\Metadata\ImgCollection|\Register\Rose\Entity\Metadata\Img[]
 * @var list<array{title: string, link: string}> $tags
 */
$previewImages = [];
$seenImageSources = [];
foreach ($images as $image) {
    $width  = $image->getWidth();
    $height = $image->getHeight();
    if (is_numeric($width) && is_numeric($height) && ((float)$width < 64 || (float)$height < 64)) {
        continue;
    }

    $src = $image->getSrc();
    if ($src === '' || isset($seenImageSources[$src])) {
        continue;
    }

    $seenImageSources[$src] = true;
    $previewImages[] = $image;
}

$imageCount = \count($previewImages);
$previewLimit = 4;
$tagLinks = [];
foreach ($tags as $tag) {
    $tagLinks[] = '<a class="post-tag-link" href="' . register_htmlencode($tag['link']) . '">'
        . register_htmlencode($tag['title']) . '</a>';
}
?>
<article class="search-result<?php echo $imageCount > 0 ? ' search-result-has-media' : ''; ?>">
    <h2 class="search-result-title">
        <a class="title" href="<?php echo register_htmlencode($link); ?>"><?php echo $title; ?></a>
    </h2>

    <?php if ($imageCount > 0): ?>
        <div class="search-result-media">
            <a class="search-result-media-link" href="<?php echo register_htmlencode($link); ?>"
                aria-label="<?php echo register_htmlencode($plainTitle); ?>">
                <?php foreach ($previewImages as $index => $image): ?>
                    <?php
                    if ($index >= $previewLimit) {
                        break;
                    }
                    ?>
                    <span class="search-result-media-item">
                        <?php echo $thumbnailHtml($image->getSrc(), $image->getWidth(), $image->getHeight(), 360, 240); ?>
                        <?php if ($index === $previewLimit - 1 && $imageCount > $previewLimit): ?>
                            <span class="search-result-media-more" aria-hidden="true">+<?php echo $imageCount - $previewLimit; ?></span>
                        <?php endif; ?>
                    </span>
                <?php endforeach; ?>
            </a>
        </div>
    <?php endif; ?>

    <?php if (trim($descr) !== ''): ?>
        <div class="search-result-snippet"><?php echo $descr; ?></div>
    <?php endif; ?>
    <?php if (!empty($time) || $tagLinks !== []): ?>
        <footer class="search-result-meta stuff">
            <?php if (!empty($time)): ?>
                <time datetime="<?php echo gmdate(DATE_ATOM, (int)$time); ?>"><?php echo $formatDate($time); ?></time>
            <?php endif; ?>
            <?php if ($tagLinks !== []): ?>
                <span class="search-result-tags post-tag-list" aria-label="<?php echo register_htmlencode($trans('Tags')); ?>">
                    <span class="post-tag-values"><?php echo implode('', $tagLinks); ?></span>
                </span>
            <?php endif; ?>
        </footer>
    <?php endif; ?>
</article>
