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

    private string $siteRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/register_update_plan_' . bin2hex(random_bytes(6));
        $this->siteRoot      = $this->temporaryRoot . '/site';
        mkdir($this->siteRoot, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryRoot);
    }

    public function testPlansSwitchAndCanRestoreItFromRollbackJournal(): void
    {
        $oldFiles = [
            $this->file('stable.txt', 'stable'),
            $this->file('update.txt', 'old'),
            $this->file('remove.css', 'remove'),
        ];
        $newFiles = [
            $this->file('stable.txt', 'stable'),
            $this->file('update.txt', 'new'),
            $this->file('add.css', 'add'),
        ];
        $installed = $this->manifest(20, $oldFiles);
        $incoming  = $this->manifest(21, $newFiles);
        $this->writeLive('stable.txt', 'stable');
        $this->writeLive('update.txt', 'old');
        $this->writeLive('remove.css', 'remove');
        file_put_contents($this->siteRoot . '/register-release.json', $installed->toJson());

        $planner = new UpdatePlanner($this->siteRoot, $this->siteRoot);
        $plan    = $planner->plan($installed, $incoming, 15);
        self::assertSame(['root:add.css', 'root:update.txt'], $plan->writes);
        self::assertSame(['root:remove.css'], $plan->deletes);
        self::assertSame(['root:stable.txt'], $plan->unchanged);
        self::assertSame([], $plan->conflicts);

        $stage = $this->temporaryRoot . '/stage';
        mkdir($stage . '/root', 0700, true);
        file_put_contents($stage . '/root/update.txt', 'new');
        file_put_contents($stage . '/root/add.css', 'add');
        file_put_contents($stage . '/root/register-release.json', $incoming->toJson());
        $rollback = $this->temporaryRoot . '/rollback';
        $applier  = new UpdateApplier($this->siteRoot, $this->siteRoot);
        $applier->apply($stage, $rollback, $installed, $incoming, $plan);

        self::assertSame('new', file_get_contents($this->siteRoot . '/update.txt'));
        self::assertSame('add', file_get_contents($this->siteRoot . '/add.css'));
        self::assertFileDoesNotExist($this->siteRoot . '/remove.css');
        self::assertSame($incoming->toArray(), ReleaseManifest::fromFile(
            $this->siteRoot . '/register-release.json',
        )->toArray());
        self::assertFileExists($rollback . '/journal.json');
        $temporaryJournals = glob($rollback . '/.journal-*');
        self::assertIsArray($temporaryJournals);
        self::assertSame([], $temporaryJournals);
        $interruptedCopy = $this->siteRoot . '/.register-update-' . str_repeat('a', 16);
        $unrelatedDotfile = $this->siteRoot . '/.register-update-not-owned';
        file_put_contents($interruptedCopy, 'incomplete atomic copy');
        file_put_contents($unrelatedDotfile, 'unrelated');

        $applier->rollbackInterrupted($rollback);
        self::assertFileDoesNotExist($interruptedCopy);
        self::assertFileExists($unrelatedDotfile);
        self::assertSame('old', file_get_contents($this->siteRoot . '/update.txt'));
        self::assertFileDoesNotExist($this->siteRoot . '/add.css');
        self::assertSame('remove', file_get_contents($this->siteRoot . '/remove.css'));
        self::assertSame($installed->toArray(), ReleaseManifest::fromFile(
            $this->siteRoot . '/register-release.json',
        )->toArray());

        $applier->rollbackInterrupted($rollback);
        mkdir($rollback, 0700, true);
        file_put_contents($rollback . '/incomplete-backup', 'not used yet');
        $applier->rollbackInterrupted($rollback);
        self::assertDirectoryDoesNotExist($rollback);
    }

    public function testReportsLocallyModifiedManagedFileAsConflict(): void
    {
        $installed = $this->manifest(20, [$this->file('file.php', 'old')]);
        $incoming  = $this->manifest(21, [$this->file('file.php', 'new')]);
        $this->writeLive('file.php', 'local edit');

        $plan = (new UpdatePlanner($this->siteRoot, $this->siteRoot))->plan($installed, $incoming, 15);

        self::assertFalse($plan->canApply());
        self::assertCount(1, $plan->conflicts);
        self::assertStringContainsString('root:file.php', $plan->conflicts[0]);
    }

    public function testRestoresMissingManagedFile(): void
    {
        $file      = $this->file('file.php', 'release contents');
        $installed = $this->manifest(20, [$file]);
        $incoming  = $this->manifest(21, [$file]);

        $plan = (new UpdatePlanner($this->siteRoot, $this->siteRoot))->plan($installed, $incoming, 15);

        self::assertTrue($plan->canApply());
        self::assertSame(['root:file.php'], $plan->writes);
        self::assertSame([], $plan->conflicts);
    }

    public function testRepairsAManagedFileWithTheWrongMode(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows does not expose portable Unix file modes.');
        }

        $file      = $this->file('file.php', 'release contents');
        $installed = $this->manifest(20, [$file]);
        $incoming  = $this->manifest(21, [$file]);
        $this->writeLive('file.php', 'release contents');
        chmod($this->siteRoot . '/file.php', 0755);

        $plan = (new UpdatePlanner($this->siteRoot, $this->siteRoot))->plan($installed, $incoming, 15);

        self::assertTrue($plan->canApply());
        self::assertSame(['root:file.php'], $plan->writes);
        self::assertSame([], $plan->unchanged);
    }

    public function testRejectsTheObsoleteSplitRootLayout(): void
    {
        $otherRoot = $this->temporaryRoot . '/public';
        mkdir($otherRoot, 0700, true);
        $installed = $this->manifest(20, [$this->file('file.php', 'old')]);
        $incoming  = $this->manifest(21, [$this->file('file.php', 'new')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('single-root shared-hosting layout');
        (new UpdatePlanner($this->siteRoot, $otherRoot))->plan($installed, $incoming, 15);
    }

    private function file(string $path, string $contents): ReleaseFile
    {
        return new ReleaseFile(ReleaseFile::TARGET_ROOT, $path, \strlen($contents), hash('sha256', $contents));
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

    private function writeLive(string $path, string $contents): void
    {
        file_put_contents($this->siteRoot . '/' . $path, $contents);
    }
}
