<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;

final class AdminListStylesheetTest extends Unit
{
    public function testMenuLayoutDoesNotStretchListPagination(): void
    {
        $root = \dirname(__DIR__, 4);
        $adminOverride = file_get_contents($root . '/_admin/css/admin-override.css');
        $register = file_get_contents($root . '/_admin/css/register.css');

        self::assertIsString($adminOverride);
        self::assertIsString($register);
        self::assertStringNotContainsString("\nnav {\n", $adminOverride);
        self::assertStringContainsString('.admin-shell > nav {', $adminOverride);

        self::assertSame(
            1,
            preg_match(
                '/\.admin-content \.list-content \.pagination\s*\{(?<rules>[^}]*)}/s',
                $register,
                $matches,
            ),
        );
        self::assertStringContainsString('width: fit-content;', $matches['rules']);
        self::assertStringContainsString('justify-content: center;', $matches['rules']);
        self::assertStringContainsString('flex-wrap: wrap;', $matches['rules']);
    }
}
