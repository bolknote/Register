<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use Register\Core\HttpClient\HttpClient;
use Register\Extension\activitypub\Content\PortableHtmlSanitizer;

final class PortableHtmlSanitizerTest extends Unit
{
    public function testProducesPortableAbsoluteAndScriptFreeHtml(): void
    {
        $sanitizer = new PortableHtmlSanitizer(new HttpClient());
        $result = $sanitizer->sanitize(
            '<script>alert(1)</script><section><p onclick="x()">Hello '
            . '<a href="../post?id=1" style="color:red" rel="me external">world</a>'
            . '<img src="data:image/png;base64,AAAA" onerror="x()"></p></section>',
            'https://blog.example/about/team',
        );

        self::assertSame(
            '<p>Hello <a href="https://blog.example/post?id=1" rel="me noopener noreferrer">world</a></p>',
            $result,
        );
        self::assertStringNotContainsString('alert', $result);
        self::assertStringNotContainsString('onclick', $result);
        self::assertStringNotContainsString('data:', $result);
    }

    public function testRejectsOversizedInputBeforeDomParsing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PortableHtmlSanitizer(new HttpClient()))->sanitize(
            str_repeat('x', 1_048_577),
            'https://blog.example/',
        );
    }
}
