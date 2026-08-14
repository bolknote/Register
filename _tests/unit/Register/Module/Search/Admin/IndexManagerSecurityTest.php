<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Admin;

use Codeception\Test\Unit;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Register\Module\Search\Admin\IndexManager;
use S2\Rose\Indexer;
use S2\Rose\Storage\Database\PdoStorage;

final class IndexManagerSecurityTest extends Unit
{
    public function testBufferDoesNotInstantiateUnexpectedSerializedClasses(): void
    {
        $cacheDir = \sys_get_temp_dir() . '/s2_index_security_' . \bin2hex(\random_bytes(8)) . '/';
        self::assertTrue(mkdir($cacheDir, 0700));

        $wakeupMarker = $cacheDir . 'unexpected-wakeup.txt';
        UnexpectedSerializedPayload::$wakeupMarker = $wakeupMarker;
        self::assertNotFalse(file_put_contents($cacheDir . 's2_search_state.txt', 'step'));
        self::assertNotFalse(file_put_contents($cacheDir . 's2_search_pointer.txt', '0'));
        self::assertNotFalse(file_put_contents(
            $cacheDir . 's2_search_buffer.txt',
            base64_encode(serialize(new UnexpectedSerializedPayload())) . "\n",
        ));

        $indexer = self::createMock(Indexer::class);
        $indexer->expects(self::never())->method('index');

        try {
            $manager = new IndexManager(
                $cacheDir,
                $indexer,
                self::createStub(PdoStorage::class),
                self::createStub(CacheItemPoolInterface::class),
                new NullLogger(),
            );

            self::assertSame('stop', $manager->index());
            self::assertFileDoesNotExist($wakeupMarker);
        } finally {
            foreach (['s2_search_state.txt', 's2_search_pointer.txt', 's2_search_buffer.txt', 'unexpected-wakeup.txt'] as $filename) {
                @unlink($cacheDir . $filename);
            }

            @rmdir($cacheDir);
        }
    }
}

final class UnexpectedSerializedPayload
{
    public static string $wakeupMarker = '';

    public function __wakeup(): void
    {
        file_put_contents(self::$wakeupMarker, 'unexpected wakeup');
    }
}
