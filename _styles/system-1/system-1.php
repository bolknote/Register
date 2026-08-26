<?php

declare(strict_types = 1);

use Register\Core\Asset\AssetPack;

return (new AssetPack(__DIR__))
    ->addMeta('<meta name="viewport" content="width=device-width, initial-scale=1">')
    ->setColorScheme(AssetPack::COLOR_SCHEME_LIGHT)
    ->addMeta('<meta name="theme-color" content="#b8b8b8">')
    ->addCss('../register/site.css', [AssetPack::OPTION_MERGE])
    ->addCss('system-1.css', [AssetPack::OPTION_MERGE])
    ->addHeadJs('../../_assets/register/local-time.js', [AssetPack::OPTION_DEFER])
    ->addJs('../register/script.js', [AssetPack::OPTION_MERGE, AssetPack::OPTION_DEFER])
    ->addJs('system-1.js', [AssetPack::OPTION_MERGE, AssetPack::OPTION_DEFER])
    ->setFavIcon('finder.png')
;
