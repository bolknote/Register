<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Comment;

use Codeception\Test\Unit;
use S2\Cms\Comment\CommentHtml;

final class CommentHtmlTest extends Unit
{
    public function testRebuildsSubmittedHtmlFromAFormattingAllowList(): void
    {
        $stored = CommentHtml::sanitizeForStorage(<<<'HTML'
<p onclick="alert(1)"><b>Bold</b> <span style="font-style: italic">italic</span>
<img src="https://tracker.example/pixel"><script>alert(1)</script>
<a href="javascript:alert(1)">unsafe</a> <a href="https://example.com/a?b=1">safe</a></p>
HTML);

        self::assertStringStartsWith('<!--register-comment-html:v1-->', $stored);
        self::assertStringContainsString('<strong>Bold</strong>', $stored);
        self::assertStringContainsString('<em>italic</em>', $stored);
        self::assertStringContainsString('unsafe', $stored);
        self::assertStringContainsString(
            '<a href="https://example.com/a?b=1" rel="nofollow ugc">safe</a>',
            $stored,
        );
        self::assertStringNotContainsString('onclick', $stored);
        self::assertStringNotContainsString('<img', $stored);
        self::assertStringNotContainsString('<script', $stored);
        self::assertStringNotContainsString('javascript:', $stored);
    }

    public function testParserRepairsMalformedHtmlBeforeItIsRendered(): void
    {
        $stored = CommentHtml::sanitizeForStorage('<p><b>one<i>two</p><blockquote>quote');
        $rendered = CommentHtml::render($stored, 'wrote:');

        self::assertSame(
            '<p><strong>one<em>two</em></strong></p><blockquote>quote</blockquote>',
            $rendered,
        );
    }

    public function testFormulaSourceSurvivesParsingExactly(): void
    {
        $formula = <<<'TEXT'
$$f(x) = x^2-\sqrt{x}$$
TEXT;
        $stored = CommentHtml::sanitizeForStorage('<p>' . $formula . '</p>');

        self::assertStringContainsString($formula, $stored);
        self::assertSame($formula, CommentHtml::plainText($stored));
    }

    public function testPlainTextIncludesSafeLinkTargetsForMailAndSpamChecks(): void
    {
        $stored = CommentHtml::sanitizeForStorage(
            '<p>Read <a href="https://example.com/page">this page</a>.</p><ul><li>First</li><li>Second</li></ul>',
        );

        self::assertSame(
            "Read this page (https://example.com/page).\n- First\n- Second",
            CommentHtml::plainText($stored),
        );
    }

    public function testLegacyCommentsKeepTheirOldBbcodeRendering(): void
    {
        self::assertSame(
            '<strong>old</strong><br />text',
            CommentHtml::render("[B]old[/B]\ntext", 'wrote:'),
        );
    }

    public function testMediaOnlyInputIsEmptyAfterSanitizing(): void
    {
        self::assertSame('', CommentHtml::sanitizeForStorage('<img src="x"><video src="x"></video>'));
    }
}
