<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Config;

use Codeception\Test\Unit;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Config\DynamicSecretStore;
use S2\Cms\Framework\Exception\ConfigurationException;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\Filesystem\Filesystem;

final class DynamicSecretStoreTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_secrets_test_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        if ($this->temporaryDirectory !== '') {
            (new Filesystem())->remove($this->temporaryDirectory);
        }
    }

    public function testProtectsAndHydratesManagedSecrets(): void
    {
        $filename = $this->temporaryDirectory . '/config.secrets.php';
        $store = new DynamicSecretStore($filename, ['AI_KEY', 'AKISMET_KEY']);

        $protected = $store->protect([
            'AI_KEY'      => 'top-secret-ai-key',
            'AKISMET_KEY' => '',
            'SITE_NAME'   => 'Example',
        ]);

        self::assertSame(DynamicSecretStore::DATABASE_PLACEHOLDER, $protected['cache']['AI_KEY']);
        self::assertSame('top-secret-ai-key', $protected['runtime']['AI_KEY']);
        self::assertSame(
            ['AI_KEY' => DynamicSecretStore::DATABASE_PLACEHOLDER],
            $protected['database_updates'],
        );
        self::assertSame('Example', $protected['cache']['SITE_NAME']);
        self::assertFileExists($filename);
        $permissions = fileperms($filename);
        self::assertIsInt($permissions);
        self::assertSame(0600, $permissions & 0777);

        $contents = file_get_contents($filename);
        self::assertIsString($contents);
        self::assertStringContainsString('top-secret-ai-key', $contents);
        self::assertStringNotContainsString('Example', $contents);
        self::assertFalse($store->requiresRegeneration($protected['cache']));
        self::assertSame('top-secret-ai-key', $store->hydrate($protected['cache'])['AI_KEY']);
    }

    public function testClearsSecretWhenDatabaseValueIsCleared(): void
    {
        $filename = $this->temporaryDirectory . '/config.secrets.php';
        $store = new DynamicSecretStore($filename, ['AI_KEY']);
        $store->protect(['AI_KEY' => 'top-secret-ai-key']);

        $protected = $store->protect(['AI_KEY' => '']);

        self::assertSame('', $protected['runtime']['AI_KEY']);
        self::assertSame([], $protected['database_updates']);
        self::assertSame([], include $filename);
        self::assertTrue($store->requiresRegeneration([
            'AI_KEY' => DynamicSecretStore::DATABASE_PLACEHOLDER,
        ]));
    }

    public function testRejectsPlaceholderWhenPrivateFileIsMissing(): void
    {
        $store = new DynamicSecretStore(
            $this->temporaryDirectory . '/missing.php',
            ['AI_KEY'],
        );

        $this->expectException(ConfigurationException::class);
        $store->hydrate(['AI_KEY' => DynamicSecretStore::DATABASE_PLACEHOLDER]);
    }

    public function testProviderMigratesDatabaseAndCacheWithoutExposingSecret(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE config (name TEXT PRIMARY KEY, value TEXT)');

        $insert = $pdo->prepare('INSERT INTO config (name, value) VALUES (:name, :value)');
        if (!$insert instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to prepare the dynamic-secret test fixture.');
        }

        $insert->execute([
            'name'  => 'AI_KEY',
            'value' => 'database-secret',
        ]);

        $dbLayer   = new DbLayer($pdo);
        $cacheFile = $this->temporaryDirectory . '/cache.php';
        $secretFile = $this->temporaryDirectory . '/config.secrets.php';
        $store     = new DynamicSecretStore($secretFile, ['AI_KEY']);
        $provider  = new DynamicConfigProvider($dbLayer, $cacheFile, false, $store);

        $provider->regenerate();

        self::assertSame('database-secret', $provider->get('AI_KEY'));
        self::assertSame(DynamicSecretStore::DATABASE_PLACEHOLDER, $this->databaseValue($pdo, 'AI_KEY'));
        $cacheContents = file_get_contents($cacheFile);
        self::assertIsString($cacheContents);
        self::assertStringNotContainsString('database-secret', $cacheContents);
        self::assertSame(
            'database-secret',
            (new DynamicConfigProvider($dbLayer, $cacheFile, false, $store))->get('AI_KEY'),
        );
    }

    public function testProviderMigratesASecretFromLegacyCacheOnFirstRead(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE config (name TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO config (name, value) VALUES ('AI_KEY', 'legacy-secret')");

        $cacheFile = $this->temporaryDirectory . '/cache.php';
        file_put_contents($cacheFile, "<?php return ['AI_KEY' => 'legacy-secret'];");
        $dbLayer  = new DbLayer($pdo);
        $store    = new DynamicSecretStore($this->temporaryDirectory . '/config.secrets.php', ['AI_KEY']);
        $provider = new DynamicConfigProvider($dbLayer, $cacheFile, false, $store);

        self::assertSame('legacy-secret', $provider->get('AI_KEY'));
        self::assertSame(DynamicSecretStore::DATABASE_PLACEHOLDER, $this->databaseValue($pdo, 'AI_KEY'));
    }

    private function databaseValue(\PDO $pdo, string $parameterName): string
    {
        $statement = $pdo->prepare('SELECT value FROM config WHERE name = :name');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to prepare the dynamic-secret fixture query.');
        }

        $statement->execute(['name' => $parameterName]);
        $value = $statement->fetchColumn();
        if (!\is_string($value)) {
            throw new \RuntimeException('The dynamic-secret fixture value is missing.');
        }

        return $value;
    }
}
