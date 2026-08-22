<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Update;

use Codeception\Test\Unit;
use Register\Update\BuildInfo;
use Symfony\Component\Filesystem\Filesystem;

final class BuildInfoTest extends Unit
{
    private string $temporaryRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/register_build_info_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryRoot, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryRoot);
    }

    public function testReadsTheSmallReleaseVersionMarker(): void
    {
        $version = '2.0.0-edge.20260822.150000';
        file_put_contents(
            $this->temporaryRoot . '/' . BuildInfo::FILENAME,
            BuildInfo::toJson(
                '20260822T150000Z-01234567',
                $version,
                '2026-08-22T15:00:00+00:00',
                str_repeat('a', 40),
            ),
        );

        self::assertSame($version, BuildInfo::version($this->temporaryRoot));
    }

    public function testUsesDevelopmentVersionForMissingOrInvalidMetadata(): void
    {
        self::assertSame(BuildInfo::DEVELOPMENT_VERSION, BuildInfo::version($this->temporaryRoot));
        file_put_contents($this->temporaryRoot . '/' . BuildInfo::FILENAME, '{invalid');
        self::assertSame(BuildInfo::DEVELOPMENT_VERSION, BuildInfo::version($this->temporaryRoot));
    }
}
