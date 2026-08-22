<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Comment;

use Codeception\Test\Unit;
use Register\Core\Comment\CommentHtml;

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

    public function testLegacyCommentsCanBeMigratedToCanonicalHtmlStorage(): void
    {
        $stored = CommentHtml::migrateLegacyForStorage(
            "[Q]цитата[/Q]\n\nОтвет [B]жирный[/B] и https://example.com/a.",
        );

        self::assertSame(
            '<!--register-comment-html:v1--><blockquote>цитата</blockquote>'
                . '<p>Ответ <strong>жирный</strong> и '
                . '<a href="https://example.com/a" rel="nofollow ugc">https://example.com/a</a>.</p>',
            $stored,
        );
        self::assertSame(
            "цитата\nОтвет жирный и https://example.com/a.",
            CommentHtml::plainText($stored),
        );
        self::assertSame($stored, CommentHtml::migrateLegacyForStorage($stored));
        self::assertStringNotContainsString('[Q]', CommentHtml::render($stored, 'wrote:'));
    }

    public function testLegacyMigrationKeepsOnlyManagedCommentAttachmentsAsHtmlMedia(): void
    {
        $image = '/_pictures/bolknote/comments/20230820.jpg';
        $video = '/_pictures/bolknote/comments/20230820.mp4';
        $audio = '/_pictures/bolknote/comments/20230820.mp3';
        $file = '/_pictures/bolknote/comments/20230820.zip';
        $stored = CommentHtml::migrateLegacyForStorage(implode("\n", [
            '[IMG]' . $image . '[/IMG]',
            '[VIDEO]' . $video . '[/VIDEO]',
            '[AUDIO]' . $audio . '[/AUDIO]',
            '[FILE]' . $file . '[/FILE]',
        ]));
        $rendered = CommentHtml::render($stored, 'wrote:');

        self::assertStringContainsString(
            '<figure class="comment-media"><img src="' . $image
                . '" alt="" loading="lazy" decoding="async"></figure>',
            $rendered,
        );
        self::assertStringContainsString('<video src="' . $video . '" controls preload="metadata">', $rendered);
        self::assertStringContainsString('<audio src="' . $audio . '" controls preload="metadata">', $rendered);
        self::assertStringContainsString(
            '<a class="comment-media-file" href="' . $file . '" rel="nofollow ugc">20230820.zip</a>',
            $rendered,
        );
        foreach ([$image, $video, $audio, $file] as $path) {
            self::assertStringContainsString($path, CommentHtml::plainText($stored));
        }

        self::assertSame(
            '',
            CommentHtml::migrateLegacyForStorage(
                '[IMG]/_pictures/bolknote/comments/../private.jpg[/IMG]',
            ),
        );
    }

    public function testMediaOnlyInputIsEmptyAfterSanitizing(): void
    {
        self::assertSame('', CommentHtml::sanitizeForStorage('<img src="x"><video src="x"></video>'));
        self::assertSame(
            '',
            CommentHtml::sanitizeForStorage(
                '<!--register-comment-html:v1--><img '
                    . 'src="/_pictures/bolknote/comments/20230820.jpg">',
            ),
        );
    }
}
