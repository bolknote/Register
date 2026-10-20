<?php

declare(strict_types = 1);

namespace unit\Register\Media;

use Codeception\Test\Unit;
use Register\Media\MediaMutationTracker;
use Symfony\Component\Filesystem\Filesystem;

final class MediaMutationTrackerTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-media-mutations-' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAnActiveMutationRejectsBackupChecksWithoutWaitingAndNestedLeasesReleaseTogether(): void
    {
        $writer = new MediaMutationTracker($this->directory . '/media.lock');
        $reader = new MediaMutationTracker($this->directory . '/media.lock');
        $release = $writer->begin();
        $releaseNested = $writer->begin();
        try {
            $release();
            $release();
            try {
                $reader->revision();
                self::fail('A backup cannot start in the middle of a mutation.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Media files are being changed; retry the backup.', $exception->getMessage());
            }
        } finally {
            $release();
            $releaseNested();
        }

        $reader->assertUnchanged($reader->revision());
    }

    public function testFailedMutationStillInvalidatesAnOverlappingBackupAndReleasesTheLock(): void
    {
        $tracker = new MediaMutationTracker($this->directory . '/media.lock');
        $before = $tracker->revision();
        try {
            $tracker->run(static function (): never {
                throw new \RuntimeException('An edit failed after changing a file.');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('An edit failed after changing a file.', $exception->getMessage());
        }

        self::assertNotSame($before, $tracker->revision());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Media files changed during the backup; retry the backup.');
        $tracker->assertUnchanged($before);
    }
}
