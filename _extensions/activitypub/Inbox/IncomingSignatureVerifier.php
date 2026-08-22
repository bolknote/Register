<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Inbox;

use Register\Extension\activitypub\Application\IncomingActivity;
use Register\Extension\activitypub\Domain\RemoteActor;
use Register\Extension\activitypub\Infrastructure\ClaimedInboxItem;
use Register\Extension\activitypub\Security\HttpSignatureRequest;
use Register\Extension\activitypub\Security\LegacyHttpSignature;
use Register\Extension\activitypub\Security\Rfc9421HttpSignature;
use Register\Extension\activitypub\Security\SignatureVerificationFailed;
use Register\Extension\activitypub\Security\VerifiedHttpSignature;

final readonly class IncomingSignatureVerifier
{
    public function __construct(
        private LegacyHttpSignature  $legacySignature,
        private Rfc9421HttpSignature $rfc9421Signature,
    ) {
    }

    public function verify(
        ClaimedInboxItem $item,
        IncomingActivity $activity,
        RemoteActor      $actor,
        int              $now,
    ): VerifiedHttpSignature {
        if (!hash_equals($item->bodyHash, hash('sha256', $item->rawBody))) {
            throw new \RuntimeException('The immutable ActivityPub inbox body hash no longer matches.');
        }

        if (!hash_equals($item->actorUrl, $activity->actorUrl)
            || !hash_equals($actor->actorUrl, $activity->actorUrl)
        ) {
            throw new SignatureVerificationFailed('The signed ActivityPub actor does not own the inbox activity.');
        }

        [$targetUri, $headers] = $this->transport($item->transportJson);
        $request = new HttpSignatureRequest('POST', $targetUri, $headers, $item->rawBody);
        if ($item->signatureType === 'legacy') {
            $signature = $headers['Signature'] ?? '';
            $verified  = $this->legacySignature->verify($request, $signature, $actor->publicKeyPem, $now);
        } elseif ($item->signatureType === 'rfc9421') {
            $verified = $this->rfc9421Signature->verify(
                $request,
                $headers['Signature-Input'] ?? '',
                $headers['Signature'] ?? '',
                $actor->publicKeyPem,
                $now,
                $item->keyId,
            );
        } else {
            throw new SignatureVerificationFailed('The stored ActivityPub signature type is unsupported.');
        }

        if (!hash_equals($actor->publicKeyId, $verified->keyId)) {
            throw new SignatureVerificationFailed('The verified ActivityPub key is not authorized by the activity actor.');
        }

        return $verified;
    }

    /** @return array{string, array<string, string>} */
    private function transport(string $json): array
    {
        try {
            $transport = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The stored ActivityPub transport evidence is invalid JSON.', 0, $exception);
        }

        if (!\is_array($transport) || array_is_list($transport)) {
            throw new \RuntimeException('The stored ActivityPub transport evidence is invalid.');
        }

        $method = $this->value($transport, 'method');
        $targetUri = $this->value($transport, 'target_uri');
        $storedHeaders = $this->value($transport, 'headers');
        if ($method !== 'POST'
            || !\is_string($targetUri)
            || !\is_array($storedHeaders)
            || array_is_list($storedHeaders)
        ) {
            throw new \RuntimeException('The stored ActivityPub transport evidence is invalid.');
        }

        $headers = [];
        foreach ($storedHeaders as $name => $value) {
            if (!\is_string($name) || !\is_string($value)) {
                throw new \RuntimeException('The stored ActivityPub transport header is invalid.');
            }

            $headers[$name] = $value;
        }

        return [$targetUri, $headers];
    }

    /** @param array<mixed> $transport */
    private function value(array $transport, string $key): mixed
    {
        return $transport[$key] ?? null;
    }
}
