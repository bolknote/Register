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

    /** @return list<string> */
    private function reservations(): array
    {
        $paths = glob($this->root . '/private/*.json');
        return $paths === false ? [] : $paths;
    }

    public function testReservesAllAttachmentsWithoutLeavingFragmentsFromCompetingComments(): void
    {
        $storage = $this->storage();
        $files = [];
        for ($index = 1; $index <= 6; ++$index) {
            $files[] = ['file_unique_id' => 'file_' . $index, 'file_size' => TelegramMediaUploadStorage::MAX_BYTES];
        }

        $storage->reserveMessage(2, $files);
        $storage->reserveMessage(2, $files); // Retrying the reservation consumes no extra quota.
        try {
            $storage->reserveMessage(3, $files);
            self::fail('A competing comment must reserve all its bytes or none.');
        } catch (\RuntimeException) {
            self::assertCount(6, $this->reservations());
        }

        $chunk = str_repeat('a', TelegramMediaUploadStorage::CHUNK_BYTES);
        for ($offset = 0; $offset < TelegramMediaUploadStorage::MAX_BYTES; $offset += TelegramMediaUploadStorage::CHUNK_BYTES) {
            $storage->append(2, 'file_6', TelegramMediaUploadStorage::MAX_BYTES, $offset,
                substr($chunk, 0, min(\strlen($chunk), TelegramMediaUploadStorage::MAX_BYTES - $offset)));
        }

        self::assertTrue($storage->status(2, 'file_6')['complete']);
        // The maximum supported ten-file comment fits, without downloading any bytes twice.
        for ($index = 7; $index <= 10; ++$index) {
            $files[] = ['file_unique_id' => 'file_' . $index, 'file_size' => TelegramMediaUploadStorage::MAX_BYTES];
        }

        $storage->reserveMessage(2, $files);
        self::assertCount(10, $this->reservations());
    }

    public function testExactMediaIdentityRemainsReadableFromLegacyStorageAndRejectsSymlinks(): void
    {
        $this->storage();
        $directory = $this->root . '/_pictures/bolknote/comments/telegram/123/2';
        mkdir($directory, 0755, true);
        $bytes = (string)file_get_contents(__DIR__ . '/../../../_resources/telegram-media/photo.png');
        $id = substr(hash('sha256', $bytes), 0, 20);
        $file = $directory . '/02-' . $id . '.png';
        file_put_contents($file, $bytes);
        $managed = new TelegramManagedMediaStorage($this->root);
        $stored = $managed->findForMessage(123, 2, $id);
        self::assertNotNull($stored);
        self::assertSame('/_pictures/bolknote/comments/telegram/123/2/02-' . $id . '.png', $stored['url']);
        self::assertNull($managed->findForMessage(123, 2, str_repeat('0', 20)));
        unlink($file);
        symlink(__DIR__ . '/../../../_resources/telegram-media/photo.png', $file);
        self::assertNull($managed->findForMessage(123, 2, $id));
    }

    public function testRepeatedAttachmentIdentityReservesItsBytesOnlyOnce(): void
    {
        $storage = $this->storage();
        $file = ['file_unique_id' => 'repeated', 'file_size' => 3];
        $storage->reserveMessage(2, [$file, $file]);
        self::assertCount(1, $this->reservations());
        self::assertTrue($storage->append(2, 'repeated', 3, 0, 'abc')['complete']);
        self::assertTrue($storage->append(2, 'repeated', 3, 0, 'abc')['complete']);
        self::assertSame('abc', $storage->download(['path' => 'live/2/2-repeated.bin', 'file_unique_id' => 'repeated']));
    }

    public function testConflictingRepeatedAttachmentSizesDoNotLeaveAnyReservations(): void
    {
        $storage = $this->storage();
        try {
            $storage->reserveMessage(2, [
                ['file_unique_id' => 'repeated', 'file_size' => 3],
                ['file_unique_id' => 'repeated', 'file_size' => 4],
            ]);
            self::fail('The same attachment identity must not reserve conflicting sizes.');
        } catch (\UnexpectedValueException) {
            self::assertCount(0, $this->reservations());
            self::assertDirectoryDoesNotExist($this->root . '/private');
        }
    }
}
