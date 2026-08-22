<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

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
            'Blog config'   => 'title',
            'S2_BLOG_TITLE' => 'string',
            'S2_SITE_TAGLINE' => 'string',
        ];
    }
}
