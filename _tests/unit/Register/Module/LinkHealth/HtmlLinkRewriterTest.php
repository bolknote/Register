<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Module\LinkHealth\HtmlLinkRewriter;
use Register\Module\LinkHealth\LinkUrlNormalizer;

final class HtmlLinkRewriterTest extends Unit
{
    public function testRewritesOnlyMatchingAnchorHrefValuesAndPreservesFragments(): void
    {
        $html = <<<'HTML'
            <p class="intro"><a data-id="1" href='https://broken.example/path?x=1#first'>First</a></p>
            <a href=https://broken.example/path?x=1#second rel="nofollow">Second</a>
            <a data-note=" href='https://broken.example/path?x=1#not-an-attribute' " href="https://broken.example/path?x=1#third">Third</a>
            <a href="https://other.example/">Other</a>
            <script/>const sample = '</scriptx><a href="https://broken.example/path?x=1#script">';</script>
            <!-- <a href="https://broken.example/path?x=1#comment"> -->
            HTML;

        $result = $this->rewriter()->rewrite(
            $html,
            '/source',
            'https://broken.example/path?x=1',
            'https://web.archive.org/web/20250101000000/https://broken.example/path?x=1',
        );

        self::assertSame(3, $result->replacementCount);
        self::assertSame(<<<'HTML'
            <p class="intro"><a data-id="1" href='https://web.archive.org/web/20250101000000/https://broken.example/path?x=1#first'>First</a></p>
            <a href="https://web.archive.org/web/20250101000000/https://broken.example/path?x=1#second" rel="nofollow">Second</a>
            <a data-note=" href='https://broken.example/path?x=1#not-an-attribute' " href="https://web.archive.org/web/20250101000000/https://broken.example/path?x=1#third">Third</a>
            <a href="https://other.example/">Other</a>
            <script/>const sample = '</scriptx><a href="https://broken.example/path?x=1#script">';</script>
            <!-- <a href="https://broken.example/path?x=1#comment"> -->
            HTML, $result->html);
    }

    public function testEncodesAFragmentForTheExistingAttributeQuote(): void
    {
        $result = $this->rewriter()->rewrite(
            '<a href="https://broken.example/#one&amp;two">Link</a>',
            '/',
            'https://broken.example/',
            'https://web.archive.org/web/20250101000000/https://broken.example/',
        );

        self::assertSame(
            '<a href="https://web.archive.org/web/20250101000000/https://broken.example/#one&amp;two">Link</a>',
            $result->html,
        );
    }

    public function testPreservesAnUnclosedRawTextElement(): void
    {
        $html = '<script>const sample = \'<a href="https://broken.example/">\';';

        $result = $this->rewriter()->rewrite(
            $html,
            '/',
            'https://broken.example/',
            'https://web.archive.org/web/20250101000000/https://broken.example/',
        );

        self::assertSame(0, $result->replacementCount);
        self::assertSame($html, $result->html);
    }

    private function rewriter(): HtmlLinkRewriter
    {
        return new HtmlLinkRewriter(new LinkUrlNormalizer('https://site.example', ''));
    }
}
