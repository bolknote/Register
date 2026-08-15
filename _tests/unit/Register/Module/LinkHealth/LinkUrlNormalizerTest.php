<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\LinkKind;
use Register\Module\LinkHealth\LinkUrlNormalizer;

final class LinkUrlNormalizerTest extends Unit
{
    public function testNormalizesExternalReachabilityKeyWithoutDroppingQuery(): void
    {
        $link = $this->normalizer()->normalize(
            'HTTP://Example.ORG:80/articles/item?b=2&amp;a=1#section',
            '/current',
        );

        self::assertNotNull($link);
        self::assertSame(LinkKind::EXTERNAL, $link->kind);
        self::assertSame('http://example.org/articles/item?b=2&a=1', $link->url);
        self::assertSame('section', $link->fragment);
    }

    public function testClassifiesSameSiteAndRelativeLinksAsLocal(): void
    {
        $absolute = $this->normalizer()->normalize('https://example.test/page#details', '/current');
        $oldScheme = $this->normalizer()->normalize('http://example.test/old-http-link', '/current');
        $alternatePort = $this->normalizer()->normalize('http://example.test:80/other-service', '/current');
        $relative = $this->normalizer()->normalize('../other', '/section/current');

        self::assertNotNull($absolute);
        self::assertSame(LinkKind::LOCAL, $absolute->kind);
        self::assertSame('/page', $absolute->url);
        self::assertSame('details', $absolute->fragment);

        self::assertNotNull($oldScheme);
        self::assertSame(LinkKind::LOCAL, $oldScheme->kind);
        self::assertSame('/old-http-link', $oldScheme->url);

        self::assertNotNull($alternatePort);
        self::assertSame(LinkKind::EXTERNAL, $alternatePort->kind);
        self::assertSame('http://example.test/other-service', $alternatePort->url);

        self::assertNotNull($relative);
        self::assertSame(LinkKind::LOCAL, $relative->kind);
        self::assertSame('/other', $relative->url);
    }

    public function testHonorsInstallationBasePath(): void
    {
        $normalizer = new LinkUrlNormalizer('https://example.test/blog', '/blog');
        $inside     = $normalizer->normalize('/blog/articles/one', '/current');
        $outside    = $normalizer->normalize('/unmanaged', '/current');

        self::assertNotNull($inside);
        self::assertSame(LinkKind::LOCAL, $inside->kind);
        self::assertSame('/articles/one', $inside->url);

        self::assertNotNull($outside);
        self::assertSame(LinkKind::EXTERNAL, $outside->kind);
        self::assertSame('https://example.test/unmanaged', $outside->url);
    }

    public function testSkipsUnsupportedLinksAndRecognizesWayback(): void
    {
        self::assertNull($this->normalizer()->normalize('#fragment', '/current'));
        self::assertNull($this->normalizer()->normalize('mailto:reader@example.test', '/current'));
        self::assertNull($this->normalizer()->normalize('javascript:alert(1)', '/current'));

        $archive = $this->normalizer()->normalize(
            'https://web.archive.org/web/20200101000000/https://old.example/',
            '/current',
        );
        self::assertNotNull($archive);
        self::assertSame(LinkKind::ARCHIVE, $archive->kind);
    }

    private function normalizer(): LinkUrlNormalizer
    {
        return new LinkUrlNormalizer('https://example.test', '');
    }
}
