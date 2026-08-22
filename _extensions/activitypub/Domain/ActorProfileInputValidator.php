<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Domain;

/** Runtime validation shared by actor drafts, hydrated commands, and future author actors. */
final class ActorProfileInputValidator
{
    private const int MAX_HTML_BYTES = 1_048_576;

    public static function validateDisplayName(string $displayName): void
    {
        self::validatePlainText($displayName, 'display name', 255, false);
    }

    public static function validateHtml(string $html, string $name): void
    {
        if (!mb_check_encoding($html, 'UTF-8')
            || \strlen($html) > self::MAX_HTML_BYTES
            || str_contains($html, "\0")
        ) {
            throw new \InvalidArgumentException(
                \sprintf('The ActivityPub actor %s must be valid UTF-8 and at most 1 MiB.', $name),
            );
        }
    }

    public static function validateProfileUrl(string $profileUrl): void
    {
        if (preg_match('/[\x00-\x20\x7f]/', $profileUrl) === 1 || str_contains($profileUrl, '\\')) {
            throw new \InvalidArgumentException('The ActivityPub actor profile URL is invalid.');
        }

        $parts = parse_url($profileUrl);
        if (!\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException(
                'The ActivityPub actor profile URL must be an absolute HTTPS URL without credentials or a fragment.',
            );
        }
    }

    /** @param array<mixed>|null $media */
    public static function validateMedia(?array $media, string $name): void
    {
        if ($media === null) {
            return;
        }

        $allowedKeys = ['url' => true, 'mediaType' => true, 'name' => true, 'blurhash' => true, 'width' => true, 'height' => true];
        if (array_diff_key($media, $allowedKeys) !== []) {
            throw new \InvalidArgumentException(\sprintf('The ActivityPub actor %s contains unknown fields.', $name));
        }

        $url = $media['url'] ?? null;
        if (!\is_string($url)) {
            throw new \InvalidArgumentException(\sprintf('The ActivityPub actor %s requires an HTTPS URL.', $name));
        }

        self::validateProfileUrl($url);

        $mediaType = $media['mediaType'] ?? null;
        if ($mediaType !== null
            && (!\is_string($mediaType) || preg_match('~^image/[a-z0-9][a-z0-9.+-]{0,63}$~Di', $mediaType) !== 1)
        ) {
            throw new \InvalidArgumentException(\sprintf('The ActivityPub actor %s media type is invalid.', $name));
        }

        $alt = $media['name'] ?? null;
        if ($alt !== null) {
            if (!\is_string($alt)) {
                throw new \InvalidArgumentException(\sprintf('The ActivityPub actor %s alt text is invalid.', $name));
            }

            self::validatePlainText($alt, $name . ' alt text', 1_024, true);
        }

        $blurhash = $media['blurhash'] ?? null;
        if ($blurhash !== null
            && (!\is_string($blurhash) || preg_match('/^[!-~]{6,200}$/D', $blurhash) !== 1)
        ) {
            throw new \InvalidArgumentException(\sprintf('The ActivityPub actor %s blurhash is invalid.', $name));
        }

        foreach (['width', 'height'] as $dimension) {
            $value = $media[$dimension] ?? null;
            if ($value !== null && (!\is_int($value) || $value < 1 || $value > 32_768)) {
                throw new \InvalidArgumentException(\sprintf('The ActivityPub actor %s %s is invalid.', $name, $dimension));
            }
        }
    }

    /** @param array<mixed> $metadata */
    public static function validateMetadata(array $metadata): void
    {
        if (!array_is_list($metadata) || \count($metadata) > 8) {
            throw new \InvalidArgumentException('An ActivityPub actor may have at most eight ordered metadata fields.');
        }

        foreach ($metadata as $entry) {
            if (!\is_array($entry)
                || array_keys($entry) !== ['name', 'value']
                || !\is_string($entry['name'])
                || !\is_string($entry['value'])
            ) {
                throw new \InvalidArgumentException('An ActivityPub actor metadata field is invalid.');
            }

            self::validatePlainText($entry['name'], 'metadata name', 255, false);
            self::validateHtml($entry['value'], 'metadata value');
            if (mb_strlen($entry['value']) > 4_096) {
                throw new \InvalidArgumentException('An ActivityPub actor metadata value is too long.');
            }
        }
    }

    private static function validatePlainText(string $value, string $name, int $maxCharacters, bool $allowEmpty): void
    {
        $trimmed = trim($value);
        if (!mb_check_encoding($value, 'UTF-8')
            || (!$allowEmpty && $trimmed === '')
            || mb_strlen($value) > $maxCharacters
            || strip_tags($value) !== $value
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
        ) {
            throw new \InvalidArgumentException(\sprintf(
                'The ActivityPub actor %s must be plain valid UTF-8 with at most %d characters.',
                $name,
                $maxCharacters,
            ));
        }
    }

    private function __construct()
    {
        throw new \LogicException('Actor profile validation is static.');
    }
}
