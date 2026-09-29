<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Content;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Register\Ai\AiClient;
use Register\Ai\AiSettings;
use Register\Content\PublicationMetadataGenerator;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpResponse;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class PublicationMetadataGeneratorTest extends TestCase
{
    public function testLocalFallbackUsesLeadTextAndRemovesNonEditorialContent(): void
    {
        $generator = $this->generator($this->settings());

        $metadata = $generator->complete(
            'Заголовок',
            '<h1>Заголовок</h1><p>Первое предложение &amp; важная деталь.</p>'
            . '<script>Секретный служебный текст.</script><cut />'
            . '<p>Текст после ката не должен попасть в описание.</p>',
        );

        self::assertSame('Первое предложение &amp; важная деталь.', $metadata->excerpt);
        self::assertSame('Первое предложение & важная деталь.', $metadata->metaDescription);
        self::assertFalse($metadata->generatedWithAi);
    }

    public function testLocalFallbackPacksSentencesAndTruncatesLongOnesAtAWord(): void
    {
        $generator = $this->generator($this->settings());
        $longSentence = str_repeat('длинное слово ', 40) . 'завершается.';

        $metadata = $generator->complete(
            '',
            '<p>Короткое первое предложение. Второе предложение тоже помещается.</p><p>' . $longSentence . '</p>',
        );

        self::assertLessThanOrEqual(PublicationMetadataGenerator::EXCERPT_LENGTH, mb_strlen($metadata->excerpt));
        self::assertLessThanOrEqual(PublicationMetadataGenerator::META_DESCRIPTION_LENGTH, mb_strlen($metadata->metaDescription));
        self::assertStringStartsWith('Короткое первое предложение.', $metadata->excerpt);
        self::assertStringNotContainsString('завершается', $metadata->metaDescription);

        $singleSentence = $generator->complete('', '<p>' . $longSentence . '</p>');
        self::assertStringEndsWith('…', $singleSentence->metaDescription);
        self::assertLessThanOrEqual(
            PublicationMetadataGenerator::META_DESCRIPTION_LENGTH,
            mb_strlen($singleSentence->metaDescription),
        );
    }

    public function testLocalFallbackPreservesUnicodeJoiningCharacters(): void
    {
        $generator = $this->generator($this->settings());
        $text = "Семья 👨‍👩‍👧‍👦. Работа 👩🏽‍💻. می\u{200C}روم. क्\u{200D}ष. 20\u{2060}°C.";

        foreach (['', 'Title'] as $title) {
            $metadata = $generator->complete($title, '<p>' . $text . '</p>');
            self::assertSame($text, $metadata->excerpt);
            self::assertSame($text, $metadata->metaDescription);
        }
    }

    public function testLocalFallbackReadsHtmlStructure(): void
    {
        $generator = $this->generator($this->settings());
        foreach ([
            ['<p title="left > right">First.</p><p>Second.</p>', 'First. Second.'],
            ["<p title='quoted > <em>markup</em>'>First.</p><p>Second.</p>", 'First. Second.'],
            ['<p>Intro.</p><template>outer<template>inner</template>hidden tail</template><p>Ending.</p>', 'Intro. Ending.'],
            ['<p>Intro.</p><code>outer<code>inner</code>hidden tail</code><p>Ending.</p>', 'Intro. Ending.'],
            ['<p>Intro.</p><script title="quoted > marker">hidden script</script><p>Ending.</p>', 'Intro. Ending.'],
            ['<p>First.<p>Second.<br>Third.', 'First. Second. Third.'],
            ['<p>👩&zwj;💻 می&zwnj;روم 20&NoBreak;°C &NotEqualTilde; &amp;lt;tag&amp;gt;</p>',
                "👩‍💻 می\u{200C}روم 20\u{2060}°C ≂̸ &lt;tag&gt;"],
            ['<p>&Afr; &fjlig; &NotEqualTilde; &LT;tag&GT;</p>', '𝔄 fj ≂̸ <tag>'],
        ] as [$html, $expected]) {
            $metadata = $generator->complete('Title', $html);
            self::assertSame($expected, $metadata->metaDescription, $html);
            self::assertSame(htmlspecialchars($expected, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), $metadata->excerpt);
        }
    }

    public function testLocalFallbackUsesOnlyEditorialCutMarkers(): void
    {
        $generator = $this->generator($this->settings());
        foreach ([
            ['<p>Intro.</p><!-- <cut /> --><p>Ending.</p>', 'Intro. Ending.'],
            ['<p>Intro.</p><p title="<cut>">Ending.</p>', 'Intro. Ending.'],
            ['<p>Intro.</p><script>const marker = "<cut>";</script><p>Ending.</p>', 'Intro. Ending.'],
            ['<p>Intro.</p><style>p::before {content:"<cut>"}</style><p>Ending.</p>', 'Intro. Ending.'],
            ['<p>Intro.</p><pre>sample <cut /> code</pre><p>Ending.</p>', 'Intro. Ending.'],
            ['<p>Intro.</p><template><cut /></template><p>Ending.</p>', 'Intro. Ending.'],
            ['<p>Intro.</p><!-- <cut /> --><p>Ending.</p><CUT /><p>After real cut.</p>', 'Intro. Ending.'],
            ['<p>Intro.</p><cut><p>After real cut.</p>', 'Intro.'],
            ['<pre>only code</pre><cut><p>Visible body.</p>', 'Visible body.'],
        ] as [$html, $expected]) {
            $metadata = $generator->complete('Title', $html);
            self::assertSame($expected, $metadata->metaDescription, $html);
            self::assertSame(htmlspecialchars($expected, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), $metadata->excerpt);
        }
    }

    public function testLocalFallbackDecodesHtmlOnlyOnce(): void
    {
        $generator = $this->generator($this->settings());
        foreach ([
            ['', '<p>Use &lt;widget&gt; and &lt;/widget&gt; as literal text.</p>', 'Use <widget> and </widget> as literal text.'],
            ['Title', '<p>Write &amp;lt;widget&amp;gt; and &amp;amp; in the source.</p>', 'Write &lt;widget&gt; and &amp; in the source.'],
            ['Title', '<p>Compare a &lt; b &gt; c and x &amp; y.</p>', 'Compare a < b > c and x & y.'],
            ['<widget> &amp;', '<h1>&lt;widget&gt; &amp;amp;</h1><p>Body.</p>', 'Body.'],
        ] as [$title, $html, $expected]) {
            $metadata = $generator->complete($title, $html);
            self::assertSame(htmlspecialchars($expected, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), $metadata->excerpt);
            self::assertSame($expected, $metadata->metaDescription);
        }
    }

    public function testLocalFallbackNormalizesWhitespaceBeforeRemovingTitle(): void
    {
        $generator = $this->generator($this->settings());
        foreach (['&nbsp;', '&#8203;', '&#xfeff;', '&#8239;'] as $separator) {
            $metadata = $generator->complete('Article title', '<h1>Article' . $separator . 'title</h1><p>Visible body.</p>');

            self::assertSame('Visible body.', $metadata->excerpt, $separator);
            self::assertSame('Visible body.', $metadata->metaDescription, $separator);
        }
    }

    public function testLocalFallbackUsesBodyWhenLeadHasNoEditorialText(): void
    {
        $generator = $this->generator($this->settings());
        foreach ([
            '<p>&nbsp;&#8203;&#xfeff;</p>',
            '<h1>Title</h1>',
            '<h1>&nbsp;Title&#8203;</h1><p>&nbsp;</p>',
        ] as $lead) {
            $metadata = $generator->complete('Title', $lead . '<cut /><p>Visible body.</p>');

            self::assertSame('Visible body.', $metadata->excerpt, $lead);
            self::assertSame('Visible body.', $metadata->metaDescription, $lead);
        }
    }

    public function testEmptyEditorialTextDoesNotCallAi(): void
    {
        $generator = $this->generator(
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_OPENROUTER,
                AiSettings::API_KEY_CONFIG_KEY => 'secret',
                AiSettings::AUTO_METADATA_CONFIG_KEY => '1',
            ]),
            static function (): HttpResponse {
                throw new \LogicException('Empty content must not trigger AI metadata generation.');
            },
        );

        foreach (['', 'Title'] as $title) {
            $metadata = $generator->complete($title, '<p>&nbsp;&#8203;&#xfeff;</p><pre>non-editorial code</pre>');
            self::assertSame('', $metadata->excerpt);
            self::assertSame('', $metadata->metaDescription);
            self::assertFalse($metadata->generatedWithAi);
        }
    }

    public function testGeneratedExcerptRendersLiteralMarkupAsText(): void
    {
        $generator = $this->generator($this->settings());
        $plainText = 'Literal <img src="x" onerror="alert(1)"> and &lt;widget&gt; 👩🏽‍💻.';
        $html = htmlspecialchars($plainText, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $metadata = $generator->complete('Title', '<p>' . $html . '</p>');
        self::assertSame($html, $metadata->excerpt);
        self::assertSame($plainText, $metadata->metaDescription);

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $metadata->excerpt . '</body></html>');
        self::assertSame($plainText, $document->getElementsByTagName('body')->item(0)?->textContent);
        self::assertSame(0, $document->getElementsByTagName('img')->length);
    }

    public function testAiMetadataPreservesPlainTextAndUnicodeJoiningCharacters(): void
    {
        $expected = "Use <widget>, &lt;entity&gt; 👩🏽‍💻 می\u{200C}روم. 20\u{2060}°C.";
        $calls = [];
        $generator = $this->generator(
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_OPENROUTER,
                AiSettings::API_KEY_CONFIG_KEY => 'secret',
                AiSettings::AUTO_METADATA_CONFIG_KEY => '1',
            ]),
            static function (string $method, string $url, array $headers, ?string $body) use (&$calls, $expected): HttpResponse {
                $calls[] = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
                $html = '<b>' . htmlspecialchars($expected, ENT_QUOTES, 'UTF-8') . '</b>';

                return new HttpResponse(statusCode: 200, content: json_encode([
                    'choices' => [[
                        'message' => ['content' => json_encode([
                            'excerpt' => $html,
                            'meta_description' => $html,
                        ], JSON_THROW_ON_ERROR)],
                    ]],
                ], JSON_THROW_ON_ERROR));
            },
        );
        $title = '👩🏽‍💻 <widget> &amp;';
        $metadata = $generator->complete($title, '<p>' . htmlspecialchars($expected, ENT_QUOTES, 'UTF-8') . '</p>');

        self::assertSame(htmlspecialchars($expected, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), $metadata->excerpt);
        self::assertSame($expected, $metadata->metaDescription);
        self::assertTrue($metadata->generatedWithAi);
        self::assertCount(1, $calls);
        self::assertStringContainsString($title, (string) $calls[0]['messages'][0]['content']);
        self::assertStringContainsString($expected, (string) $calls[0]['messages'][0]['content']);
    }

    public function testExistingMetadataIsPreservedWithoutCallingAi(): void
    {
        $called = false;
        $generator = $this->generator(
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_OPENROUTER,
                AiSettings::API_KEY_CONFIG_KEY => 'secret',
                AiSettings::AUTO_METADATA_CONFIG_KEY => '1',
            ]),
            static function () use (&$called): HttpResponse {
                $called = true;
                throw new \LogicException('AI must not be called.');
            },
        );

        $metadata = $generator->complete(
            'Title',
            '<p>Body.</p>',
            '<em>Hand-written &amp; excerpt</em>',
            'Hand-written meta description',
        );

        self::assertSame('<em>Hand-written &amp; excerpt</em>', $metadata->excerpt);
        self::assertSame('Hand-written meta description', $metadata->metaDescription);
        self::assertFalse($called);
    }

    public function testConfiguredAiIsNotUsedWhileAutomaticMetadataIsDisabled(): void
    {
        $called = false;
        $generator = $this->generator(
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_OPENROUTER,
                AiSettings::API_KEY_CONFIG_KEY => 'secret',
            ]),
            static function () use (&$called): HttpResponse {
                $called = true;
                throw new \LogicException('AI must not be called.');
            },
        );

        $metadata = $generator->complete('', '<p>Local publication summary.</p>');

        self::assertSame('Local publication summary.', $metadata->excerpt);
        self::assertFalse($called);
    }

    public function testAiCompletesEmptyFieldsWithACompactJsonRequest(): void
    {
        $calls = [];
        $generator = $this->generator(
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_OPENROUTER,
                AiSettings::API_KEY_CONFIG_KEY => 'secret',
                AiSettings::AUTO_METADATA_CONFIG_KEY => '1',
            ]),
            static function (string $method, string $url, array $headers, ?string $body, array $options) use (&$calls): HttpResponse {
                $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];

                return new HttpResponse(statusCode: 200, content: json_encode([
                    'choices' => [[
                        'message' => ['content' => json_encode([
                            'excerpt' => '<b>AI excerpt.</b>',
                            'meta_description' => 'AI meta description.',
                        ], JSON_THROW_ON_ERROR)],
                    ]],
                ], JSON_THROW_ON_ERROR));
            },
        );

        $metadata = $generator->complete('Title', '<p>Clean source.</p><script>Not source.</script>');

        self::assertSame('AI excerpt.', $metadata->excerpt);
        self::assertSame('AI meta description.', $metadata->metaDescription);
        self::assertTrue($metadata->generatedWithAi);
        self::assertCount(1, $calls);
        $request = json_decode((string)$calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(512, $request['max_tokens']);
        self::assertStringContainsString('Clean source.', (string)$request['messages'][0]['content']);
        self::assertStringNotContainsString('Not source.', (string)$request['messages'][0]['content']);
    }

    public function testInvalidAiResponseFallsBackToLocalMetadata(): void
    {
        $generator = $this->generator(
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_OPENROUTER,
                AiSettings::API_KEY_CONFIG_KEY => 'secret',
                AiSettings::AUTO_METADATA_CONFIG_KEY => '1',
            ]),
            static fn(): HttpResponse => new HttpResponse(
                statusCode: 200,
                content: '{"choices":[{"message":{"content":"not json"}}]}',
            ),
        );

        $metadata = $generator->complete('', '<p>Reliable local summary.</p>');

        self::assertSame('Reliable local summary.', $metadata->excerpt);
        self::assertSame('Reliable local summary.', $metadata->metaDescription);
        self::assertFalse($metadata->generatedWithAi);
    }

    /**
     * @param null|callable(string, string, array<string, string>, ?string, array<string, int|bool|string>): HttpResponse $request
     */
    private function generator(AiSettings $settings, ?callable $request = null): PublicationMetadataGenerator
    {
        return new PublicationMetadataGenerator(
            new AiClient(new HttpClient(), $settings, new ArrayAdapter(), $request),
            $settings,
            new NullLogger(),
        );
    }

    /** @param array<string, string> $overrides */
    private function settings(array $overrides = []): AiSettings
    {
        $values = array_replace([
            AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_DISABLED,
            AiSettings::API_KEY_CONFIG_KEY => '',
            AiSettings::MODEL_CONFIG_KEY => '',
            AiSettings::FOLDER_ID_CONFIG_KEY => '',
            AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => '',
            AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
            AiSettings::AUTO_ALT_CONFIG_KEY => '1',
            AiSettings::AUTO_METADATA_CONFIG_KEY => '0',
        ], $overrides);
        $provider = new class($values) extends DynamicConfigProvider {
            /** @param array<string, string> $values */
            public function __construct(private array $values)
            {
                parent::__construct();
            }

            #[\Override]
            public function get(string $paramName): mixed
            {
                return $this->values[$paramName];
            }
        };

        return new AiSettings($provider);
    }
}
