<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Security;

use Codeception\Test\Unit;
use Register\Backup\PortableZipWriter;
use Register\Url\ContentUrlAliasRepository;
use Register\AdminYard\Translator;
use Register\Admin\Picture\PictureFileNameHelper;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpClientException;

/** Exercises security invariants with deterministic generated inputs. */
final class SecurityBoundaryPropertyTest extends Unit
{
    private const int CASES = 128;

    /** @var list<string> */
    private array $temporaryFiles = [];

    #[\Override]
    protected function _after(): void
    {
        foreach ($this->temporaryFiles as $filename) {
            if (is_file($filename)) {
                unlink($filename);
            }
        }
    }

    public function testUrlAliasNormalizationRejectsGeneratedReservedAndMalformedPaths(): void
    {
        for ($case = 0; $case < self::CASES; ++$case) {
            $token = $this->token('alias', $case);
            $utf8Segment = 'тест-' . $case;
            $encodedPath = '/archive/' . rawurlencode($utf8Segment) . '/' . $token . '/';
            $expected    = 'archive/' . $utf8Segment . '/' . $token;

            self::assertSame($expected, ContentUrlAliasRepository::normalizePath($encodedPath));
            self::assertSame($expected, ContentUrlAliasRepository::normalizePath($expected));

            $unsafePaths = [
                '/' . $token . '/%2e%2e/escape',
                '/' . $token . '/%2E/escape',
                '/' . $token . '%2f%2fescape',
                '/' . $token . '%5cescape',
                '/' . $token . '%3fnext=escape',
                '/' . $token . '%23fragment',
                '/' . $token . '%00escape',
                '/' . $token . '%0aescape',
                '/' . $token . "%ffescape",
            ];

            foreach ($unsafePaths as $unsafePath) {
                $this->assertThrows(
                    \InvalidArgumentException::class,
                    static fn(): string => ContentUrlAliasRepository::normalizePath($unsafePath),
                    $unsafePath,
                );
            }
        }
    }

    public function testHttpClientRejectsGeneratedRawControlCharactersAndNormalizesRedirects(): void
    {
        $client    = new HttpClient();
        $normalize = new \ReflectionMethod(HttpClient::class, 'normalizeUrl');

        foreach ([...range(0, 32), 127] as $codePoint) {
            $unsafeUrl = 'https://example.test/safe' . chr($codePoint) . '/tail';
            $this->assertThrows(
                HttpClientException::class,
                static fn(): mixed => $normalize->invoke($client, $unsafeUrl),
                'raw control character ' . $codePoint,
            );
        }

        for ($case = 0; $case < self::CASES; ++$case) {
            $currentSegment = $this->token('redirect-current', $case);
            $targetSegment  = $this->token('redirect-target', $case);
            $resolved       = $client->resolveRedirectUrl(
                '../' . $targetSegment . '?case=' . $case,
                'https://example.test/root/' . $currentSegment . '/start?stale=1',
            );

            self::assertSame(
                'https://example.test/root/' . $targetSegment . '?case=' . $case,
                $resolved,
            );
            self::assertStringNotContainsString('/../', $resolved);
            self::assertStringNotContainsString('stale=1', $resolved);
        }
    }

    public function testUploadAllowlistRejectsGeneratedPathAndActiveExtensionMutations(): void
    {
        $helper = new PictureFileNameHelper(new Translator([], 'en'), 'jpg png');

        self::assertSame('photo.jpg', $helper->normalizeFileName('C:\\Users\\Alice\\Photo.JPG'));

        for ($case = 0; $case < self::CASES; ++$case) {
            $token = $this->token('upload', $case);
            self::assertTrue($helper->isAllowedExtension($token . ($case % 2 === 0 ? '.jpg' : '.PNG')));

            $activeExtension = $case % 2 === 0 ? 'PHP' : 'pHtMl';
            $unsafeNames = [
                '../' . $token . '.jpg',
                '..\\' . $token . '.jpg',
                'folder/' . $token . '.jpg',
                'folder\\' . $token . '.jpg',
                $token . "\0.jpg",
                $token . "\n.jpg",
                $token . '..jpg',
                $token . '.' . $activeExtension . '.jpg',
                $token . "\xff.jpg",
            ];

            foreach ($unsafeNames as $unsafeName) {
                self::assertFalse($helper->isAllowedExtension($unsafeName), bin2hex($unsafeName));
            }
        }
    }

    public function testPortableZipRejectsGeneratedTraversalAndMalformedEntryNames(): void
    {
        $archive = sys_get_temp_dir() . '/register_zip_property_' . bin2hex(random_bytes(8)) . '.zip';
        $this->temporaryFiles[] = $archive;
        $writer = new PortableZipWriter($archive);

        for ($case = 0; $case < self::CASES; ++$case) {
            $token = $this->token('archive', $case);
            $unsafeNames = [
                '../' . $token . '.txt',
                'safe/../../' . $token . '.txt',
                'safe\\..\\' . $token . '.txt',
                '/' . $token . '.txt',
                'C:/' . $token . '.txt',
                'safe//' . $token . '.txt',
                'safe/./' . $token . '.txt',
                'safe/' . $token . '/',
                'safe/' . $token . "\0.txt",
                'safe/' . $token . "\n.txt",
                'safe/' . $token . "\xff.txt",
            ];

            foreach ($unsafeNames as $unsafeName) {
                $this->assertThrows(
                    \InvalidArgumentException::class,
                    static fn(): int => $writer->addString($unsafeName, 'blocked', 1_700_000_000),
                    bin2hex($unsafeName),
                );
            }

            $safeName = 'media/' . $token . '.txt';
            self::assertSame(7, $writer->addString($safeName, 'content', 1_700_000_000));
        }

        $writer->close();
        $contents = file_get_contents($archive);
        self::assertIsString($contents);
        self::assertStringStartsWith("PK\x03\x04", $contents);
        self::assertSame("PK\x05\x06", substr($contents, -22, 4));
        self::assertSame("\0\0", substr($contents, -2));
    }

    /**
     * @param class-string<\Throwable> $expectedClass
     * @param callable(): mixed $callback
     */
    private function assertThrows(string $expectedClass, callable $callback, string $context): void
    {
        try {
            $callback();
        } catch (\Throwable $throwable) {
            self::assertInstanceOf($expectedClass, $throwable, $context);
            return;
        }

        self::fail('Expected ' . $expectedClass . ' for ' . $context . '.');
    }

    private function token(string $namespace, int $case): string
    {
        return substr(hash('sha256', $namespace . ':' . $case), 0, 24);
    }
}
