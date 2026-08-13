<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Content;

use Codeception\Test\Unit;
use Register\Content\ContentId;
use Register\Content\ContentType;

final class ContentIdTest extends Unit
{
    public function testRoundTripsTypedIdentifiers(): void
    {
        self::assertSame('post:42', (string)ContentId::post(42));
        self::assertEquals(ContentId::page(7), ContentId::fromString('page:7'));
        self::assertSame(ContentType::POST, ContentId::fromString('post:9')->type);
    }

    /** @dataProvider invalidIdentifierProvider */
    public function testRejectsInvalidIdentifiers(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentId::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'untyped' => ['42'];
        yield 'zero' => ['post:0'];
        yield 'negative' => ['page:-1'];
        yield 'unknown type' => ['article:1'];
        yield 'overflow' => ['post:999999999999999999999999999999'];
    }
}
