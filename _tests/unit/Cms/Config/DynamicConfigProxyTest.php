<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Config;

use Codeception\Test\Unit;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

final class DynamicConfigProxyTest extends Unit
{
    /**
     * @throws DbLayerException
     */
    public function testProxiesReuseInstancesAndFollowUpdates(): void
    {
        [$provider, $dbLayer] = $this->createProvider([
            'S2_FEATURE' => '1',
            'S2_LIMIT'   => '15',
        ]);

        $boolProxy = $provider->getBoolProxy('S2_FEATURE');
        $intProxy  = $provider->getIntProxy('S2_LIMIT');

        self::assertSame($boolProxy, $provider->getBoolProxy('S2_FEATURE'));
        self::assertTrue($boolProxy->get());
        self::assertSame(15, $intProxy->get());

        $this->updateConfig($dbLayer, [
            'S2_FEATURE' => '0',
            'S2_LIMIT'   => '8',
        ]);
        $provider->clearState();

        self::assertFalse($boolProxy->get());
        self::assertSame(8, $intProxy->get());
    }

    /**
     * @throws DbLayerException
     * @throws \ReflectionException
     */
    public function testStringProxyValidatesType(): void
    {
        [$provider] = $this->createProvider(['S2_TITLE' => 'Hello']);

        self::assertSame('Hello', $provider->getStringProxy('S2_TITLE')->get());

        $reflection = new \ReflectionClass($provider);
        $paramsProp = $reflection->getProperty('params');
        $paramsProp->setValue($provider, ['S2_TITLE' => 123]);

        $this->expectException(\LogicException::class);
        $provider->getStringProxy('S2_TITLE')->get();
    }

    /**
     * @throws DbLayerException
     */
    public function testMissingParamThrowsException(): void
    {
        [$provider] = $this->createProvider([]);

        $this->expectException(\LogicException::class);
        $provider->getBoolProxy('S2_UNKNOWN')->get();
    }

    /**
     * @return array{0:DynamicConfigProvider, 1:DbLayer}
     * @throws DbLayerException
     * @param array<string, string> $data
     */
    private function createProvider(array $data): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dbLayer = new DbLayer($pdo);
        $dbLayer->query('CREATE TABLE config (name TEXT PRIMARY KEY, value TEXT)');
        $this->updateConfig($dbLayer, $data);

        $file = \tempnam(\sys_get_temp_dir(), 's2_dyn_cfg_');
        if ($file === false) {
            throw new \RuntimeException('Unable to allocate a temporary configuration file.');
        }

        \unlink($file);

        return [new DynamicConfigProvider($dbLayer, $file, true), $dbLayer];
    }

    /**
     * @param array<string, string> $data
     */
    private function updateConfig(DbLayer $dbLayer, array $data): void
    {
        foreach ($data as $name => $value) {
            $dbLayer->query(
                'INSERT OR REPLACE INTO config (name, value) VALUES (:name, :value)',
                [':name' => $name, ':value' => $value]
            );
        }
    }
}
