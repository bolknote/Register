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

final class ReleaseManifestTest extends Unit
{
    public function testRoundTripKeepsReleaseMetadataAndFiles(): void
    {
        $manifest = $this->manifest(20, 'new contents');
        $restored = ReleaseManifest::fromJson($manifest->toJson());

        self::assertSame($manifest->toArray(), $restored->toArray());
        self::assertSame(hash('sha256', 'new contents'), $restored->filesByKey()['app:file.php']->sha256);
        self::assertSame(12, $restored->totalBytes());
    }

    public function testBuildNumberOrdersReleasesWithinOneChannel(): void
    {
        self::assertTrue($this->manifest(21, 'new')->isNewerThan($this->manifest(20, 'old')));
        self::assertFalse($this->manifest(20, 'old')->isNewerThan($this->manifest(20, 'old')));
    }

    public function testReleaseChannelsCanAdvanceFromEdgeToCandidateToStable(): void
    {
        $edge = $this->manifest(30, 'edge', 'edge');
        $candidate = $this->manifest(10, 'candidate', 'rc');
        $stable = $this->manifest(1, 'stable', 'stable');

        self::assertTrue($candidate->isNewerThan($edge));
        self::assertTrue($stable->isNewerThan($candidate));
        self::assertFalse($edge->isNewerThan($candidate));
        self::assertFalse($candidate->isNewerThan($stable));
    }

    public function testUnknownReleaseChannelIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('release channel is not supported');

        $this->manifest(1, 'nightly', 'nightly');
    }

    public function testReleaseFileRejectsDirectoryTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReleaseFile(ReleaseFile::TARGET_APPLICATION, '../config.php', 1, str_repeat('a', 64));
    }

    public function testManifestRejectsItsOwnReservedArchivePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved manifest path');
        new ReleaseManifest(
            '20260822T000000Z-01234567',
            '2.0.0-edge.20260822.000000',
            '2.0.0',
            'edge',
            20,
            '2026-08-22T00:00:00+00:00',
            str_repeat('a', 40),
            '8.3.0',
            15,
            15,
            [new ReleaseFile(
                ReleaseFile::TARGET_APPLICATION,
                'register-release.json',
                1,
                str_repeat('a', 64),
            )],
        );
    }

    private function manifest(int $build, string $contents, string $channel = 'edge'): ReleaseManifest
    {
        return new ReleaseManifest(
            '20260822T000000Z-01234567-' . $build,
            match ($channel) {
                'rc'     => '2.0.0-rc.' . $build,
                'stable' => '2.0.0',
                default  => '2.0.0-edge.20260822.000000.' . $build,
            },
            '2.0.0',
            $channel,
            $build,
            '2026-08-22T00:00:00+00:00',
            str_repeat('a', 40),
            '8.3.0',
            15,
            15,
            [new ReleaseFile(
                ReleaseFile::TARGET_APPLICATION,
                'file.php',
                \strlen($contents),
                hash('sha256', $contents),
            )],
        );
    }
}
