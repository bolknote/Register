<?php
/**
 * @copyright 2024-2025  Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

use Register\AdminYard\Config\FieldConfig;

readonly class PathToAdminEntityConverter
{
    /** @return array<mixed>|null */
    public function getQueryParams(string $_path): ?array
    {
        return ['entity' => 'BlogPost', 'action' => FieldConfig::ACTION_LIST];
    }
}
