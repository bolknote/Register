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

final class AiClientTest extends TestCase
{
    public function testTagNormalizationDoesNotCorruptCyrillicEndings(): void
    {
        $client = new AiClient(
            new HttpClient(),
            new AiSettings(new DynamicConfigProvider()),
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
}
