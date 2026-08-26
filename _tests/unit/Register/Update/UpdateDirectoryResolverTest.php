<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Update;

use Codeception\Test\Unit;
use Register\Update\UpdateDirectoryResolver;

final class UpdateDirectoryResolverTest extends Unit
{
    public function testKeepsWorkspaceInsideTheProtectedCacheDirectory(): void
    {
        self::assertSame(
            '/home/account/public_html/_cache/register-updates',
            UpdateDirectoryResolver::resolve('/home/account/public_html/'),
        );
    }
}
