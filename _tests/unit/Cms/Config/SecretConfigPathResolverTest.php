<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Config;

use Codeception\Test\Unit;
use S2\Cms\Config\SecretConfigPathResolver;

final class SecretConfigPathResolverTest extends Unit
{
    public function testUsesPrivateApplicationRootForSplitDeployment(): void
    {
        self::assertSame(
            '/home/account/register-app/config.secrets.php',
            SecretConfigPathResolver::resolve(
                '/home/account/register-app/',
                '/home/account/public_html/',
                null,
            ),
        );
    }

    public function testUsesStableSiblingFileForRepositoryRootDeployment(): void
    {
        $resolved = SecretConfigPathResolver::resolve('/srv/www/register', '/srv/www/register/', null);

        self::assertMatchesRegularExpression(
            '#^/srv/www/register-secrets-[a-f0-9]{12}\.php$#D',
            $resolved,
        );
        self::assertSame(
            $resolved,
            SecretConfigPathResolver::resolve('/srv/www/register/', '/srv/www/register', ''),
        );
    }

    public function testDoesNotTreatApplicationBelowPublicRootAsPrivate(): void
    {
        self::assertMatchesRegularExpression(
            '#^/srv/www/register-secrets-[a-f0-9]{12}\.php$#D',
            SecretConfigPathResolver::resolve('/srv/www/public/app', '/srv/www/public', null),
        );
    }

    public function testResolvesConfiguredRelativeAndAbsolutePaths(): void
    {
        self::assertSame(
            '/srv/register/private/secrets.php',
            SecretConfigPathResolver::resolve('/srv/register', '/srv/public', 'private/secrets.php'),
        );
        self::assertSame(
            '/var/lib/register/secrets.php',
            SecretConfigPathResolver::resolve(
                '/srv/register',
                '/srv/public',
                '/var/lib/register/secrets.php',
            ),
        );
    }
}
