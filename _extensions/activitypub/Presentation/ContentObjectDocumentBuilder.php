<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Presentation;

use Register\Content\ContentDetails;
use Register\Content\Tag;
use s2_extensions\activitypub\Content\ContentAttachmentExtractor;
use s2_extensions\activitypub\Content\PortableHtmlSanitizer;
use s2_extensions\activitypub\Domain\ContentDeliveryMode;
use s2_extensions\activitypub\Domain\FederationUrlGenerator;
use s2_extensions\activitypub\Domain\LocalActor;

/** Builds the portable, recipient-independent snapshot served and embedded in activities. */
final readonly class ContentObjectDocumentBuilder
{
    private const int FALLBACK_EXCERPT_CHARACTERS = 600;

    public function __construct(
        private PortableHtmlSanitizer $htmlSanitizer,
        private string                $siteLanguage,
        private ?ContentAttachmentExtractor $attachmentExtractor = null,
    ) {
    }

    /**
     * @param list<string> $additionalFollowerCollections
     * @return array<string, mixed>
     */
    public function build(
        ContentDetails       $details,
        LocalActor           $actor,
        FederationUrlGenerator $urls,
        string               $objectPublicId,
        string               $objectType,
        string               $visibility,
        ContentDeliveryMode  $deliveryMode,
        int                  $publishedAt,
        int                  $updatedAt,
        array                $additionalFollowerCollections = [],
        string               $summary = '',
        ?string              $language = null,
    ): array {
        if (!\in_array($objectType, ['Article', 'Note', 'Page'], true)
            || !\in_array($visibility, ['public', 'unlisted'], true)
        ) {
            throw new \InvalidArgumentException('The ActivityPub content projection profile is invalid.');
        }

        $content      = $details->content;
        $canonicalUrl = $urls->resource($content->path);
        $actorUrl     = $urls->actor($actor->publicId);
        $followers = $this->followerCollections(
            $urls->actorFollowers($actor->publicId),
            $additionalFollowerCollections,
        );
        $portableContent = $deliveryMode === ContentDeliveryMode::FULL
            ? $this->htmlSanitizer->sanitize($content->body, $canonicalUrl)
            : $this->excerpt($details, $canonicalUrl);

        if ($objectType === 'Note' && trim($content->title) !== '') {
            $portableContent = '<p><strong>' . $this->escapePlainText($content->title) . '</strong></p>' . $portableContent;
        }

        if (trim(strip_tags($portableContent)) === '') {
            $portableContent = '<p>' . $this->escapePlainText($content->title) . '</p>';
        }

        $mentions = $this->mentions($portableContent);
        $mentionUrls = array_column($mentions, 'href');
        [$to, $cc] = $visibility === 'public'
            ? [[ActivityStreamsContext::PUBLIC_COLLECTION, ...$mentionUrls], $followers]
            : [[...$followers, ...$mentionUrls], [ActivityStreamsContext::PUBLIC_COLLECTION]];
        $to = array_values(array_unique($to));
        $cc = array_values(array_unique($cc));

        $document = [
            '@context'     => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'           => $urls->object($objectPublicId),
            'type'         => $objectType,
            'attributedTo' => $actorUrl,
            'url'          => $canonicalUrl,
            'content'      => $portableContent,
            'mediaType'    => 'text/html',
            'published'    => $this->date($publishedAt),
            'updated'      => $this->date($updatedAt),
            'to'           => $to,
            'cc'           => $cc,
            'audience'     => $followers,
            'replies'      => $urls->objectReplies($objectPublicId),
        ];
        if ($objectType !== 'Note') {
            $document['name'] = $this->plainText($content->title, 10_000);
        }

        $summary = $this->plainText($summary, 500);
        if ($summary !== '') {
            $document['summary'] = $summary;
        }

        $languageTag = $this->languageTag($language);
        if ($languageTag !== null) {
            $document['contentMap'] = [$languageTag => $portableContent];
            if ($summary !== '') {
                $document['summaryMap'] = [$languageTag => $summary];
            }
        }

        $tags = [...array_values(array_filter(array_map($this->tag(...), $details->tags))), ...$mentions];
        if ($tags !== []) {
            $document['tag'] = $tags;
        }

        $attachments = $this->attachmentExtractor?->extract($content->body, $canonicalUrl) ?? [];
        if ($attachments !== []) {
            $document['attachment'] = $attachments;
        }

        return $document;
    }

    /**
     * @param list<string> $additional
     * @return non-empty-list<string>
     */
    private function followerCollections(string $ownerFollowers, array $additional): array
    {
        $collections = [$ownerFollowers => $ownerFollowers];
        foreach ($additional as $url) {
            $parts = parse_url($url);
            if (\strlen($url) > 2_048
                || !\is_array($parts)
                || strtolower($parts['scheme'] ?? '') !== 'https'
                || !\is_string($parts['host'] ?? null)
                || $parts['host'] === ''
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                throw new \InvalidArgumentException('An additional ActivityPub follower collection is invalid.');
            }

            $collections[$url] = $url;
        }

        return array_values($collections);
    }

    private function excerpt(ContentDetails $details, string $canonicalUrl): string
    {
        $content = $details->content;
        if (trim($content->excerpt) !== '') {
            $excerpt = $this->htmlSanitizer->sanitize($content->excerpt, $canonicalUrl);
        } elseif (trim($content->description) !== '') {
            $excerpt = '<p>' . $this->escapePlainText($content->description) . '</p>';
        } else {
            $plain = html_entity_decode(strip_tags($content->body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plain = preg_replace('/\s+/u', ' ', trim($plain)) ?? '';
            if (mb_strlen($plain) > self::FALLBACK_EXCERPT_CHARACTERS) {
                $plain = rtrim(mb_substr($plain, 0, self::FALLBACK_EXCERPT_CHARACTERS - 1)) . '…';
            }

            $excerpt = '<p>' . $this->escapePlainText($plain) . '</p>';
        }

        return $excerpt . '<p><a href="' . htmlspecialchars($canonicalUrl, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')
            . '" rel="noopener noreferrer">' . $this->escapePlainText($content->title) . '</a></p>';
    }

    /** @return array{type: string, name: string}|null */
    private function tag(Tag $tag): ?array
    {
        $name = preg_replace('/\s+/u', '', trim($tag->name));
        if (!\is_string($name) || $name === '' || !mb_check_encoding($name, 'UTF-8')) {
            return null;
        }

        return ['type' => 'Hashtag', 'name' => '#' . mb_substr($name, 0, 255)];
    }

    /** @return list<array{type: string, href: string, name: string}> */
    private function mentions(string $html): array
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><body>' . $html . '</body></html>',
                LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            return [];
        }

        $mentions = [];
        foreach ($document->getElementsByTagName('a') as $anchor) {
            $classes = preg_split('/\s+/', strtolower($anchor->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
            if (!\is_array($classes) || !\in_array('mention', $classes, true)) {
                continue;
            }

            $href = $anchor->getAttribute('href');
            $name = $this->plainText($anchor->textContent, 255);
            $parts = parse_url($href);
            if (!\is_array($parts)) {
                continue;
            }

            $scheme = $parts['scheme'] ?? null;
            $host = $parts['host'] ?? null;
            if (!\is_string($scheme) || !\is_string($host)) {
                continue;
            }

            if ($name === ''
                || !str_starts_with($name, '@')
                || \strlen($href) > 2_048
                || strtolower($scheme) !== 'https'
                || $host === ''
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                continue;
            }

            $mentions[$href] = ['type' => 'Mention', 'href' => $href, 'name' => $name];
            if (\count($mentions) >= 32) {
                break;
            }
        }

        return array_values($mentions);
    }

    private function languageTag(?string $override): ?string
    {
        $language = $override ?? $this->siteLanguage;

        return match (strtolower(trim($language))) {
            'english' => 'en',
            'russian' => 'ru',
            default => preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/Di', trim($language)) === 1
                ? strtolower(trim($language))
                : null,
        };
    }

    private function escapePlainText(string $value): string
    {
        return htmlspecialchars($this->plainText($value, 10_000), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function plainText(string $value, int $maxCharacters): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('Federated plain text must be valid UTF-8.');
        }

        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return mb_substr($value, 0, $maxCharacters);
    }

    private function date(int $timestamp): string
    {
        if ($timestamp < 1) {
            throw new \InvalidArgumentException('An ActivityPub content timestamp must be positive.');
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
