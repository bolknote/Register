<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use Register\Extension\activitypub\Security\HttpSignatureKind;
use Register\Extension\activitypub\Security\HttpSignatureRequest;
use Register\Extension\activitypub\Security\LegacyHttpSignature;
use Register\Extension\activitypub\Security\RsaCrypto;
use Register\Extension\activitypub\Security\SignatureVerificationFailed;

final class LegacyHttpSignatureTest extends Unit
{
    public function testSignsAndVerifiesFediversePostProfile(): void
    {
        $crypto   = new RsaCrypto();
        $keyPair  = $crypto->generateKeyPair();
        $signature = new LegacyHttpSignature($crypto);
        $body     = '{"type":"Create"}';
        $request  = new HttpSignatureRequest(
            'POST',
            'https://Social.Example:443/users/alice/inbox?shared=true',
            ['Content-Type' => 'application/activity+json'],
            $body,
        );
        $signed = $signature->sign(
            $request,
            'https://blog.example/activitypub/keys/Abcdefghijklmnopqrstu_',
            $keyPair->privateKeyPem,
            1_700_000_000,
        );

        self::assertSame(
            "(request-target): post /users/alice/inbox?shared=true\n"
            . "host: social.example\n"
            . "date: Tue, 14 Nov 2023 22:13:20 GMT\n"
            . 'digest: SHA-256=' . base64_encode(hash('sha256', $body, true)),
            $signed->signatureBase,
        );
        self::assertSame('social.example', $signed->headers['Host']);
        self::assertStringContainsString('algorithm="rsa-sha256"', $signed->headers['Signature']);
        self::assertStringContainsString('headers="(request-target) host date digest"', $signed->headers['Signature']);

        $verified = $signature->verify(
            $request->withHeaders($signed->headers),
            $signed->headers['Signature'],
            $keyPair->publicKeyPem,
            1_700_000_240,
        );
        self::assertSame(HttpSignatureKind::LEGACY, $verified->kind);
        self::assertSame('https://blog.example/activitypub/keys/Abcdefghijklmnopqrstu_', $verified->keyId);
        self::assertSame(['(request-target)', 'host', 'date', 'digest'], $verified->coveredComponents);
        self::assertSame(1_700_000_000, $verified->createdAt);
    }

    public function testRejectsBodyTamperingBeforeRsaVerification(): void
    {
        [$signature, $request, $headers, $publicKey] = $this->signedRequest();
        $tampered = new HttpSignatureRequest(
            $request->method,
            $request->targetUri,
            $headers,
            $request->body . ' ',
        );

        $this->expectException(SignatureVerificationFailed::class);
        $this->expectExceptionMessage('body digest');
        $signature->verify($tampered, $headers['Signature'], $publicKey, 1_700_000_001);
    }

    public function testRejectsInsufficientCoverageEvenWithValidRsaSignature(): void
    {
        [$signature, $request, $headers, $publicKey] = $this->signedRequest();
        $headers['Signature'] = preg_replace(
            '/headers="[^"]+"/',
            'headers="(request-target) host date"',
            $headers['Signature'],
        ) ?? '';

        $this->expectException(SignatureVerificationFailed::class);
        $this->expectExceptionMessage('required components');
        $signature->verify($request->withHeaders($headers), $headers['Signature'], $publicKey, 1_700_000_001);
    }

    public function testRejectsReplayOutsideClockWindow(): void
    {
        [$signature, $request, $headers, $publicKey] = $this->signedRequest();

        $this->expectException(SignatureVerificationFailed::class);
        $this->expectExceptionMessage('clock window');
        $signature->verify($request->withHeaders($headers), $headers['Signature'], $publicKey, 1_700_000_301);
    }

    /** @return array{LegacyHttpSignature, HttpSignatureRequest, array<string, string>, string} */
    private function signedRequest(): array
    {
        $crypto    = new RsaCrypto();
        $keyPair   = $crypto->generateKeyPair();
        $signature = new LegacyHttpSignature($crypto);
        $request   = new HttpSignatureRequest(
            'POST',
            'https://social.example/inbox',
            ['Content-Type' => 'application/activity+json'],
            '{"type":"Follow"}',
        );
        $signed = $signature->sign(
            $request,
            'https://blog.example/activitypub/keys/Abcdefghijklmnopqrstu_',
            $keyPair->privateKeyPem,
            1_700_000_000,
        );

        return [$signature, $request, $signed->headers, $keyPair->publicKeyPem];
    }
}
