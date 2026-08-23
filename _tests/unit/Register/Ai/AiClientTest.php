<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Ai;

use PHPUnit\Framework\TestCase;
use Register\Ai\AiClient;
use Register\Ai\AiImageInput;
use Register\Ai\AiSettings;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpResponse;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AiClientTest extends TestCase
{
    /** @dataProvider imageCapabilityProvider */
    public function testImageCapabilityDetection(string $provider, string $model, bool $expected): void
    {
        self::assertSame($expected, AiSettings::supportsImageInputFor($provider, $model));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function imageCapabilityProvider(): iterable
    {
        yield 'Gemini' => [AiSettings::PROVIDER_GEMINI, 'gemini-3.5-flash-lite', true];
        yield 'Gemini embedding' => [AiSettings::PROVIDER_GEMINI, 'gemini-embedding-001', false];
        yield 'Groq default text model' => [AiSettings::PROVIDER_GROQ, 'openai/gpt-oss-20b', false];
        yield 'Groq vision model' => [AiSettings::PROVIDER_GROQ, 'meta-llama/llama-4-scout', true];
        yield 'OpenRouter free router' => [AiSettings::PROVIDER_OPENROUTER, 'openrouter/free', true];
        yield 'Mistral Small' => [AiSettings::PROVIDER_MISTRAL, 'mistral-small-latest', true];
        yield 'Cloudflare Gemma' => [AiSettings::PROVIDER_CLOUDFLARE, '@cf/google/gemma-4-26b-a4b-it', true];
        yield 'Yandex text endpoint' => [AiSettings::PROVIDER_YANDEX, 'yandexgpt-5-lite', false];
        yield 'Unknown custom model' => [AiSettings::PROVIDER_OPENROUTER, 'vendor/text-model', false];
    }

    public function testAutomaticMetadataRemainsOptInWhenSettingIsMissing(): void
    {
        $settings = new AiSettings(new DynamicConfigProvider());

        self::assertFalse($settings->autoMetadataEnabled());
        self::assertTrue($settings->autoAltEnabled());
    }

    public function testGeminiImageAltUsesInlineImageAndNormalizesResponse(): void
    {
        $calls = [];
        $client = new AiClient(
            new HttpClient(),
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_GEMINI,
                AiSettings::API_KEY_CONFIG_KEY => 'gemini-secret',
                AiSettings::MODEL_CONFIG_KEY => '',
                AiSettings::FOLDER_ID_CONFIG_KEY => '',
                AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => '',
                AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
                AiSettings::AUTO_ALT_CONFIG_KEY => '1',
            ]),
            new ArrayAdapter(),
            static function (string $method, string $url, array $headers, ?string $body, array $options) use (&$calls): HttpResponse {
                $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
                return new HttpResponse(
                    statusCode: 200,
                    content: '{"candidates":[{"content":{"parts":[{"text":"Alt: «Рыжий кот спит на синем кресле»"}]}}]}',
                );
            },
        );

        self::assertSame(
            'Рыжий кот спит на синем кресле',
            $client->generateImageAlt('Дом', '<p>Выходной.</p>', new AiImageInput('image/png', 'png-bytes')),
        );
        self::assertCount(1, $calls);
        self::assertStringContainsString('/gemini-3.5-flash-lite:generateContent', $calls[0]['url']);
        $body = json_decode((string)$calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $prompt = (string)$body['contents'][0]['parts'][0]['text'];
        self::assertStringContainsString('photograph or illustration with a scene', $prompt);
        self::assertStringContainsString('document, screenshot, interface, diagram, chart, or meme', $prompt);
        self::assertStringContainsString('main readable text or data', $prompt);
        self::assertSame('image/png', $body['contents'][0]['parts'][1]['inline_data']['mime_type']);
        self::assertSame(base64_encode('png-bytes'), $body['contents'][0]['parts'][1]['inline_data']['data']);
        self::assertSame(256, $body['generationConfig']['maxOutputTokens']);
    }

    /** @dataProvider imageCompatibleProviderDataProvider */
    public function testOpenAiCompatibleImageAltUsesProviderSpecificImageShape(
        string $provider,
        string $expectedUrl,
        bool $flatImageUrl,
    ): void {
        $calls = [];
        $client = new AiClient(
            new HttpClient(),
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => $provider,
                AiSettings::API_KEY_CONFIG_KEY => 'provider-secret',
                AiSettings::MODEL_CONFIG_KEY => '',
                AiSettings::FOLDER_ID_CONFIG_KEY => '',
                AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => 'cloudflare-account',
                AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
                AiSettings::AUTO_ALT_CONFIG_KEY => '1',
            ]),
            new ArrayAdapter(),
            static function (string $method, string $url, array $headers, ?string $body, array $options) use (&$calls): HttpResponse {
                $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
                return new HttpResponse(
                    statusCode: 200,
                    content: '{"choices":[{"message":{"content":"Ночной город в дождь"}}]}',
                );
            },
        );

        self::assertSame(
            'Ночной город в дождь',
            $client->generateImageAlt('', '', new AiImageInput('image/jpeg', 'jpeg-bytes')),
        );
        self::assertSame($expectedUrl, $calls[0]['url']);
        $body = json_decode((string)$calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        $imageUrl = $body['messages'][0]['content'][1]['image_url'];
        self::assertSame(
            'data:image/jpeg;base64,' . base64_encode('jpeg-bytes'),
            $flatImageUrl ? $imageUrl : $imageUrl['url'],
        );
        self::assertSame(256, $body['max_tokens']);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function imageCompatibleProviderDataProvider(): iterable
    {
        yield 'OpenRouter' => [
            AiSettings::PROVIDER_OPENROUTER,
            'https://openrouter.ai/api/v1/chat/completions',
            false,
        ];
        yield 'Mistral' => [
            AiSettings::PROVIDER_MISTRAL,
            'https://api.mistral.ai/v1/chat/completions',
            true,
        ];
        yield 'Cloudflare' => [
            AiSettings::PROVIDER_CLOUDFLARE,
            'https://api.cloudflare.com/client/v4/accounts/cloudflare-account/ai/v1/chat/completions',
            false,
        ];
    }

    public function testTagNormalizationDoesNotCorruptCyrillicEndings(): void
    {
        $client = new AiClient(
            new HttpClient(),
            new AiSettings(new DynamicConfigProvider()),
            new ArrayAdapter(),
        );
        $normalizeResult = new \ReflectionMethod($client, 'normalizeResult');

        self::assertSame(
            'разработка игр, инди-игры, геймдев, локальный кооператив, нейросети',
            $normalizeResult->invoke(
                $client,
                AiClient::ACTION_TAGS,
                '• разработка игр •, «инди-игры», #геймдев, локальный кооп, нейросети, отец и дочь',
            ),
        );
    }

    public function testMalformedTagsAreDiscarded(): void
    {
        $client = new AiClient(
            new HttpClient(),
            new AiSettings(new DynamicConfigProvider()),
            new ArrayAdapter(),
        );
        $normalizeResult = new \ReflectionMethod($client, 'normalizeResult');

        self::assertSame(
            'инди-игры, геймдев, нейросети',
            $normalizeResult->invoke(
                $client,
                AiClient::ACTION_TAGS,
                'Теги: разработка иг?, инди-игры, зачем?, геймдев, нейросети',
            ),
        );
    }

    /** @dataProvider openAiCompatibleProviderDataProvider */
    public function testOpenAiCompatibleProvidersUseExpectedEndpointAndDefaultModel(
        string $provider,
        string $expectedUrl,
        string $expectedModel,
    ): void {
        $calls = [];
        $client = new AiClient(
            new HttpClient(),
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => $provider,
                AiSettings::API_KEY_CONFIG_KEY  => 'provider-secret',
                AiSettings::MODEL_CONFIG_KEY    => '',
                AiSettings::FOLDER_ID_CONFIG_KEY => '',
                AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => 'cloudflare-account',
                AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
            ]),
            new ArrayAdapter(),
            static function (
                string  $method,
                string  $url,
                array   $headers,
                ?string $body,
                array   $options,
            ) use (&$calls): HttpResponse {
                $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];

                return new HttpResponse(
                    statusCode: 200,
                    content: '{"choices":[{"message":{"content":"Готовый текст"}}]}',
                );
            },
        );

        self::assertSame('Готовый текст', $client->generate(AiClient::ACTION_IMPROVE, '', 'Исходный текст'));
        self::assertCount(1, $calls);
        self::assertSame('POST', $calls[0]['method']);
        self::assertSame($expectedUrl, $calls[0]['url']);
        self::assertSame('Bearer provider-secret', $calls[0]['headers']['Authorization']);
        self::assertSame('application/json', $calls[0]['headers']['Content-Type']);
        self::assertSame(1_048_576, $calls[0]['options'][HttpClient::MAX_RESPONSE_BYTES]);

        $body = json_decode((string)$calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expectedModel, $body['model']);
        self::assertStringContainsString('Исходный текст', (string)$body['messages'][0]['content']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function openAiCompatibleProviderDataProvider(): iterable
    {
        yield 'OpenRouter free router' => [
            AiSettings::PROVIDER_OPENROUTER,
            'https://openrouter.ai/api/v1/chat/completions',
            'openrouter/free',
        ];
        yield 'Mistral free mode' => [
            AiSettings::PROVIDER_MISTRAL,
            'https://api.mistral.ai/v1/chat/completions',
            'mistral-small-latest',
        ];
        yield 'Cloudflare Workers AI' => [
            AiSettings::PROVIDER_CLOUDFLARE,
            'https://api.cloudflare.com/client/v4/accounts/cloudflare-account/ai/v1/chat/completions',
            '@cf/google/gemma-4-26b-a4b-it',
        ];
    }

    public function testCloudflareRequiresAccountId(): void
    {
        $settings = $this->settings([
            AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_CLOUDFLARE,
            AiSettings::API_KEY_CONFIG_KEY  => 'provider-secret',
            AiSettings::MODEL_CONFIG_KEY    => '',
            AiSettings::FOLDER_ID_CONFIG_KEY => '',
            AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => '',
            AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
        ]);

        self::assertFalse($settings->isConfigured());
    }

    public function testYandexUsesFolderAndOpenAiCompatibleEndpoint(): void
    {
        $calls = [];
        $client = new AiClient(
            new HttpClient(),
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY  => AiSettings::PROVIDER_YANDEX,
                AiSettings::API_KEY_CONFIG_KEY   => 'yandex-secret',
                AiSettings::MODEL_CONFIG_KEY     => '',
                AiSettings::FOLDER_ID_CONFIG_KEY => 'b1g-example-folder',
                AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => '',
                AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
            ]),
            new ArrayAdapter(),
            static function (
                string  $method,
                string  $url,
                array   $headers,
                ?string $body,
                array   $options,
            ) use (&$calls): HttpResponse {
                $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];

                return new HttpResponse(
                    statusCode: 200,
                    content: '{"choices":[{"message":{"content":"Исправленный текст"}}]}',
                );
            },
        );

        self::assertSame(
            'Исправленный текст',
            $client->generate(AiClient::ACTION_PROOFREAD, 'Заголовок', 'Текст с ашибкой.'),
        );
        self::assertCount(1, $calls);
        self::assertSame('POST', $calls[0]['method']);
        self::assertSame('https://ai.api.cloud.yandex.net/v1/chat/completions', $calls[0]['url']);
        self::assertSame('Api-Key yandex-secret', $calls[0]['headers']['Authorization']);
        self::assertSame('b1g-example-folder', $calls[0]['headers']['OpenAI-Project']);
        self::assertSame(1_048_576, $calls[0]['options'][HttpClient::MAX_RESPONSE_BYTES]);

        $body = json_decode((string)$calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('gpt://b1g-example-folder/yandexgpt-5-lite', $body['model']);
        self::assertSame('user', $body['messages'][0]['role']);
        self::assertStringContainsString('Текст с ашибкой.', (string) $body['messages'][0]['content']);
    }

    public function testGigaChatObtainsAndCachesOAuthToken(): void
    {
        $calls = [];
        $chatResponseNumber = 0;
        $client = new AiClient(
            new HttpClient(),
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY  => AiSettings::PROVIDER_GIGACHAT,
                AiSettings::API_KEY_CONFIG_KEY   => 'base64-authorization-key',
                AiSettings::MODEL_CONFIG_KEY     => '',
                AiSettings::FOLDER_ID_CONFIG_KEY => '',
                AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => '',
                AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
            ]),
            new ArrayAdapter(),
            static function (
                string  $method,
                string  $url,
                array   $headers,
                ?string $body,
                array   $options,
            ) use (&$calls, &$chatResponseNumber): HttpResponse {
                $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
                if ($url === 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth') {
                    return new HttpResponse(
                        statusCode: 200,
                        content: json_encode([
                            'access_token' => 'short-lived-token',
                            'expires_at'   => time() + 1800,
                        ], JSON_THROW_ON_ERROR),
                    );
                }

                ++$chatResponseNumber;

                return new HttpResponse(
                    statusCode: 200,
                    content: json_encode([
                        'choices' => [[
                            'message' => ['content' => 'Ответ ' . $chatResponseNumber],
                        ]],
                    ], JSON_THROW_ON_ERROR),
                );
            },
        );

        self::assertSame('Ответ 1', $client->generate(AiClient::ACTION_IMPROVE, '', 'Первый текст'));
        self::assertSame('Ответ 2', $client->generate(AiClient::ACTION_IMPROVE, '', 'Второй текст'));

        self::assertCount(3, $calls, 'The cached token must avoid a second OAuth request.');
        self::assertSame('https://ngw.devices.sberbank.ru:9443/api/v2/oauth', $calls[0]['url']);
        self::assertSame('Basic base64-authorization-key', $calls[0]['headers']['Authorization']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $calls[0]['headers']['RqUID'],
        );
        self::assertSame('scope=GIGACHAT_API_PERS', $calls[0]['body']);
        self::assertSame('https://api.giga.chat/v1/chat/completions', $calls[1]['url']);
        self::assertSame('Bearer short-lived-token', $calls[1]['headers']['Authorization']);
        self::assertSame('https://api.giga.chat/v1/chat/completions', $calls[2]['url']);

        $body = json_decode((string)$calls[1]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('GigaChat-2-Pro', $body['model']);
        self::assertStringContainsString('Первый текст', (string) $body['messages'][0]['content']);
    }

    public function testGigaChatUploadsAttachesAndRemovesImageForAlt(): void
    {
        $calls = [];
        $client = new AiClient(
            new HttpClient(),
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_GIGACHAT,
                AiSettings::API_KEY_CONFIG_KEY => 'base64-authorization-key',
                AiSettings::MODEL_CONFIG_KEY => '',
                AiSettings::FOLDER_ID_CONFIG_KEY => '',
                AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => '',
                AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
                AiSettings::AUTO_ALT_CONFIG_KEY => '1',
            ]),
            new ArrayAdapter(),
            static function (string $method, string $url, array $headers, ?string $body, array $options) use (&$calls): HttpResponse {
                $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
                return match ($url) {
                    'https://ngw.devices.sberbank.ru:9443/api/v2/oauth' => new HttpResponse(
                        statusCode: 200,
                        content: json_encode([
                            'access_token' => 'giga-token',
                            'expires_at' => time() + 1800,
                        ], JSON_THROW_ON_ERROR),
                    ),
                    'https://api.giga.chat/v1/files' => new HttpResponse(
                        statusCode: 200,
                        content: '{"id":"file-123"}',
                    ),
                    'https://api.giga.chat/v1/chat/completions' => new HttpResponse(
                        statusCode: 200,
                        content: '{"choices":[{"message":{"content":"Собака бежит по снегу"}}]}',
                    ),
                    'https://api.giga.chat/v1/files/file-123/delete' => new HttpResponse(statusCode: 200, content: '{}'),
                    default => throw new \LogicException('Unexpected URL ' . $url),
                };
            },
        );

        self::assertSame(
            'Собака бежит по снегу',
            $client->generateImageAlt('', '', new AiImageInput('image/png', 'image-binary')),
        );
        self::assertCount(4, $calls);
        self::assertStringContainsString('multipart/form-data; boundary=', $calls[1]['headers']['Content-Type']);
        self::assertStringContainsString('name="purpose"', (string)$calls[1]['body']);
        self::assertStringContainsString('image-binary', (string)$calls[1]['body']);
        $chatBody = json_decode((string)$calls[2]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['file-123'], $chatBody['messages'][0]['attachments']);
        self::assertSame('https://api.giga.chat/v1/files/file-123/delete', $calls[3]['url']);
    }

    public function testAvailabilityCheckUsesSmallRequestAndCachesSuccess(): void
    {
        $calls = [];
        $client = new AiClient(
            new HttpClient(),
            $this->settings([
                AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_OPENROUTER,
                AiSettings::API_KEY_CONFIG_KEY => 'provider-secret',
                AiSettings::MODEL_CONFIG_KEY => '',
                AiSettings::FOLDER_ID_CONFIG_KEY => '',
                AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => '',
                AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
            ]),
            new ArrayAdapter(),
            static function (string $method, string $url, array $headers, ?string $body, array $options) use (&$calls): HttpResponse {
                $calls[] = compact('method', 'url', 'headers', 'body', 'options');

                return new HttpResponse(
                    statusCode: 200,
                    content: '{"choices":[{"message":{"content":"OK"}}]}',
                );
            },
        );

        $client->checkAvailability();
        $client->checkAvailability();

        self::assertCount(1, $calls);
        $request = json_decode((string)$calls[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(16, $request['max_tokens']);
        self::assertSame('This is a connection check. Reply with the single word OK.', $request['messages'][0]['content']);
    }

    /** @param array<string, string> $values */
    private function settings(array $values): AiSettings
    {
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
