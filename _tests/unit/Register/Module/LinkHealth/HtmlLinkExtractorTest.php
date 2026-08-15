<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\HtmlLinkExtractor;

final class HtmlLinkExtractorTest extends Unit
{
    public function testExtractsOnlyAnchorHrefValuesFromHtmlFragment(): void
    {
        $links = (new HtmlLinkExtractor())->extract(<<<'HTML'
            <p><a href="https://example.test/?a=1&amp;b=2">First</a></p>
            <a class="secondary" href='/local#part'>Second</a>
            <a>No target</a>
            <img src="https://images.example.test/image.jpg" alt="">
            <script>const example = '<a href="https://script.example/">';</script>
            HTML);

        self::assertSame([
            'https://example.test/?a=1&b=2',
            '/local#part',
        ], $links);
    }

    public function testEmptyFragmentHasNoLinks(): void
    {
        self::assertSame([], (new HtmlLinkExtractor())->extract(''));
    }
}
