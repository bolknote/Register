<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth\Admin;

use Register\Module\LinkHealth\Manifest;
use Register\Core\Admin\DynamicConfigFormExtenderInterface;

final class DynamicConfigFormExtender implements DynamicConfigFormExtenderInterface
{
    /** @return array<string, string> */
    #[\Override]
    public function getExtraParamTypes(): array
    {
        return [
            'Link health config'              => 'title',
            Manifest::AUTO_REPAIR_CONFIG_KEY => 'boolean',
        ];
    }
}
