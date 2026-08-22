<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Inbox;

use s2_extensions\activitypub\Domain\ProtocolLimits;
use s2_extensions\activitypub\Infrastructure\FetchedRemoteActor;
use s2_extensions\activitypub\Presentation\CanonicalJson;
use s2_extensions\activitypub\Security\RsaCrypto;

/** Strictly validates the actor subset Register consumes; JSON-LD contexts are never fetched. */
final readonly class RemoteActorDocumentValidator
{
    private const int CACHE_SECONDS = 6 * 60 * 60;

    public function __construct(private RsaCrypto $rsaCrypto, private CanonicalJson $canonicalJson)
    {
    }

    public function validate(string $expectedActorUrl, string $expectedKeyId, string $json, int $now): FetchedRemoteActor
    {
        return $this->validateDocument($expectedActorUrl, $expectedKeyId, $json, $now);
    }

    public function validateForDiscovery(string $expectedActorUrl, string $json, int $now): FetchedRemoteActor
    {
        return $this->validateDocument($expectedActorUrl, null, $json, $now);
    }

    private function validateDocument(
        string  $expectedActorUrl,
        ?string $expectedKeyId,
        string  $json,
        int     $now,
    ): FetchedRemoteActor
    {
        if ($json === '' || \strlen($json) > ProtocolLimits::ACTOR_DOCUMENT_BYTES || $now < 1) {
            throw new \InvalidArgumentException('The remote ActivityPub actor document is empty or too large.');
        }

        try {
            $document = json_decode($json, true, ProtocolLimits::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The remote ActivityPub actor document is invalid JSON.', 0, $exception);
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new \InvalidArgumentException('The remote ActivityPub actor document must be a JSON object.');
        }

        $actorUrl = $this->httpsUrl($document['id'] ?? null, true, 'actor id');
        if (!hash_equals($expectedActorUrl, $actorUrl)) {
            throw new \InvalidArgumentException('The fetched ActivityPub actor id does not match the signed activity actor.');
        }

        $actorType = $this->value($document, 'type');
        if (!\is_string($actorType)
            || !\in_array($actorType, ['Person', 'Service', 'Organization', 'Application', 'Group'], true)
        ) {
            throw new \InvalidArgumentException('The remote ActivityPub actor type is unsupported.');
        }

        $preferredUsername = $this->plainText($document['preferredUsername'] ?? null, 255, 'preferredUsername');
        $displayName = $this->optionalPlainText($document['name'] ?? null, 255) ?? $preferredUsername;
        $inboxUrl   = $this->httpsUrl($document['inbox'] ?? null, false, 'inbox');
        $sharedInboxUrl = null;
        $endpoints = $document['endpoints'] ?? null;
        if (\is_array($endpoints) && !array_is_list($endpoints) && isset($endpoints['sharedInbox'])) {
            $sharedInboxUrl = $this->httpsUrl($endpoints['sharedInbox'], false, 'sharedInbox');
        }

        [$publicKeyId, $publicKeyPem] = $this->publicKey($document['publicKey'] ?? null, $actorUrl, $expectedKeyId);
        $alsoKnownAs = $this->alsoKnownAs($document['alsoKnownAs'] ?? null);
        $avatarUrl   = $this->avatarUrl($document['icon'] ?? null);
        $featuredUrl = isset($document['featured'])
            ? $this->httpsUrl($document['featured'], true, 'featured collection')
            : null;
        $movedToUrl  = isset($document['movedTo'])
            ? $this->httpsUrl($document['movedTo'], true, 'movedTo')
            : null;
        if ($movedToUrl !== null && hash_equals($actorUrl, $movedToUrl)) {
            throw new \InvalidArgumentException('The remote ActivityPub actor cannot move to itself.');
        }

        $snapshot    = $this->canonicalJson->encode($document);

        return new FetchedRemoteActor(
            $actorUrl,
            $actorType,
            $preferredUsername,
            $displayName,
            $inboxUrl,
            $sharedInboxUrl,
            $publicKeyId,
            $publicKeyPem,
            $alsoKnownAs,
            $snapshot,
            hash('sha256', $snapshot),
            $now,
            $now + self::CACHE_SECONDS,
            $movedToUrl,
            $avatarUrl,
            $featuredUrl,
        );
    }

    /** @return array{string, string} */
    private function publicKey(mixed $value, string $actorUrl, ?string $expectedKeyId): array
    {
        $candidates = \is_array($value) && array_is_list($value) ? $value : [$value];
        if (\count($candidates) > 8) {
            throw new \InvalidArgumentException('The remote ActivityPub actor publishes too many public keys.');
        }

        foreach ($candidates as $candidate) {
            if (!\is_array($candidate) || array_is_list($candidate)) {
                continue;
            }

            try {
                $keyId = $this->httpsUrl($candidate['id'] ?? null, true, 'public key id');
                if ($expectedKeyId !== null && !hash_equals($expectedKeyId, $keyId)) {
                    continue;
                }

                $owner = $this->httpsUrl($candidate['owner'] ?? null, true, 'public key owner');
                $pem   = $candidate['publicKeyPem'] ?? null;
                if (!hash_equals($actorUrl, $owner) || !\is_string($pem) || \strlen($pem) > 16_384) {
                    throw new \InvalidArgumentException('The remote ActivityPub public key owner or encoding is invalid.');
                }

                return [$keyId, $this->rsaCrypto->normalizePublicKey($pem)];
            } catch (\InvalidArgumentException $exception) {
                if ($expectedKeyId !== null) {
                    throw $exception;
                }
            }
        }

        throw new \InvalidArgumentException($expectedKeyId === null
            ? 'The ActivityPub actor document publishes no usable owned RSA public key.'
            : 'The signed ActivityPub key is not authorized by the activity actor document.');
    }

    /** @return list<string> */
    private function alsoKnownAs(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = \is_array($value) ? $value : [$value];
        if (\count($values) > 16) {
            throw new \InvalidArgumentException('The remote ActivityPub actor publishes too many aliases.');
        }

        $result = [];
        foreach ($values as $alias) {
            $url = $this->httpsUrl($alias, true, 'actor alias');
            $result[$url] = $url;
        }

        ksort($result, SORT_STRING);

        return array_values($result);
    }

    private function avatarUrl(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $candidates = \is_array($value) && array_is_list($value) ? $value : [$value];
        if (\count($candidates) > 8) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (\is_string($candidate)) {
                try {
                    return $this->httpsUrl($candidate, false, 'avatar');
                } catch (\InvalidArgumentException) {
                    continue;
                }
            }

            if (!\is_array($candidate) || array_is_list($candidate)) {
                continue;
            }

            $type = $candidate['type'] ?? 'Image';
            if (!\is_string($type) || !\in_array($type, ['Image', 'Document'], true)) {
                continue;
            }

            $urls = $candidate['url'] ?? null;
            $urls = \is_array($urls) && array_is_list($urls) ? $urls : [$urls];
            foreach (array_slice($urls, 0, 8) as $url) {
                if (\is_array($url) && !array_is_list($url)) {
                    $url = $url['href'] ?? null;
                }

                try {
                    return $this->httpsUrl($url, false, 'avatar');
                } catch (\InvalidArgumentException) {
                    // An optional unusable icon must not make an otherwise valid actor undiscoverable.
                }
            }
        }

        return null;
    }

    private function plainText(mixed $value, int $limit, string $field): string
    {
        $text = $this->optionalPlainText($value, $limit);
        if ($text === null || $text === '') {
            throw new \InvalidArgumentException('The remote ActivityPub ' . $field . ' is missing or invalid.');
        }

        return $text;
    }

    private function optionalPlainText(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value)
            || \strlen($value) > $limit
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            throw new \InvalidArgumentException('A remote ActivityPub actor text field is invalid.');
        }

        return trim(strip_tags($value));
    }

    private function httpsUrl(mixed $value, bool $allowFragment, string $field): string
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('The remote ActivityPub ' . $field . ' must be an HTTPS URL.');
        }

        $parts = parse_url($value);
        if (\strlen($value) > 2_048
            || !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || (!$allowFragment && isset($parts['fragment']))
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
        ) {
            throw new \InvalidArgumentException('The remote ActivityPub ' . $field . ' must be bounded credential-free HTTPS.');
        }

        return $value;
    }

    /** @param array<mixed> $document */
    private function value(array $document, string $key): mixed
    {
        return $document[$key] ?? null;
    }
}
