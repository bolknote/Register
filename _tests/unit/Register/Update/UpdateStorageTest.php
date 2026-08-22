<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Update;

use Codeception\Test\Unit;
use Register\Update\UpdateStorage;
use Symfony\Component\Filesystem\Filesystem;

final class UpdateStorageTest extends Unit
{
    private string $temporaryRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/register_update_storage_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryRoot, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryRoot);
    }

    public function testAppendsOrderedChunksAndFindsRecoverableSession(): void
    {
        $storage = new UpdateStorage($this->temporaryRoot . '/updates');
        $state   = $storage->start('register-release.tar.gz', 5);
        $id      = $state['id'] ?? null;
        self::assertIsString($id);

        $firstChunk = $this->temporaryRoot . '/first';
        $lastChunk  = $this->temporaryRoot . '/last';
        file_put_contents($firstChunk, 'ab');
        file_put_contents($lastChunk, 'cde');
        $state = $storage->append($id, 0, $firstChunk);
        self::assertSame(2, $state['received']);
        $state = $storage->append($id, 2, $lastChunk);
        self::assertSame('uploaded', $state['status']);
        self::assertSame('abcde', file_get_contents($storage->archivePath($id)));
        self::assertNull($storage->latestRecoverable());

        $stored = $storage->load($id);
        $stored['status'] = 'ready';
        $storage->save($id, $stored);
        self::assertSame($id, $storage->latestRecoverable()['id'] ?? null);
    }

    public function testRejectsOutOfOrderChunk(): void
    {
        $storage = new UpdateStorage($this->temporaryRoot . '/updates');
        $state   = $storage->start('register-release.zip', 3);
        $id      = $state['id'] ?? null;
        self::assertIsString($id);
        $chunk = $this->temporaryRoot . '/chunk';
        file_put_contents($chunk, 'abc');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('offset');
        $storage->append($id, 1, $chunk);
    }

    public function testResetsStagingLeftByAnInterruptedPrepareRequest(): void
    {
        $storage = new UpdateStorage($this->temporaryRoot . '/updates');
        $state   = $storage->start('register-release.zip', 3);
        $id      = $state['id'] ?? null;
        self::assertIsString($id);
        mkdir($storage->stageRoot($id) . '/app', 0700, true);
        file_put_contents($storage->stageRoot($id) . '/app/partial.php', '<?php');

        $storage->resetStage($id);

        self::assertDirectoryDoesNotExist($storage->stageRoot($id));
        $storage->resetStage($id);
        self::assertDirectoryDoesNotExist($storage->stageRoot($id));
    }

    public function testCleansCompletedPayloadAndPrunesOnlyNonCriticalExpiredSessions(): void
    {
        $storage = new UpdateStorage($this->temporaryRoot . '/updates');

        $completed = $storage->start('completed.zip', 3);
        $completedId = $completed['id'] ?? null;
        self::assertIsString($completedId);
        $chunk = $this->temporaryRoot . '/completed-chunk';
        file_put_contents($chunk, 'abc');
        $storage->append($completedId, 0, $chunk);
        $completedState = $storage->load($completedId);
        $completedState['status'] = 'complete';
        $storage->save($completedId, $completedState);
        mkdir($storage->stageRoot($completedId), 0700, true);
        mkdir($storage->rollbackRoot($completedId), 0700, true);
        file_put_contents($storage->stageRoot($completedId) . '/staged', 'data');
        file_put_contents($storage->rollbackRoot($completedId) . '/saved', 'data');

        $storage->cleanupCompleted($completedId);

        self::assertFileDoesNotExist($this->temporaryRoot . '/updates/' . $completedId . '/completed.zip');
        self::assertDirectoryDoesNotExist($storage->stageRoot($completedId));
        self::assertDirectoryDoesNotExist($storage->rollbackRoot($completedId));
        self::assertSame('complete', $storage->load($completedId)['status']);

        $ready = $storage->start('ready.tar.gz', 1);
        $readyId = $ready['id'] ?? null;
        self::assertIsString($readyId);
        $readyState = $storage->load($readyId);
        $readyState['status'] = 'ready';
        $storage->save($readyId, $readyState);

        $critical = $storage->start('critical.tar.bz2', 1);
        $criticalId = $critical['id'] ?? null;
        self::assertIsString($criticalId);
        $criticalState = $storage->load($criticalId);
        $criticalState['status'] = 'migration_failed';
        $storage->save($criticalId, $criticalState);

        self::assertSame(2, $storage->pruneExpired(time() + UpdateStorage::SESSION_RETENTION_SECONDS + 1));
        self::assertFileDoesNotExist($this->temporaryRoot . '/updates/' . $completedId . '/state.json');
        self::assertFileDoesNotExist($this->temporaryRoot . '/updates/' . $readyId . '/state.json');
        self::assertSame('migration_failed', $storage->load($criticalId)['status']);
    }
}
