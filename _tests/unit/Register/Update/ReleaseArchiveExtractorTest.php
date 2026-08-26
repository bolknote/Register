<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Update;

use Codeception\Test\Unit;
use Register\Tools\Deployment\ReleaseArchiveBuilder;
use Register\Update\ArchiveCapabilities;
use Register\Update\ReleaseArchiveExtractor;
use Register\Update\ReleaseFile;
use Register\Update\ReleaseManifest;
use Symfony\Component\Filesystem\Filesystem;

require_once \dirname(__DIR__, 4) . '/tools/deployment/ReleaseArchiveBuilder.php';

final class ReleaseArchiveExtractorTest extends Unit
{
    private string $temporaryRoot = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/register_release_extract_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryRoot . '/distribution/public_html', 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryRoot);
    }

    public function testExtractsAndVerifiesEverySupportedArchiveFormat(): void
    {
        $manifest = $this->writeDistribution();
        file_put_contents($this->temporaryRoot . '/distribution/DEPLOYMENT.md', 'not part of a self-update');
        file_put_contents($this->temporaryRoot . '/distribution/public_html/unmanaged.txt', 'not managed');
        $builder  = new ReleaseArchiveBuilder();
        $archives = [
            ['release.zip', ArchiveCapabilities::FORMAT_ZIP, $builder->createZip(...)],
            ['release.tar.gz', ArchiveCapabilities::FORMAT_TAR_GZIP, $builder->createTarGzip(...)],
            ['release.tar.bz2', ArchiveCapabilities::FORMAT_TAR_BZIP2, $builder->createTarBzip2(...)],
        ];
        $capabilities = new ArchiveCapabilities();
        $extractor    = new ReleaseArchiveExtractor($capabilities);
        $index        = 0;

        foreach ($archives as [$name, $format, $create]) {
            if (!$capabilities->formats()[$format]['available']) {
                continue;
            }

            $archive = $this->temporaryRoot . '/' . $name;
            $create($this->temporaryRoot . '/distribution', $archive, 1_700_000_000);
            $readManifest = $extractor->manifest($archive);
            self::assertSame($manifest->toArray(), $readManifest->toArray());

            $stage = $this->temporaryRoot . '/stage-' . $index++;
            $extractor->extract($archive, $stage, $readManifest);
            self::assertSame('<?php echo "ok";', file_get_contents($stage . '/root/file.php'));
            self::assertSame('body{}', file_get_contents($stage . '/root/site.css'));
        }

        self::assertGreaterThan(0, $index);
    }

    public function testRejectsAnArchiveFileThatIsNotListedInTheManifest(): void
    {
        if (!(new ArchiveCapabilities())->formats()[ArchiveCapabilities::FORMAT_ZIP]['available']) {
            self::markTestSkipped('The Zip extension is unavailable.');
        }

        $this->writeDistribution();
        $archive = $this->temporaryRoot . '/release.zip';
        (new ReleaseArchiveBuilder())->createZip(
            $this->temporaryRoot . '/distribution',
            $archive,
            1_700_000_000,
        );

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        self::assertTrue($zip->addFromString('public_html/unlisted.php', '<?php'));
        self::assertTrue($zip->close());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not listed in its manifest');
        (new ReleaseArchiveExtractor(new ArchiveCapabilities()))->manifest($archive);
    }

    public function testBuilderRejectsAFileThatChangedAfterManifestCreation(): void
    {
        $this->writeDistribution();
        file_put_contents($this->temporaryRoot . '/distribution/public_html/file.php', 'changed');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('differs from its manifest');
        (new ReleaseArchiveBuilder())->createZip(
            $this->temporaryRoot . '/distribution',
            $this->temporaryRoot . '/release.zip',
            1_700_000_000,
        );
    }

    private function writeDistribution(): ReleaseManifest
    {
        $application = '<?php echo "ok";';
        $public      = 'body{}';
        file_put_contents($this->temporaryRoot . '/distribution/public_html/file.php', $application);
        file_put_contents($this->temporaryRoot . '/distribution/public_html/site.css', $public);
        $manifest = new ReleaseManifest(
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
            [
                new ReleaseFile('root', 'file.php', \strlen($application), hash('sha256', $application)),
                new ReleaseFile('root', 'site.css', \strlen($public), hash('sha256', $public)),
            ],
        );
        file_put_contents(
            $this->temporaryRoot . '/distribution/' . ReleaseManifest::ARCHIVE_PATH,
            $manifest->toJson(),
        );

        return $manifest;
    }
}
