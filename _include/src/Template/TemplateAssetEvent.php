<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Template;

use Register\Core\Asset\AssetPack;

readonly class TemplateAssetEvent
{
    public function __construct(public AssetPack $assetPack)
    {
    }
}
