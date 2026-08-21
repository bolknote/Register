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
use Register\Ai\AiSettings;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\HttpClient\HttpResponse;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AiClientTest extends TestCase
{
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
