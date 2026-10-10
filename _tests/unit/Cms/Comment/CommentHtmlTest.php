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
        self::assertStringNotContainsString('style=', $stored);
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

    public function testTrailingBreaksAreRemovedFromNewAndExistingRichComments(): void
    {
        $html = 'Что-то не так с рсс<br>https://example.com/rss<br><br><br>';
        $expected = 'Что-то не так с рсс<br>https://example.com/rss';

        self::assertSame(
            '<!--register-comment-html:v1-->' . $expected,
            CommentHtml::sanitizeForStorage($html),
        );
        self::assertSame(
            $expected,
            CommentHtml::render('<!--register-comment-html:v1-->' . $html, 'wrote:'),
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

    public function testMailTextPreservesParagraphsAndExplicitLineBreaks(): void
    {
        $stored = CommentHtml::sanitizeForStorage(
            '<p>First paragraph.</p><p>Second paragraph.<br>Next line.</p>'
                . '<p>Last &amp; final.</p>',
        );

        self::assertSame(
            "First paragraph.\n\nSecond paragraph.\nNext line.\n\nLast & final.",
            CommentHtml::mailText($stored),
        );
        // Mail formatting must not change the text used for validation and spam checks.
        self::assertSame(
            "First paragraph.\nSecond paragraph.\nNext line.\nLast & final.",
            CommentHtml::plainText($stored),
        );
    }

    public function testMailTextPrefixesEveryQuotedLineIncludingNestedQuotes(): void
    {
        $stored = CommentHtml::sanitizeForStorage(
            '<p>Introduction.</p><blockquote><p>First line.<br>Second line.</p>'
                . '<blockquote><p>Nested quote.</p><p>Another paragraph.</p></blockquote>'
                . '<p>Outer quote again.</p></blockquote><p>Answer.</p>',
        );

        self::assertSame(
            "Introduction.\n\n> First line.\n> Second line.\n>\n> > Nested quote."
                . "\n> >\n> > Another paragraph.\n>\n> Outer quote again.\n\nAnswer.",
            CommentHtml::mailText($stored),
        );
    }

    public function testMailTextDoesNotJoinTextToAdjacentQuotesOrTreatHtmlIndentationAsLines(): void
    {
        $stored = CommentHtml::sanitizeForStorage(
            "Before <strong>the quote</strong><blockquote>Quoted text.</blockquote>After the quote.\n"
                . "<p>Next <strong>formatted</strong> <em>paragraph</em>.</p>\n",
        );

        self::assertSame(
            "Before the quote\n\n> Quoted text.\n\nAfter the quote.\n\nNext formatted paragraph.",
            CommentHtml::mailText($stored),
        );
    }

    public function testMailTextKeepsRepeatedExplicitBreaksAndPreformattedIndentation(): void
    {
        $stored = CommentHtml::sanitizeForStorage(
            '<p>One<br><br><br>Two</p><pre><code>  first();' . "\n    second();"
                . '</code></pre><p>End.</p>',
        );

        self::assertSame(
            "One\n\n\nTwo\n\n  first();\n    second();\n\nEnd.",
            CommentHtml::mailText($stored),
        );
        self::assertSame(
            "  first();\n    second();",
            CommentHtml::mailText(CommentHtml::sanitizeForStorage("<pre>  first();\n    second();</pre>")),
        );
    }

    public function testMailTextDoesNotDiscardBreaksInEmptyParagraphs(): void
    {
        self::assertSame(
            "Before.\n\n\n\nAfter.",
            CommentHtml::mailText(CommentHtml::sanitizeForStorage(
                '<p>Before.</p><p><br></p><p><br></p><p>After.</p>',
            )),
        );
    }

    public function testMailTextKeepsListsAndSafeLinkTargetsReadable(): void
    {
        $stored = CommentHtml::sanitizeForStorage(
            '<p>Read <a href="https://example.test/page?a=1&amp;b=2">this page</a>.</p>'
                . '<ul><li>First</li><li>Second<br>Continued</li></ul>'
                . '<p><a href="https://example.test/">https://example.test/</a></p>',
        );

        self::assertSame(
            "Read this page (https://example.test/page?a=1&b=2).\n\n- First\n- Second\nContinued"
                . "\n\nhttps://example.test/",
            CommentHtml::mailText($stored),
        );
    }

    public function testMailTextRetainsLegacyQuoteFormattingAndManagedAttachmentPaths(): void
    {
        $legacy = "[Q]First quoted line\nSecond quoted line[/Q]\n\nAnswer.";
        self::assertSame(CommentHtml::plainText($legacy), CommentHtml::mailText($legacy));
        self::assertStringContainsString('> First quoted line', CommentHtml::mailText($legacy));
        self::assertStringContainsString('> Second quoted line', CommentHtml::mailText($legacy));

        $source = '/_pictures/telegram/comments/123/2/image.png';
        $stored = CommentHtml::sanitizeImportedForStorage(
            '<p>Attachment:</p><figure class="comment-media"><img src="' . $source . '"></figure><p>Answer.</p>',
        );
        self::assertSame("Attachment:\n\n" . $source . "\n\nAnswer.", CommentHtml::mailText($stored));
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

    public function testGenericImportedMediaWorksUnderABasePathAndRejectsTraversal(): void
    {
        foreach (['', '/blog'] as $basePath) {
            $source = $basePath . '/_pictures/telegram/comments/123/2/01-0123456789abcdef0123.png';
            $stored = CommentHtml::sanitizeImportedForStorage('<figure class="comment-media"><img src="' . $source . '"></figure>');
            self::assertStringContainsString($source, CommentHtml::render($stored, 'wrote:'));
            self::assertSame('', CommentHtml::sanitizeForStorage('<img src="' . $source . '">'));
        }

        foreach (['..', '%2e%2e', '%2fprivate', '%5cprivate', '%00'] as $segment) {
            $source = '/blog/_pictures/telegram/comments/123/' . $segment . '/file.png';
            self::assertSame('', CommentHtml::sanitizeImportedForStorage('<img src="' . $source . '">'));
        }
    }

    public function testImportedUnavailableAttachmentKeepsSemanticMarkerAndUsesRenderLocale(): void
    {
        $stored = CommentHtml::sanitizeImportedForStorage(
            '<span class="comment-media-missing" data-kind="photo" data-count="1">'
                . 'Telegram attachment unavailable</span>',
        );

        self::assertStringContainsString('class="comment-media-missing"', $stored);
        self::assertStringContainsString('data-kind="photo"', $stored);
        self::assertSame('Telegram attachment unavailable', CommentHtml::plainText($stored));

        $rendered = CommentHtml::render($stored, 'написал:', [
            'attachment' => 'Вложение из Telegram недоступно',
            'photo' => 'Изображение из Telegram недоступно',
            'multiple' => 'Вложения из Telegram недоступны (%count%)',
        ]);
        self::assertStringContainsString('Изображение из Telegram недоступно', $rendered);
        self::assertStringNotContainsString('Telegram attachment unavailable', $rendered);
    }

    public function testUnavailableAttachmentLabelsBelongToPublicLanguagePacks(): void
    {
        $root = dirname(__DIR__, 4);
        $english = require $root . '/_lang/English/common.php';
        $russian = require $root . '/_lang/Russian/common.php';

        self::assertSame(
            'Telegram attachment unavailable',
            $english['Telegram attachment unavailable'] ?? null,
        );
        self::assertSame(
            'Вложение из Telegram недоступно',
            $russian['Telegram attachment unavailable'] ?? null,
        );
        self::assertSame(
            'Изображение из Telegram недоступно',
            $russian['Telegram image unavailable'] ?? null,
        );
        self::assertArrayNotHasKey('Telegram attachment unavailable admin detail', $english);
        self::assertArrayNotHasKey('Telegram attachment unavailable admin detail', $russian);
    }

    public function testOrdinaryCommentCannotForgeUnavailableAttachmentComponent(): void
    {
        $stored = CommentHtml::sanitizeForStorage(
            '<span class="comment-media-missing" data-kind="photo">Visible text</span>',
        );

        self::assertStringNotContainsString('comment-media-missing', $stored);
        self::assertSame('Visible text', CommentHtml::plainText($stored));
    }

    public function testLegacyTelegramMediaErrorRendersAsLocalizedAttachmentState(): void
    {
        $stored = CommentHtml::sanitizeForStorage(
            '<p>Подпись</p><br><br><em>Telegram attachment is not contained in the JSON: '
                . '(File not included. Change data exporting settings to download.).</em>',
        );
        $rendered = CommentHtml::render($stored, 'написал:', [
            'attachment' => 'Вложение из Telegram недоступно',
        ]);

        self::assertStringContainsString('<p>Подпись</p><span class="comment-media-missing"', $rendered);
        self::assertStringContainsString('Вложение из Telegram недоступно', $rendered);
        self::assertStringNotContainsString('File not included', $rendered);
    }
}
