<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\ProtocolLimits;

/** A bounded, context-free structural view of an incoming ActivityStreams activity. */
final readonly class IncomingActivity
{
    /** @param array<string, mixed> $document */
    private function __construct(
        public string $id,
        public string $type,
        public string $actorUrl,
        public array  $document,
    ) {
    }

    public static function fromJson(string $json): self
    {
        if ($json === '' || \strlen($json) > ProtocolLimits::INBOX_BODY_BYTES) {
            throw new \InvalidArgumentException('The ActivityPub inbox body is empty or too large.');
        }

        try {
            $document = json_decode($json, true, ProtocolLimits::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The ActivityPub inbox body is not valid bounded JSON.', 0, $exception);
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new \InvalidArgumentException('The ActivityPub inbox body must be a JSON object.');
        }

        $id   = self::iri($document['id'] ?? null, 'id');
        $type = self::value($document, 'type');
        $actor = self::iri($document['actor'] ?? null, 'actor');
        if (!\is_string($type) || preg_match('/^[A-Za-z][A-Za-z0-9]{0,31}$/D', $type) !== 1) {
            throw new \InvalidArgumentException('The ActivityPub activity type is invalid.');
        }

        return new self($id, $type, $actor, $document);
    }

    public function objectIri(): ?string
    {
        try {
            return self::iri($this->document['object'] ?? null, 'object');
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function targetIri(): ?string
    {
        try {
            return self::iri($this->document['target'] ?? null, 'target');
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public function objectDocument(): ?array
    {
        $object = $this->document['object'] ?? null;
        return \is_array($object) && !array_is_list($object) ? $object : null;
    }

    public function withFetchedObject(string $json): self
    {
        try {
            $object = json_decode($json, true, ProtocolLimits::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The fetched ActivityPub object is invalid JSON.', 0, $exception);
        }

        if (!\is_array($object) || array_is_list($object)) {
            throw new \InvalidArgumentException('The fetched ActivityPub object must be a JSON object.');
        }

        $expectedId = $this->objectIri();
        $actualId   = self::iri($object['id'] ?? null, 'fetched object id');
        if ($expectedId === null || !hash_equals($expectedId, $actualId)) {
            throw new \DomainException('The fetched ActivityPub object id does not match the signed activity reference.');
        }

        $document = $this->document;
        $document['object'] = $object;

        return new self($this->id, $this->type, $this->actorUrl, $document);
    }

    private static function iri(mixed $value, string $field): string
    {
        if (\is_array($value) && !array_is_list($value)) {
            $value = $value['id'] ?? null;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException('The ActivityPub ' . $field . ' must be an HTTPS IRI.');
        }

        $parts = parse_url($value);
        if (\strlen($value) > 2_048
            || !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $value) === 1
        ) {
            throw new \InvalidArgumentException('The ActivityPub ' . $field . ' must be a bounded credential-free HTTPS IRI.');
        }

        return $value;
    }

    /** @param array<mixed> $document */
    private static function value(array $document, string $key): mixed
    {
        return $document[$key] ?? null;
    }
}
