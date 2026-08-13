<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Search\Admin;

use S2\Cms\Admin\DynamicConfigFormExtenderInterface;

class DynamicConfigFormExtender implements DynamicConfigFormExtenderInterface
{
    /**
     * @return array<mixed>
     */
    #[\Override]
    public function getExtraParamTypes(): array
    {
        return [
            'Search config'                   => 'title',
            'S2_SEARCH_QUICK'                 => 'boolean',
            'S2_SEARCH_RECOMMENDATIONS_LIMIT' => 'int',
        ];
    }
}
