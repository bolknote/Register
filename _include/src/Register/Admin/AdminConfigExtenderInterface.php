<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin;

use Register\AdminYard\Config\AdminConfig;

interface AdminConfigExtenderInterface
{
    public function extend(AdminConfig $adminConfig): void;
}
