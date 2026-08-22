<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Config;

use Codeception\Test\Unit;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Config\DynamicSecretParameterRegistry;
use Register\Core\Config\DynamicSecretStore;
use Register\Core\Framework\Exception\ConfigurationException;
use Register\Core\Pdo\DbLayer;
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

    public function testNamesMissingCacheValueWhenPrivateFileIsMissing(): void
    {
        $store = new DynamicSecretStore(
            $this->temporaryDirectory . '/missing.php',
            ['AI_KEY'],
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The private dynamic-secret file is missing the value "AI_KEY" referenced by the configuration cache.',
        );
        $store->hydrate(['AI_KEY' => DynamicSecretStore::DATABASE_PLACEHOLDER]);
    }

    public function testNamesMissingDatabaseValueWhenPrivateFileIsMissing(): void
    {
        $store = new DynamicSecretStore(
            $this->temporaryDirectory . '/missing.php',
            ['AI_KEY'],
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'The private dynamic-secret file is missing the value "AI_KEY" referenced by the database.',
        );
        $store->protect(['AI_KEY' => DynamicSecretStore::DATABASE_PLACEHOLDER]);
    }

    public function testHydratesAnEarlierMigratedSecretWithoutMigratingFreshValues(): void
    {
        $filename = $this->temporaryDirectory . '/config.secrets.php';
        $legacyStore = new DynamicSecretStore($filename, ['AI_KEY', 'ANTISPAM_SECRET']);
        $legacyStore->protect([
            'AI_KEY'         => 'api-secret',
            'ANTISPAM_SECRET' => 'antispam-secret',
        ]);

        $store = new DynamicSecretStore($filename, ['AI_KEY'], ['ANTISPAM_SECRET']);
        $protected = $store->protect([
            'AI_KEY'          => DynamicSecretStore::DATABASE_PLACEHOLDER,
            'ANTISPAM_SECRET' => DynamicSecretStore::DATABASE_PLACEHOLDER,
        ]);

        self::assertSame('api-secret', $protected['runtime']['AI_KEY']);
        self::assertSame('antispam-secret', $protected['runtime']['ANTISPAM_SECRET']);
        self::assertSame([], $protected['database_updates']);
        self::assertFalse($store->requiresRegeneration($protected['cache']));
        self::assertSame('antispam-secret', $store->hydrate($protected['cache'])['ANTISPAM_SECRET']);

        $freshFilename = $this->temporaryDirectory . '/fresh.secrets.php';
        $freshStore = new DynamicSecretStore($freshFilename, ['AI_KEY'], ['ANTISPAM_SECRET']);
        $fresh = $freshStore->protect([
            'AI_KEY'          => '',
            'ANTISPAM_SECRET' => 'database-only-secret',
        ]);
        self::assertSame('database-only-secret', $fresh['cache']['ANTISPAM_SECRET']);
        self::assertFileDoesNotExist($freshFilename);
    }

    public function testKeepsExtensionPrivateSecretOutsideDatabaseAndCacheLifecycle(): void
    {
        $filename = $this->temporaryDirectory . '/config.secrets.php';
        $name     = 'REGISTER_EXTENSION_ACTIVITYPUB_MASTER_KEY';
        $registry = new DynamicSecretParameterRegistry(['AI_KEY']);
        $registry->registerExtensionPrivate($name);

        $store = new DynamicSecretStore($filename, $registry);

        $secret = $store->getOrCreateExtensionPrivate($name);

        self::assertSame($secret, $store->getOrCreateExtensionPrivate($name));
        self::assertSame($secret, $store->getExtensionPrivate($name));
        self::assertSame(43, \strlen($secret));
        self::assertSame([], $store->protect(['AI_KEY' => ''])['database_updates']);
        self::assertSame($secret, (include $filename)[$name] ?? null);

        $store->replaceExtensionPrivate($name, str_repeat('r', 43));
        self::assertSame(str_repeat('r', 43), $store->getExtensionPrivate($name));
        self::assertFileExists($filename . '.lock');

        $permissions = fileperms($filename . '.lock');
        self::assertIsInt($permissions);
        self::assertSame(0600, $permissions & 0777);
    }

    public function testDisabledExtensionSecretDoesNotBreakCoreSecretHydration(): void
    {
        $filename = $this->temporaryDirectory . '/config.secrets.php';
        $name     = 'REGISTER_EXTENSION_ACTIVITYPUB_MASTER_KEY';
        $registry = new DynamicSecretParameterRegistry(['AI_KEY']);
        $registry->registerExtensionPrivate($name);

        $extensionAwareStore = new DynamicSecretStore($filename, $registry);
        $secret              = $extensionAwareStore->getOrCreateExtensionPrivate($name);

        $coreOnlyStore = new DynamicSecretStore($filename, ['AI_KEY']);
        $protected     = $coreOnlyStore->protect(['AI_KEY' => 'core-secret']);

        self::assertSame('core-secret', $coreOnlyStore->hydrate($protected['cache'])['AI_KEY']);
        self::assertSame($secret, (include $filename)[$name] ?? null);
    }

    public function testRequiresRuntimeRegistrationBeforeAccessingExtensionPrivateSecret(): void
    {
        $store = new DynamicSecretStore(
            $this->temporaryDirectory . '/config.secrets.php',
            ['AI_KEY'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $store->getOrCreateExtensionPrivate('REGISTER_EXTENSION_ACTIVITYPUB_MASTER_KEY');
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
