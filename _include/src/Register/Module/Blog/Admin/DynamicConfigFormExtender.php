<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

use Register\Core\Admin\DynamicConfigFormExtenderInterface;

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
            'REGISTER_BLOG_TITLE' => 'string',
            'REGISTER_SITE_TAGLINE' => 'string',
        ];
    }
}
