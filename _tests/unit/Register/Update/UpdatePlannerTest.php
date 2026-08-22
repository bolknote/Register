<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Update;

use Codeception\Test\Unit;
use Register\Update\ReleaseFile;
use Register\Update\ReleaseManifest;
use Register\Update\UpdateApplier;
use Register\Update\UpdatePlanner;
use Symfony\Component\Filesystem\Filesystem;

final class UpdatePlannerTest extends Unit
{
    private string $temporaryRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/register_update_plan_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryRoot . '/app', 0700, true);
        mkdir($this->temporaryRoot . '/public', 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryRoot);
    }

    public function testPlansSwitchAndCanRestoreItFromRollbackJournal(): void
    {
        $oldFiles = [
            $this->file('app', 'stable.txt', 'stable'),
            $this->file('app', 'update.txt', 'old'),
            $this->file('public', 'remove.css', 'remove'),
        ];
        $newFiles = [
            $this->file('app', 'stable.txt', 'stable'),
            $this->file('app', 'update.txt', 'new'),
            $this->file('public', 'add.css', 'add'),
        ];
        $installed = $this->manifest(20, $oldFiles);
        $incoming  = $this->manifest(21, $newFiles);
        $this->writeLive('app', 'stable.txt', 'stable');
        $this->writeLive('app', 'update.txt', 'old');
        $this->writeLive('public', 'remove.css', 'remove');
        file_put_contents($this->temporaryRoot . '/app/register-release.json', $installed->toJson());

        $planner = new UpdatePlanner($this->temporaryRoot . '/app', $this->temporaryRoot . '/public');
        $plan    = $planner->plan($installed, $incoming, 15);
        self::assertSame(['app:update.txt', 'public:add.css'], $plan->writes);
        self::assertSame(['public:remove.css'], $plan->deletes);
        self::assertSame(['app:stable.txt'], $plan->unchanged);
        self::assertSame([], $plan->conflicts);

        $stage = $this->temporaryRoot . '/stage';
        mkdir($stage . '/app', 0700, true);
        mkdir($stage . '/public', 0700, true);
        file_put_contents($stage . '/app/update.txt', 'new');
        file_put_contents($stage . '/public/add.css', 'add');
        file_put_contents($stage . '/app/register-release.json', $incoming->toJson());
        $rollback = $this->temporaryRoot . '/rollback';
        $applier  = new UpdateApplier($this->temporaryRoot . '/app', $this->temporaryRoot . '/public');
        $applier->apply($stage, $rollback, $installed, $incoming, $plan);

        self::assertSame('new', file_get_contents($this->temporaryRoot . '/app/update.txt'));
        self::assertSame('add', file_get_contents($this->temporaryRoot . '/public/add.css'));
        self::assertFileDoesNotExist($this->temporaryRoot . '/public/remove.css');
        self::assertSame($incoming->toArray(), ReleaseManifest::fromFile(
            $this->temporaryRoot . '/app/register-release.json',
        )->toArray());
        self::assertFileExists($rollback . '/journal.json');
        $temporaryJournals = glob($rollback . '/.journal-*');
        self::assertIsArray($temporaryJournals);
        self::assertSame([], $temporaryJournals);
        $interruptedCopy = $this->temporaryRoot . '/app/.register-update-' . str_repeat('a', 16);
        $unrelatedDotfile = $this->temporaryRoot . '/app/.register-update-not-owned';
        file_put_contents($interruptedCopy, 'incomplete atomic copy');
        file_put_contents($unrelatedDotfile, 'unrelated');

        $applier->rollbackInterrupted($rollback);
        self::assertFileDoesNotExist($interruptedCopy);
        self::assertFileExists($unrelatedDotfile);
        self::assertSame('old', file_get_contents($this->temporaryRoot . '/app/update.txt'));
        self::assertFileDoesNotExist($this->temporaryRoot . '/public/add.css');
        self::assertSame('remove', file_get_contents($this->temporaryRoot . '/public/remove.css'));
        self::assertSame($installed->toArray(), ReleaseManifest::fromFile(
            $this->temporaryRoot . '/app/register-release.json',
        )->toArray());

        $applier->rollbackInterrupted($rollback);
        mkdir($rollback, 0700, true);
        file_put_contents($rollback . '/incomplete-backup', 'not used yet');
        $applier->rollbackInterrupted($rollback);
        self::assertDirectoryDoesNotExist($rollback);
    }

    public function testReportsLocallyModifiedManagedFileAsConflict(): void
    {
        $installed = $this->manifest(20, [$this->file('app', 'file.php', 'old')]);
        $incoming  = $this->manifest(21, [$this->file('app', 'file.php', 'new')]);
        $this->writeLive('app', 'file.php', 'local edit');

        $plan = (new UpdatePlanner(
            $this->temporaryRoot . '/app',
            $this->temporaryRoot . '/public',
        ))->plan($installed, $incoming, 15);

        self::assertFalse($plan->canApply());
        self::assertCount(1, $plan->conflicts);
        self::assertStringContainsString('app:file.php', $plan->conflicts[0]);
    }

    public function testRestoresMissingManagedFile(): void
    {
        $file      = $this->file('app', 'file.php', 'release contents');
        $installed = $this->manifest(20, [$file]);
        $incoming  = $this->manifest(21, [$file]);

        $plan = (new UpdatePlanner(
            $this->temporaryRoot . '/app',
            $this->temporaryRoot . '/public',
        ))->plan($installed, $incoming, 15);

        self::assertTrue($plan->canApply());
        self::assertSame(['app:file.php'], $plan->writes);
        self::assertSame([], $plan->conflicts);
    }

    public function testRepairsAManagedFileWithTheWrongMode(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows does not expose portable Unix file modes.');
        }

        $file      = $this->file('app', 'file.php', 'release contents');
        $installed = $this->manifest(20, [$file]);
        $incoming  = $this->manifest(21, [$file]);
        $this->writeLive('app', 'file.php', 'release contents');
        chmod($this->temporaryRoot . '/app/file.php', 0755);

        $plan = (new UpdatePlanner(
            $this->temporaryRoot . '/app',
            $this->temporaryRoot . '/public',
        ))->plan($installed, $incoming, 15);

        self::assertTrue($plan->canApply());
        self::assertSame(['app:file.php'], $plan->writes);
        self::assertSame([], $plan->unchanged);
    }

    private function file(string $target, string $path, string $contents): ReleaseFile
    {
        return new ReleaseFile($target, $path, \strlen($contents), hash('sha256', $contents));
    }

    /** @param list<ReleaseFile> $files */
    private function manifest(int $build, array $files): ReleaseManifest
    {
        return new ReleaseManifest(
            '20260822T000000Z-01234567-' . $build,
            '2.0.0-edge.20260822.000000.' . $build,
            '2.0.0',
            'edge',
            $build,
            '2026-08-22T00:00:00+00:00',
            str_repeat('a', 40),
            '8.3.0',
            15,
            15,
            $files,
        );
    }

    private function writeLive(string $target, string $path, string $contents): void
    {
        file_put_contents($this->temporaryRoot . '/' . $target . '/' . $path, $contents);
    }
}
