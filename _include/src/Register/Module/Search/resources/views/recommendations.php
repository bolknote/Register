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
    $percent         = 100.0 * $imgDto->getRatio();
    $src             = $imgDto->getSrc();
    $class           = $imgDto->getClass();
    $fallbackAttributes = '';
    $escapedSrc         = s2_htmlencode($src);

    if ($class === 'right') {
        $height = $percent * 0.35;

        return "<div class='recommendation-img-right-wrapper' style=\"width: 35%; padding-top: {$height}%\"><img loading='lazy' src=\"$escapedSrc\" class='recommendation-img' alt=''></div>";
    }

    if ($class === 'right2') {
        $height = $percent * 0.18;

        return "<div class='recommendation-img-right-wrapper' style=\"width: 18%; padding-top: {$height}%\"><img loading='lazy' src=\"$escapedSrc\" class='recommendation-img' alt=''></div>";
    }

    if ($class === 'thumb') {
        $h = 120.0 * $imgDto->getRatio();
        $w = 120;

        return "<div class='recommendation-img-thumb-wrapper' style='height: {$h}px; width: {$w}px;'><img loading='lazy' class='recommendation-img' src='$escapedSrc' alt=''></div><br clear='left'>";
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

    $escapedSrc = s2_htmlencode($src);

    return "<div class='recommendation-img-wrapper {$class}' style='padding-top: $percent%'><img loading='lazy' class='recommendation-img' src='$escapedSrc' alt='' {$fallbackAttributes}></div>";
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
<div class="recommendations" style="<?php if ($maxLine > 5) {echo 'grid-template-columns: repeat(' . ($maxLine - 1) . ', 1fr);'; } ?>">
    <?php foreach ($content as $recommendation) : ?>
        <div class="recommendation" style="grid-area: <?php echo $recommendation['position'] ?: 'auto'; ?>">
            <a class="recommendation-link" href="<?= $makeLink($recommendation['url']) ?>">
                <?php
                if ($recommendation['image'] !== null) {
                    $columnNum = $getColumnsNumFromGridArea($recommendation['position']);

                    $imgDto = $getReducedImg($recommendation['image']);

                    echo $getImgMarkup($imgDto, $columnNum);
                }
                ?>
                <span class="recommendation-header recommendation-header-<?= $recommendation['headingSize'] ?>"><?php echo s2_htmlencode($recommendation['title']); ?></span>
            </a>
            <div class="recommendation-snippet"><?= $recommendation['snippet'] ?></div>
            <div class="recommendation-date"
                 title="<?php echo $recommendation['date'] ? $dateAndTime($recommendation['date']->getTimestamp()) : ''; ?>">
                <?= $recommendation['date'] ? $recommendation['date']->format('Y') : '' ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
