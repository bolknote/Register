<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Helper;

use Codeception\Test\Unit;
use S2\Cms\Helper\StringHelper;

final class StringHelperTest extends Unit
{
    /**
     * @dataProvider nameInitialsDataProvider
     */
    public function testNameInitials(string $name, string $expected): void
    {
        self::assertSame($expected, StringHelper::nameInitials($name));
    }

    public static function nameInitialsDataProvider(): \Iterator
    {
        yield 'one word' => ['Genux', 'G'];
        yield 'full name' => ['Евгений Степанищев', 'ЕС'];
        yield 'legacy domain qualifier' => ['Евгений Степанищев (bolknote.ru)', 'ЕС'];
        yield 'punctuation' => ['anna-maria petrova', 'AP'];
        yield 'empty' => ['  ', '?'];
        yield 'symbols only' => ['🐈', '?'];
    }

    public function testStablePaletteIndex(): void
    {
        $first  = StringHelper::stablePaletteIndex('Genux', 8);
        $second = StringHelper::stablePaletteIndex('Genux', 8);

        self::assertSame($first, $second);
        self::assertGreaterThanOrEqual(0, $first);
        self::assertLessThan(8, $first);
    }

    public function testStablePaletteIndexRejectsAnEmptyPalette(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StringHelper::stablePaletteIndex('Genux', 0);
    }

    /**
     * @dataProvider jsMailToDataProvider
     */
    public function testJsMailTo(string $name, string $email, string $expectedOutput): void
    {
        $result = StringHelper::jsMailTo($name, $email);
        self::assertSame($expectedOutput, $result);
    }

    public static function jsMailToDataProvider(): \Iterator
    {
        yield 'valid email' => [
            'John Doe',
            'john@example.com',
            '<script type="text/javascript">var mailto="john"+"%40"+"example.com";' .
            'document.write(\'<a href="mailto:\'+mailto+\'">John Doe</a>\');</script>' .
            '<noscript>John Doe, <small>[john at example.com]</small></noscript>'
        ];
        yield 'email with single quote in name' => [
            "John O'Reilly",
            'john@example.com',
            '<script type="text/javascript">var mailto="john"+"%40"+"example.com";' .
            'document.write(\'<a href="mailto:\'+mailto+\'">John O\\\'Reilly</a>\');</script>' .
            "<noscript>John O'Reilly, <small>[john at example.com]</small></noscript>"
        ];
        yield 'invalid email - no @' => [
            'John Doe',
            'invalid-email',
            'John Doe'
        ];
        yield 'invalid email - multiple @' => [
            'John Doe',
            'john@doe@example.com',
            'John Doe'
        ];
    }

    /**
     * @dataProvider isValidEmailDataProvider
     */
    public function testIsValidEmail(string $email, bool $expectedResult): void
    {
        $result = StringHelper::isValidEmail($email);
        self::assertSame($expectedResult, $result);
    }

    public static function isValidEmailDataProvider(): \Iterator
    {
        yield 'valid email' => ['test@example.com', true];
        yield 'valid email with subdomain' => ['test@sub.example.com', true];
        yield 'valid email with plus' => ['test+filter@example.com', true];
        yield 'valid email with quotes' => ['"test"@example.com', true];
        yield 'valid email with ip' => ['test@[127.0.0.1]', true];
        yield 'invalid email - no @' => ['test.example.com', false];
        yield 'invalid email - no domain' => ['test@', false];
        yield 'invalid email - no local part' => ['@example.com', false];
        yield 'invalid email - space' => ['test @example.com', false];
        yield 'invalid email - too long' => [str_repeat('a', 70) . '@example.com', false];
        yield 'invalid email - multiple @' => ['test@@example.com', false];
        yield 'invalid email - special chars' => ['test<@example.com', false];
    }

    /**
     * @dataProvider pagingDataProvider
     * @param array<string, string> $expectedLinks
     */
    public function testPaging(int $page, int $totalPages, string $url, array $expectedLinks, string $expectedOutput): void
    {
        $linksForNavigation = [];
        $result = StringHelper::paging($page, $totalPages, $url, $linksForNavigation);

        self::assertEquals($expectedLinks, $linksForNavigation);
        if ($totalPages <= 1) {
            self::assertSame($expectedOutput, $result);

            return;
        }

        self::assertStringContainsString($expectedOutput, $result);
    }

    public static function pagingDataProvider(): \Iterator
    {
        yield 'first page of many' => [
            1,
            5,
            'http://example.com/page?num=%d',
            ['next' => 'http://example.com/page?num=2'],
            '<span class="current digit">1</span>'
        ];
        yield 'middle page' => [
            3,
            5,
            'http://example.com/page?num=%d',
            ['prev' => 'http://example.com/page?num=2', 'next' => 'http://example.com/page?num=4'],
            '<span class="current digit">3</span>'
        ];
        yield 'last page' => [
            5,
            5,
            'http://example.com/page?num=%d',
            ['prev' => 'http://example.com/page?num=4'],
            '<span class="current digit">5</span>'
        ];
        yield 'single page' => [
            1,
            1,
            'http://example.com/page?num=%d',
            [],
            ''
        ];
        yield 'invalid page' => [
            0,
            5,
            'http://example.com/page?num=%d',
            ['next' => 'http://example.com/page?num=1'],
            '<span class="arrow left">&larr;</span>'
        ];
    }

    public function testBbcodeToHtml(): void
    {
        $input = '[B]bold[/B] [I]italic[/I] [Q=Author]quote[/Q] http://example.com';
        $expected = '<strong>bold</strong> <em>italic</em><blockquote><strong>Author</strong> wrote:<br/><br/><em>quote</em></blockquote><noindex><a href="http://example.com" rel="nofollow">http://example.com</a></noindex>';

        $result = StringHelper::bbcodeToHtml($input, 'wrote:');
        self::assertSame($expected, $result);
    }

    public function testUtf8Wordwrap(): void
    {
        $input = "Это длинная строка на русском языке которая должна быть разбита на несколько строк";
        $expected = "Это длинная строка на русском языке которая \nдолжна быть разбита на несколько строк ";

        $result = StringHelper::utf8Wordwrap($input, 50);
        self::assertSame($expected, $result);
    }

    public function testBbcodeToMail(): void
    {
        $input = "[B]bold[/B] [I]italic[/I] [Q=Author]quote\nline2[/Q]";
        $expected = "*bold* _italic_ \n\n> quote \n> line2";

        $result = StringHelper::bbcodeToMail($input);
        self::assertSame($expected, $result);
    }
}
