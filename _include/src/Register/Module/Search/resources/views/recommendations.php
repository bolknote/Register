<?php

declare(strict_types = 1);

/**
 * @var callable $trans
 * @var callable $makeLink
 * @var callable $dateAndTime
 * @var array  $raw
 * @var array  $log
 * @var ?array $content
 */

use Register\Module\Search\Layout\ImgDto;
use Register\Module\Search\Rose\CustomExtractor;

if ($content === null || $content === []) {
    return;
}

$getImgMarkup = static function (ImgDto $imgDto, int $columnNum): string
{
    $src                = $imgDto->getSrc();
    $class              = $imgDto->getClass();
    $width              = max(1, (int)round($imgDto->getWidth()));
    $height             = max(1, (int)round($imgDto->getHeight()));
    $fallbackAttributes = '';
    $escapedSrc         = register_htmlencode($src);

    if ($class === 'right') {
        return "<div class='recommendation-img-right-wrapper recommendation-img-right-wide'><img loading='lazy' src=\"$escapedSrc\" width='$width' height='$height' class='recommendation-img' alt=''></div>";
    }

    if ($class === 'right2') {
        return "<div class='recommendation-img-right-wrapper recommendation-img-right-compact'><img loading='lazy' src=\"$escapedSrc\" width='$width' height='$height' class='recommendation-img' alt=''></div>";
    }

    if ($class === 'thumb') {
        return "<div class='recommendation-img-thumb-wrapper'><img loading='lazy' class='recommendation-img' src='$escapedSrc' width='$width' height='$height' alt=''></div><br clear='left'>";
    }

    $class = '';
    if (strpos($src, 'youtube.com')) {
        if ($columnNum === 1) {
            $src = str_replace('hq720', 'sddefault', $src);
            $fallbackAttributes = 'data-youtube-fallback-width="640" data-youtube-fallback-from="sddefault" data-youtube-fallback-to="hqdefault"';
        } else {
            $fallbackAttributes = 'data-youtube-fallback-width="1280" data-youtube-fallback-from="hq720" data-youtube-fallback-to="hqdefault"';
        }
        $class = 'recommendation-video-wrapper';
    }

    $escapedSrc = register_htmlencode($src);

    return "<div class='recommendation-img-wrapper {$class}'><img loading='lazy' class='recommendation-img' src='$escapedSrc' width='$width' height='$height' alt='' {$fallbackAttributes}></div>";
};

$getGridClasses = static function (string $area): string
{
    if ($area === '' || $area === 'auto') {
        return '';
    }

    $parts = explode('/', $area);
    if (\count($parts) !== 2 && \count($parts) !== 4) {
        return '';
    }

    $properties = ['row-start', 'column-start', 'row-end', 'column-end'];
    $classes    = [];
    foreach ($parts as $index => $part) {
        if (!ctype_digit($part)) {
            return '';
        }

        $line = (int)$part;
        if ($line < 1 || $line > 7) {
            return '';
        }

        $classes[] = 'recommendation-grid-' . $properties[$index] . '-' . $line;
    }

    return implode(' ', $classes);
};

$getColumnsNumFromGridArea = static function (string $area): int
{
    $parts = explode('/', $area);
    if (count($parts) === 4 && ctype_digit($parts[3]) && ctype_digit($parts[1])) {
        return (int)$parts[3] - (int)$parts[1];
    }

    return 1;
};

$getReducedImg = function (ImgDto $img): ImgDto
{
    $src = $img->getSrc();
    if (str_starts_with($src, CustomExtractor::YOUTUBE_PROTOCOL)) {
        return new ImgDto(
            'https://img.youtube.com/vi/' . substr($src, \strlen(CustomExtractor::YOUTUBE_PROTOCOL)) . '/hq720.jpg',
            640,
            360,
            $img->getClass()
        )/*->addSrc('https://img.youtube.com/vi/' . substr($src, \strlen(CustomExtractor::YOUTUBE_PROTOCOL)) . '/hq720.jpg')*/ ;
    }

    return $img;
};

$maxLine = 0;
foreach ($content as $recommendation) {
    $pos = $recommendation['position'];
    $posPieces = explode('/', $pos);
    if (count($posPieces) === 2) {
        $maxLine = max($maxLine, (int)$posPieces[1] + 1);
    } elseif (count($posPieces) === 4) {
        $maxLine = max($maxLine, $posPieces[3]);
    }
}

?>
<h2 class="recommendation-title" id="recommendations"><?php echo $trans('Read next'); ?></h2>
<!-- <?php echo end($log); ?> -->
<div class="recommendations<?php if ($maxLine > 5) {echo ' recommendations-columns-' . ($maxLine - 1); } ?>">
    <?php foreach ($content as $recommendation) : ?>
        <div class="recommendation <?= $getGridClasses($recommendation['position']) ?>">
            <a class="recommendation-link" href="<?= $makeLink($recommendation['url']) ?>">
                <?php
                if ($recommendation['image'] !== null) {
                    $columnNum = $getColumnsNumFromGridArea($recommendation['position']);

                    $imgDto = $getReducedImg($recommendation['image']);

                    echo $getImgMarkup($imgDto, $columnNum);
                }
                ?>
                <span class="recommendation-header recommendation-header-<?= $recommendation['headingSize'] ?>"><?php echo register_htmlencode($recommendation['title']); ?></span>
            </a>
            <div class="recommendation-snippet"><?= $recommendation['snippet'] ?></div>
            <div class="recommendation-date"
                 title="<?php echo $recommendation['date'] ? $dateAndTime($recommendation['date']->getTimestamp()) : ''; ?>">
                <?= $recommendation['date'] ? $recommendation['date']->format('Y') : '' ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
