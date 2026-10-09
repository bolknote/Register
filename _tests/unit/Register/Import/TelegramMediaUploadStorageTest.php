<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Import;

use Codeception\Test\Unit;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Import\ExternalImportMapRepository;
use Register\Import\ExternalImportMapSchema;
use Register\Import\Telegram\TelegramManagedMediaStorage;
use Register\Import\Telegram\TelegramMediaUploadStorage;
use Register\Import\Telegram\TelegramMediaDownloadFailed;

final class TelegramMediaUploadStorageTest extends Unit
{
    private string $root = '';

    #[\Override]
    protected function _after(): void
    {
        if ($this->root !== '') {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($this->root);
        }
    }

    private function storage(): TelegramMediaUploadStorage
    {
        $this->root = sys_get_temp_dir() . '/register-media-upload-' . bin2hex(random_bytes(6));
        $db = new DbLayerSqlite(new \PDO('sqlite::memory:'));
        ExternalImportMapSchema::create($db);
        return new TelegramMediaUploadStorage($this->root . '/private', 123, new ExternalImportMapRepository($db), new TelegramManagedMediaStorage($this->root));
    }

    public function testResumesChunksAndHandlesDuplicateAcknowledgementsWithoutAppendingTwice(): void
    {
        $storage = $this->storage();
        $chunk = str_repeat('a', TelegramMediaUploadStorage::CHUNK_BYTES);
        $total = \strlen($chunk) + 3;
        self::assertFalse($storage->status(2, 'file_1')['complete']);
        self::assertSame(\strlen($chunk), $storage->append(2, 'file_1', $total, 0, $chunk)['received']);
        self::assertSame(\strlen($chunk), $storage->append(2, 'file_1', $total, 0, $chunk)['received']);
        self::assertTrue($storage->append(2, 'file_1', $total, \strlen($chunk), 'end')['complete']);
        self::assertSame($chunk . 'end', $storage->download(['path' => 'live/2/1-file_1.bin', 'file_unique_id' => 'file_1']));
        try {
            $storage->append(2, 'file_1', $total, \strlen($chunk), 'BAD');
            self::fail('An acknowledged file must not change its bytes.');
        } catch (\UnexpectedValueException) {
            self::assertSame($chunk . 'end', $storage->download(['path' => 'live/2/1-file_1.bin', 'file_unique_id' => 'file_1']));
        }
    }

    public function testIncompleteUploadsCannotBecomeCommentsAndKilledChunksCanBeReplaced(): void
    {
        $storage = $this->storage();
        $chunk = str_repeat('a', TelegramMediaUploadStorage::CHUNK_BYTES);
        $total = \strlen($chunk) + 100;
        $storage->append(2, 'file_1', $total, 0, $chunk);
        try {
            $storage->download(['path' => 'live/2/1-file_1.bin', 'file_unique_id' => 'file_1']);
            self::fail('Incomplete bytes must remain retryable.');
        } catch (TelegramMediaDownloadFailed) {
            self::assertFalse($storage->status(2, 'file_1')['complete']);
        }

        $paths = glob($this->root . '/private/*.part');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        file_put_contents($paths[0], 'half a chunk', FILE_APPEND);
        self::assertSame(\strlen($chunk), $storage->status(2, 'file_1')['received']);
        self::assertTrue($storage->append(2, 'file_1', $total, \strlen($chunk), str_repeat('b', 100))['complete']);
    }
}
