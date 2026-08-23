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

        self::assertSame('Первое предложение & важная деталь.', $metadata->excerpt);
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
            'Hand-written excerpt',
            'Hand-written meta description',
        );

        self::assertSame('Hand-written excerpt', $metadata->excerpt);
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
