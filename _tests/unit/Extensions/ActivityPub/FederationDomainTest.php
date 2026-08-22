<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use Register\Extension\activitypub\Domain\CanonicalBasePath;
use Register\Extension\activitypub\Domain\CanonicalOrigin;
use Register\Extension\activitypub\Domain\FederationUrlGenerator;
use Register\Extension\activitypub\Domain\LocalHandle;

final class FederationDomainTest extends Unit
{
    public function testNormalizesAndBuildsFrozenFederationUrls(): void
    {
        $origin    = new CanonicalOrigin('HTTPS://Blog.Example:443/');
        $basePath = new CanonicalBasePath('/register/');
        $urls      = new FederationUrlGenerator($origin, $basePath);
        $publicId  = 'Abcdefghijklmnopqrstu_';

        self::assertSame('https://blog.example', $origin->value);
        self::assertSame('blog.example', $origin->authority());
        self::assertSame('/register', $basePath->value);
        self::assertSame('https://blog.example/register/activitypub/actors/' . $publicId, $urls->actor($publicId));
        self::assertSame('https://blog.example/register/activitypub/actors/' . $publicId . '/inbox', $urls->actorInbox($publicId));
        self::assertSame('https://blog.example/register/activitypub/objects/' . $publicId, $urls->object($publicId));
        self::assertSame('https://blog.example/register/activitypub/keys/' . $publicId, $urls->key($publicId));
        self::assertSame('https://blog.example/register/nodeinfo/2.1', $urls->nodeInfo());
    }

    /** @dataProvider invalidOrigins */
    public function testRejectsUnsafeOrNonOriginCanonicalUrls(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CanonicalOrigin($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOrigins(): iterable
    {
        yield 'plaintext' => ['http://blog.example'];
        yield 'credentials' => ['https://user@blog.example'];
        yield 'path' => ['https://blog.example/register'];
        yield 'query' => ['https://blog.example/?x=1'];
        yield 'ip literal' => ['https://127.0.0.1'];
        yield 'bad label' => ['https://-blog.example'];
        yield 'unicode must be configured as A-label' => ['https://пример.рф'];
    }

    /** @dataProvider invalidBasePaths */
    public function testRejectsAmbiguousBasePaths(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CanonicalBasePath($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBasePaths(): iterable
    {
        yield 'relative' => ['register'];
        yield 'dot segment' => ['/register/../admin'];
        yield 'query' => ['/register?x=1'];
        yield 'broken percent encoding' => ['/register/%zz'];
        yield 'control character' => ["/register\nadmin"];
    }

    public function testNormalizesHandlesWithoutExposingLoginSemantics(): void
    {
        self::assertSame('blog_team', (new LocalHandle(' Blog_Team '))->value);

        $this->expectException(\InvalidArgumentException::class);
        new LocalHandle('admin@example.org');
    }
}
