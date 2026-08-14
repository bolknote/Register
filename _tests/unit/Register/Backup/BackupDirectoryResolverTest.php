<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Backup;

use Codeception\Test\Unit;
use Register\Backup\BackupDirectoryResolver;

final class BackupDirectoryResolverTest extends Unit
{
    public function testDefaultDirectoryLivesBesideTheDocumentRootAndIsInstallationSpecific(): void
    {
        $directory = BackupDirectoryResolver::resolve('/srv/www/register', null);

        self::assertMatchesRegularExpression('#^/srv/www/register-backups-[a-f0-9]{12}$#D', $directory);
        self::assertSame($directory, BackupDirectoryResolver::resolve('/srv/www/register/', ''));
        self::assertNotSame($directory, BackupDirectoryResolver::resolve('/srv/www/another-register', null));
    }

    public function testConfiguredRelativeAndAbsoluteDirectoriesAreResolved(): void
    {
        self::assertSame(
            '/srv/www/register/private/backups',
            BackupDirectoryResolver::resolve('/srv/www/register/', 'private/backups/'),
        );
        self::assertSame(
            '/var/backups/register',
            BackupDirectoryResolver::resolve('/srv/www/register', '/var/backups/register/'),
        );
    }
}
