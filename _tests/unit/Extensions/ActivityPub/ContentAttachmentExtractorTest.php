<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use PHPUnit\Framework\TestCase;
use Register\Core\HttpClient\HttpClient;
use Register\Extension\activitypub\Content\ContentAttachmentExtractor;

final class ContentAttachmentExtractorTest extends TestCase
{
    private string $directory = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/register-ap-image-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        if (!\is_string($image)) {
            throw new \LogicException('Invalid embedded PNG fixture.');
        }

        file_put_contents($this->directory . '/pixel.png', $image);
    }

    #[\Override]
    protected function tearDown(): void
    {
        unlink($this->directory . '/pixel.png');
        rmdir($this->directory);
    }

    public function testPreservesLegacyAndVersionedPublicUrlsForTheSameLocalFile(): void
    {
        foreach (['/blog/_pictures', 'https://cdn.example/media'] as $prefix) {
            $extractor = new ContentAttachmentExtractor(new HttpClient(), $this->directory, $prefix);
            foreach (['', '?media=85'] as $query) {
                $source = $prefix . '/pixel.png' . $query;
                $attachments = $extractor->extract('<img src="' . $source . '" alt="Tiny pixel">', 'https://example.test/blog/post');
                self::assertCount(1, $attachments);
                self::assertSame((str_starts_with($prefix, 'https://') ? '' : 'https://example.test') . $source, $attachments[0]['url']);
                self::assertSame('image/png', $attachments[0]['mediaType']);
                self::assertSame(1, $attachments[0]['width']);
                self::assertSame(1, $attachments[0]['height']);
            }
        }
    }

    public function testVersionQueriesCannotBypassAttachmentStorageBoundaries(): void
    {
        $extractor = new ContentAttachmentExtractor(new HttpClient(), $this->directory, '/blog/_pictures');
        foreach ([
            'https://attacker.example/blog/_pictures/pixel.png?media=85',
            '/blog/_pictures-other/pixel.png?media=85',
            '/blog/_pictures/%2e%2e/pixel.png?media=85',
            '/blog/_pictures/folder%2f..%2fpixel.png?media=85',
            '/blog/_pictures/pixel.png?media=0',
            '/blog/_pictures/pixel.png?download=1',
            '/blog/_pictures/pixel.png?media=85&media=86',
            '/blog/_pictures/pixel.png?media=85&download=1',
        ] as $source) {
            self::assertSame([], $extractor->extract('<img src="' . htmlspecialchars($source, ENT_QUOTES, 'UTF-8') . '">', 'https://example.test/blog/post'), $source);
        }
    }
}
