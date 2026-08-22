<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Inbox;

use s2_extensions\activitypub\Content\PortableHtmlSanitizer;
use s2_extensions\activitypub\Infrastructure\ValidatedRemoteObject;
use s2_extensions\activitypub\Presentation\ActivityStreamsContext;

/** Validates and minimizes an embedded remote object without dereferencing JSON-LD contexts. */
final readonly class RemoteObjectDocumentValidator
{
    private const int MAX_RECIPIENTS = 128;

    public function __construct(private PortableHtmlSanitizer $htmlSanitizer)
    {
    }

    /** @param array<string, mixed> $document */
    public function validate(array $document, string $expectedActorUrl, int $now): ValidatedRemoteObject
    {
        if ($now < 1) {
            throw new \InvalidArgumentException('A remote ActivityPub object must be a JSON object.');
        }

        $objectUrl = $this->httpsUrl($document['id'] ?? null, true, 'object id');
        $type      = $this->recognizedType($document['type'] ?? null);
        $actorUrl  = $this->attributedActor($document['attributedTo'] ?? null);
        if (!hash_equals($expectedActorUrl, $actorUrl)) {
            throw new \DomainException('The remote ActivityPub object is not attributed to the verified activity actor.');
        }

        $canonicalUrl = $this->canonicalUrl($document['url'] ?? null, $objectUrl);
        $inReplyTo    = isset($document['inReplyTo'])
            ? $this->httpsUrl($document['inReplyTo'], true, 'inReplyTo')
            : null;
        $displayName  = $this->optionalPlainText($document['name'] ?? null, 10_000) ?? '';
        $summary      = $this->optionalPlainText($document['summary'] ?? null, 10_000) ?? '';
        $content      = $this->content($document, $objectUrl);
        if (trim(strip_tags($content)) === '') {
            $fallback = $displayName !== '' ? $displayName : $summary;
            if ($fallback === '') {
                throw new \InvalidArgumentException('The remote ActivityPub object has no usable content.');
            }

            $content = '<p>' . htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '</p>';
        }

        $to  = $this->addresses($document['to'] ?? null, 'to');
        $cc  = $this->addresses($document['cc'] ?? null, 'cc');
        $bto = $this->addresses($document['bto'] ?? null, 'bto');
        $bcc = $this->addresses($document['bcc'] ?? null, 'bcc');
        $recipients = array_values(array_unique([...$to, ...$cc, ...$bto, ...$bcc]));
        if (\count($recipients) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('The remote ActivityPub object has too many recipients.');
        }

        $publishedAt = $this->timestamp($document['published'] ?? null, $now, 'published');
        $updatedAt   = $this->timestamp($document['updated'] ?? null, $publishedAt, 'updated');
        $sensitive   = $document['sensitive'] ?? false;
        if (!\is_bool($sensitive)) {
            throw new \InvalidArgumentException('The remote ActivityPub sensitive flag must be boolean.');
        }

        $sanitized = [
            '@context'     => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'           => $objectUrl,
            'type'         => $type,
            'attributedTo' => $actorUrl,
            'url'          => $canonicalUrl,
            'content'      => $content,
            'mediaType'    => 'text/html',
            'published'    => gmdate('Y-m-d\TH:i:s\Z', $publishedAt),
            'updated'      => gmdate('Y-m-d\TH:i:s\Z', $updatedAt),
            'to'           => $to,
            'cc'           => $cc,
        ];
        if ($inReplyTo !== null) {
            $sanitized['inReplyTo'] = $inReplyTo;
        }

        if ($displayName !== '') {
            $sanitized['name'] = $displayName;
        }

        if ($summary !== '') {
            $sanitized['summary'] = $summary;
        }

        if ($sensitive) {
            $sanitized['sensitive'] = true;
        }

        $tags = $this->tags($document['tag'] ?? null);
        if ($tags !== []) {
            $sanitized['tag'] = $tags;
        }

        $attachments = $this->attachments($document['attachment'] ?? null);
        if ($attachments !== []) {
            $sanitized['attachment'] = $attachments;
        }

        return new ValidatedRemoteObject(
            $objectUrl,
            $type,
            $actorUrl,
            $canonicalUrl,
            $inReplyTo,
            $content,
            $displayName,
            $summary,
            $sensitive,
            $this->visibility($to, $cc, $recipients),
            $recipients,
            $sanitized,
            $publishedAt,
            $updatedAt,
        );
    }

    private function recognizedType(mixed $value): string
    {
        $types = \is_array($value) && array_is_list($value) ? $value : [$value];
        foreach ($types as $type) {
            if (\is_string($type) && \in_array($type, ['Note', 'Article', 'Page'], true)) {
                return $type;
            }
        }

        throw new \DomainException('The remote ActivityPub object type is unsupported.');
    }

    private function attributedActor(mixed $value): string
    {
        $values = \is_array($value) && array_is_list($value) ? $value : [$value];
        if (\count($values) !== 1) {
            throw new \DomainException('Register does not infer remote ActivityPub attribution delegation.');
        }

        return $this->httpsUrl($values[0], true, 'attributedTo');
    }

    private function canonicalUrl(mixed $value, string $fallback): string
    {
        if ($value === null) {
            return $fallback;
        }

        $values = \is_array($value) && array_is_list($value) ? $value : [$value];
        foreach ($values as $candidate) {
            try {
                return $this->httpsUrl($candidate, true, 'canonical URL');
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        throw new \InvalidArgumentException('The remote ActivityPub object canonical URL is invalid.');
    }

    /** @param array<string, mixed> $document */
    private function content(array $document, string $baseUrl): string
    {
        $content = $document['content'] ?? null;
        if (!\is_string($content)) {
            $map = $document['contentMap'] ?? null;
            if (\is_array($map) && !array_is_list($map) && \count($map) <= 8) {
                ksort($map, SORT_STRING);
                foreach ($map as $language => $candidate) {
                    if (\is_string($language)
                        && preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/Di', $language) === 1
                        && \is_string($candidate)
                    ) {
                        $content = $candidate;
                        break;
                    }
                }
            }
        }

        if (!\is_string($content)) {
            return '';
        }

        return $this->htmlSanitizer->sanitize($content, $baseUrl);
    }

    /** @return list<string> */
    private function addresses(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }

        $values = \is_array($value) && array_is_list($value) ? $value : [$value];
        if (\count($values) > self::MAX_RECIPIENTS) {
            throw new \InvalidArgumentException('The remote ActivityPub ' . $field . ' recipient list is too large.');
        }

        $result = [];
        foreach ($values as $candidate) {
            $url = $this->httpsUrl($candidate, true, $field . ' recipient');
            $result[$url] = $url;
        }

        return array_values($result);
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $all
     */
    private function visibility(array $to, array $cc, array $all): string
    {
        if (\in_array(ActivityStreamsContext::PUBLIC_COLLECTION, $to, true)) {
            return 'public';
        }

        if (\in_array(ActivityStreamsContext::PUBLIC_COLLECTION, $cc, true)) {
            return 'unlisted';
        }

        foreach ($all as $recipient) {
            if (str_ends_with(rtrim($recipient, '/'), '/followers')) {
                return 'followers';
            }
        }

        return 'direct';
    }

    /** @return list<array<string, mixed>> */
    private function tags(mixed $value): array
    {
        $values = $value === null ? [] : (\is_array($value) && array_is_list($value) ? $value : [$value]);
        if (\count($values) > 64) {
            throw new \InvalidArgumentException('The remote ActivityPub object has too many tags.');
        }

        $result = [];
        foreach ($values as $tag) {
            if (!\is_array($tag) || array_is_list($tag)) {
                continue;
            }

            $type = $tag['type'] ?? null;
            $name = $this->optionalPlainText($tag['name'] ?? null, 255);
            if (!\is_string($type) || !\in_array($type, ['Mention', 'Hashtag', 'Emoji'], true) || $name === null) {
                continue;
            }

            $normalized = ['type' => $type, 'name' => $name];
            if (isset($tag['href'])) {
                $normalized['href'] = $this->httpsUrl($tag['href'], true, 'tag href');
            }

            $result[] = $normalized;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function attachments(mixed $value): array
    {
        $values = $value === null ? [] : (\is_array($value) && array_is_list($value) ? $value : [$value]);
        if (\count($values) > 16) {
            throw new \InvalidArgumentException('The remote ActivityPub object has too many attachments.');
        }

        $result = [];
        foreach ($values as $attachment) {
            if (!\is_array($attachment) || array_is_list($attachment)) {
                continue;
            }

            $url = $attachment['url'] ?? null;
            if (\is_array($url) && array_is_list($url)) {
                $url = $url[0] ?? null;
            }

            $mediaType = $this->optionalPlainText($attachment['mediaType'] ?? null, 255);
            try {
                $normalizedUrl = $this->httpsUrl($url, true, 'attachment URL');
            } catch (\InvalidArgumentException) {
                continue;
            }

            $normalized = ['type' => 'Document', 'url' => $normalizedUrl];
            if ($mediaType !== null) {
                $normalized['mediaType'] = $mediaType;
            }

            $name = $this->optionalPlainText($attachment['name'] ?? null, 2_000);
            if ($name !== null) {
                $normalized['name'] = $name;
            }

            $result[] = $normalized;
        }

        return $result;
    }

    private function optionalPlainText(mixed $value, int $maxCharacters): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value)
            || !mb_check_encoding($value, 'UTF-8')
            || \strlen($value) > $maxCharacters * 4
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1
        ) {
            throw new \InvalidArgumentException('A remote ActivityPub text field is invalid.');
        }

        return mb_substr(trim(strip_tags($value)), 0, $maxCharacters);
    }

    private function timestamp(mixed $value, int $fallback, string $field): int
    {
        if ($value === null) {
            return $fallback;
        }

        if (!\is_string($value)
            || \strlen($value) > 64
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException('The remote ActivityPub ' . $field . ' timestamp is invalid.');
        }

        $timestamp = strtotime($value);
        if ($timestamp === false || $timestamp < 0) {
            throw new \InvalidArgumentException('The remote ActivityPub ' . $field . ' timestamp is invalid.');
        }

        return $timestamp;
    }

    private function httpsUrl(mixed $value, bool $allowFragment, string $field): string
    {
        if (\is_array($value) && !array_is_list($value)) {
            $value = $value['id'] ?? $value['href'] ?? null;
        }

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
}
