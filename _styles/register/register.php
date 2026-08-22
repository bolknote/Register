<?php

declare(strict_types = 1);

use Register\Core\Asset\AssetPack;

return (new AssetPack(__DIR__))
    ->addMeta('<meta name="viewport" content="width=device-width, initial-scale=1">')
    ->setColorScheme(AssetPack::COLOR_SCHEME_SYSTEM)
    ->addMeta('<meta name="theme-color" media="(prefers-color-scheme: light)" content="#f7f3e9">')
    ->addMeta('<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#202020">')
    ->addCss('site.css', [AssetPack::OPTION_MERGE])
    ->addCss('../../_assets/register/local-time.css')
    ->addHeadJs('../../_assets/register/local-time.js')
    ->addJs('script.js', [AssetPack::OPTION_MERGE, AssetPack::OPTION_DEFER])
    ->setFavIcon('favicon.svg')
;
