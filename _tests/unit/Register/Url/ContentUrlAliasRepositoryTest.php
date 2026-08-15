<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Url;

use PHPUnit\Framework\TestCase;
use Register\Url\ContentUrlAliasRepository;

final class ContentUrlAliasRepositoryTest extends TestCase
{
    /** @dataProvider pathProvider */
    public function testPathNormalization(string $source, string $expected): void
    {
        self::assertSame($expected, ContentUrlAliasRepository::normalizePath($source));
    }

    /** @return iterable<string, array{string, string}> */
    public static function pathProvider(): iterable
    {
        yield 'dated E2 address' => ['/2004/07/19/~1004/', '2004/07/19/~1004'];
        yield 'encoded UTF-8 address' => ['/old/%D1%82%D0%B5%D1%81%D1%82', 'old/тест'];
        yield 'flat slug' => ['old-slug', 'old-slug'];
    }

    /** @dataProvider invalidPathProvider */
    public function testInvalidPathsAreRejected(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentUrlAliasRepository::normalizePath($path);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPathProvider(): iterable
    {
        yield 'empty' => ['/'];
        yield 'query' => ['/old?next=new'];
        yield 'parent segment' => ['/old/../new'];
        yield 'repeated slash' => ['/old//new'];
        yield 'backslash' => ['/old\\new'];
    }
}
