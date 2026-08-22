<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\LinkHealth;

use Codeception\Test\Unit;
use Register\Core\HttpClient\Remote\DnsMessageCodec;
use Register\Core\HttpClient\Remote\DnsResponseStatus;

final class DnsMessageCodecTest extends Unit
{
    public function testDecodesAddressForTheExactQuestion(): void
    {
        $codec = new DnsMessageCodec();
        $id    = 0x1234;
        $query = $codec->createQuery('example.test', DnsMessageCodec::TYPE_A, $id);
        $packedAddress = inet_pton('93.184.216.34');
        self::assertIsString($packedAddress);
        $response = pack('nnnnnn', $id, 0x8180, 1, 1, 0, 0)
            . substr($query, 12)
            . "\xc0\x0c"
            . pack('nnNn', DnsMessageCodec::TYPE_A, 1, 60, 4)
            . $packedAddress;

        $decoded = $codec->decodeResponse($response, $id, 'example.test', DnsMessageCodec::TYPE_A);

        self::assertSame(DnsResponseStatus::ANSWER, $decoded->status);
        self::assertSame(['93.184.216.34'], $decoded->addresses);
    }

    public function testFollowsOnlyTheQuestionCnameChain(): void
    {
        $codec = new DnsMessageCodec();
        $id    = 0x2345;
        $query = $codec->createQuery('old.example.test', DnsMessageCodec::TYPE_A, $id);
        $canonicalName = "\x03new\xc0\x10";
        $packedAddress = inet_pton('93.184.216.35');
        self::assertIsString($packedAddress);
        $response = pack('nnnnnn', $id, 0x8180, 1, 2, 0, 0)
            . substr($query, 12)
            . "\xc0\x0c"
            . pack('nnNn', 5, 1, 60, \strlen($canonicalName))
            . $canonicalName
            . $canonicalName
            . pack('nnNn', DnsMessageCodec::TYPE_A, 1, 60, 4)
            . $packedAddress;

        $decoded = $codec->decodeResponse($response, $id, 'old.example.test', DnsMessageCodec::TYPE_A);

        self::assertSame(['93.184.216.35'], $decoded->addresses);
    }

    public function testTreatsAuthoritativeNameErrorAsAnEmptyAnswer(): void
    {
        $codec = new DnsMessageCodec();
        $id    = 0x3456;
        $query = $codec->createQuery('missing.example', DnsMessageCodec::TYPE_AAAA, $id);
        $response = pack('nnnnnn', $id, 0x8183, 1, 0, 0, 0) . substr($query, 12);

        $decoded = $codec->decodeResponse($response, $id, 'missing.example', DnsMessageCodec::TYPE_AAAA);

        self::assertSame(DnsResponseStatus::EMPTY, $decoded->status);
        self::assertSame([], $decoded->addresses);
    }

    public function testRejectsAResponseForAnotherQuestion(): void
    {
        $codec = new DnsMessageCodec();
        $query = $codec->createQuery('example.test', DnsMessageCodec::TYPE_A, 1);
        $response = pack('nnnnnn', 2, 0x8180, 1, 0, 0, 0) . substr($query, 12);

        $this->expectException(\UnexpectedValueException::class);
        $codec->decodeResponse($response, 1, 'example.test', DnsMessageCodec::TYPE_A);
    }
}
