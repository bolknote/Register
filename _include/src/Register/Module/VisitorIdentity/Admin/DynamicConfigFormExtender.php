<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity\Admin;

use Register\Module\VisitorIdentity\Manifest;
use S2\Cms\Admin\DynamicConfigFormExtenderInterface;

final class DynamicConfigFormExtender implements DynamicConfigFormExtenderInterface
{
    /** @return array<string, string> */
    #[\Override]
    public function getExtraParamTypes(): array
    {
        return [Manifest::SECRET_CONFIG_KEY => 'hidden'];
    }
}
