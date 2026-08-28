<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Dashboard;

interface DashboardStatProviderInterface
{
    public function getHtml(): string;
}
