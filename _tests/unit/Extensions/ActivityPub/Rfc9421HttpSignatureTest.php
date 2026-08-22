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
use Register\Extension\activitypub\Security\Rfc9421HttpSignature;
use Register\Extension\activitypub\Security\RsaCrypto;
use Register\Extension\activitypub\Security\SignatureVerificationFailed;

final class Rfc9421HttpSignatureTest extends Unit
{
    public function testSignsAndVerifiesRfc9421AndRfc9530Profile(): void
    {
        $crypto    = new RsaCrypto();
        $keyPair   = $crypto->generateKeyPair();
        $signature = new Rfc9421HttpSignature($crypto);
        $body      = '{"type":"Create"}';
        $request   = new HttpSignatureRequest(
            'POST',
            'https://social.example/inbox?shared=true',
            ['Content-Type' => 'application/activity+json'],
            $body,
        );
        $signed = $signature->sign(
            $request,
            'https://blog.example/activitypub/keys/Abcdefghijklmnopqrstu_',
            $keyPair->privateKeyPem,
            1_700_000_000,
        );
        $digest = 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';

        self::assertSame($digest, $signed->headers['Content-Digest']);
        self::assertSame(
            'sig1=("@method" "@target-uri" "content-digest" "content-type")'
            . ';created=1700000000;expires=1700000300'
            . ';keyid="https://blog.example/activitypub/keys/Abcdefghijklmnopqrstu_"'
            . ';alg="rsa-v1_5-sha256"',
            $signed->headers['Signature-Input'],
        );
        self::assertSame(
            "\"@method\": POST\n"
            . "\"@target-uri\": https://social.example/inbox?shared=true\n"
            . "\"content-digest\": {$digest}\n"
            . "\"content-type\": application/activity+json\n"
            . '"@signature-params": ("@method" "@target-uri" "content-digest" "content-type")'
            . ';created=1700000000;expires=1700000300'
            . ';keyid="https://blog.example/activitypub/keys/Abcdefghijklmnopqrstu_"'
            . ';alg="rsa-v1_5-sha256"',
            $signed->signatureBase,
        );

        $verified = $signature->verify(
            $request->withHeaders($signed->headers),
            $signed->headers['Signature-Input'],
            $signed->headers['Signature'],
            $keyPair->publicKeyPem,
            1_700_000_240,
        );
        self::assertSame(HttpSignatureKind::RFC_9421, $verified->kind);
        self::assertSame('sig1', $verified->label);
        self::assertSame(1_700_000_300, $verified->expiresAt);
        self::assertSame(
            ['@method', '@target-uri', 'content-digest', 'content-type'],
            $verified->coveredComponents,
        );
    }

    public function testRejectsTargetUriTampering(): void
    {
        [$signature, $request, $headers, $publicKey] = $this->signedRequest();
        $tampered = new HttpSignatureRequest(
            $request->method,
            'https://social.example/other-inbox',
            $request->withHeaders($headers)->headers(),
            $request->body,
        );

        $this->expectException(SignatureVerificationFailed::class);
        $this->expectExceptionMessage('cryptographically invalid');
        $signature->verify(
            $tampered,
            $headers['Signature-Input'],
            $headers['Signature'],
            $publicKey,
            1_700_000_001,
        );
    }

    public function testRejectsContentDigestTampering(): void
    {
        [$signature, $request, $headers, $publicKey] = $this->signedRequest();
        $tampered = new HttpSignatureRequest(
            $request->method,
            $request->targetUri,
            $request->withHeaders($headers)->headers(),
            $request->body . ' ',
        );

        $this->expectException(SignatureVerificationFailed::class);
        $this->expectExceptionMessage('body digest');
        $signature->verify(
            $tampered,
            $headers['Signature-Input'],
            $headers['Signature'],
            $publicKey,
            1_700_000_001,
        );
    }

    public function testSupportsMultipleLabelsAndDictionaryOrderIndependence(): void
    {
        [$signature, $request, $headers, $publicKey] = $this->signedRequest();
        $unsupportedInput = 'old=("@method");created=1700000000'
            . ';keyid="https://blog.example/old-key";alg="rsa-pss-sha512"';
        $signatureInput = $unsupportedInput . ', ' . $headers['Signature-Input'];
        // RFC dictionaries are unordered, so the Signature field may serialize labels differently.
        $signatureHeader = $headers['Signature'] . ', old=:AA==:';

        $verified = $signature->verify(
            $request->withHeaders($headers),
            $signatureInput,
            $signatureHeader,
            $publicKey,
            1_700_000_001,
        );
        self::assertSame('sig1', $verified->label);
    }

    public function testPreflightRejectsTwoValidCandidatesWithDifferentKeyIds(): void
    {
        $crypto = new RsaCrypto();
        $keyPair = $crypto->generateKeyPair();
        $signature = new Rfc9421HttpSignature($crypto);
        $request = new HttpSignatureRequest(
            'POST',
            'https://social.example/inbox',
            ['Content-Type' => 'application/activity+json'],
            '{"type":"Follow"}',
        );
        $first = $signature->sign(
            $request,
            'https://blog.example/keys/first',
            $keyPair->privateKeyPem,
            1_700_000_000,
        );
        $second = $signature->sign(
            $request,
            'https://blog.example/keys/second',
            $keyPair->privateKeyPem,
            1_700_000_000,
        );
        $signatureInput = $first->headers['Signature-Input'] . ', '
            . 'sig2=' . substr($second->headers['Signature-Input'], \strlen('sig1='));
        $signatureHeader = $first->headers['Signature'] . ', '
            . 'sig2=' . substr($second->headers['Signature'], \strlen('sig1='));

        $this->expectException(SignatureVerificationFailed::class);
        $this->expectExceptionMessage('more than one key');
        $signature->selectCandidateKeyId(
            $request->withHeaders($first->headers),
            $signatureInput,
            $signatureHeader,
            1_700_000_001,
        );
    }

    public function testRejectsExpiredCreationWindow(): void
    {
        [$signature, $request, $headers, $publicKey] = $this->signedRequest();

        $this->expectException(SignatureVerificationFailed::class);
        $this->expectExceptionMessage('creation window');
        $signature->verify(
            $request->withHeaders($headers),
            $headers['Signature-Input'],
            $headers['Signature'],
            $publicKey,
            1_700_000_301,
        );
    }

    /** @return array{Rfc9421HttpSignature, HttpSignatureRequest, array<string, string>, string} */
    private function signedRequest(): array
    {
        $crypto    = new RsaCrypto();
        $keyPair   = $crypto->generateKeyPair();
        $signature = new Rfc9421HttpSignature($crypto);
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
