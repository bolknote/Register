<?php

declare(strict_types = 1);

use S2\Cms\Asset\AssetPack;

return (new AssetPack(__DIR__))
    ->addMeta('<meta name="viewport" content="width=device-width, initial-scale=1">')
    ->setColorScheme(AssetPack::COLOR_SCHEME_DARK)
    ->addMeta('<meta name="theme-color" content="#071a18">')
    ->addCss('../register/site.css', [AssetPack::OPTION_MERGE])
    ->addCss('pixel-forest.css', [AssetPack::OPTION_MERGE])
    ->addCss('../../_assets/register/local-time.css')
    ->addHeadJs('../../_assets/register/local-time.js')
    ->addJs('../register/script.js', [AssetPack::OPTION_MERGE, AssetPack::OPTION_DEFER])
    ->setFavIcon('favicon.svg')
;
