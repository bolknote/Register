<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Config\StringProxy;
use Symfony\Component\HttpFoundation\Request;

/** Validates collector input and removes high-risk or high-cardinality request data. */
final readonly class AnalyticsEventNormalizer
{
    private const int MAX_CLOCK_AGE_SECONDS = 86400;

    private const int MAX_CLOCK_LEAD_SECONDS = 300;

    private const array SEARCH_DOMAINS = [
        'baidu.com',
        'bing.com',
        'duckduckgo.com',
        'ecosia.org',
        'qwant.com',
        'rambler.ru',
        'search.brave.com',
        'search.mail.ru',
    ];

    private const array SOCIAL_DOMAINS = [
        'dzen.ru',
        'facebook.com',
        'instagram.com',
        'linkedin.com',
        'ok.ru',
        'pinterest.com',
        'reddit.com',
        't.co',
        't.me',
        'telegram.me',
        'telegram.org',
        'threads.net',
        'twitter.com',
        'vk.com',
        'x.com',
        'youtu.be',
        'youtube.com',
    ];

    private const array CONTENT_TYPES = [
        'archive',
        'blog-list',
        'error',
        'home',
        'other',
        'page',
        'post',
        'search',
    ];

    private const array DEVICES = ['desktop', 'mobile', 'tablet'];

    private const array BROWSERS = ['Chrome', 'Edge', 'Firefox', 'Other', 'Safari', 'Samsung Internet'];

    private const array OPERATING_SYSTEMS = ['Android', 'ChromeOS', 'Linux', 'Other', 'Windows', 'iOS', 'macOS'];

    private const array SCREEN_CLASSES = ['large', 'medium', 'small', 'wide'];

    private const array NAVIGATION_TYPES = ['back_forward', 'navigate', 'other', 'prerender', 'reload'];

    public function __construct(private StringProxy $salt)
    {
    }

    /** @param array<string, mixed> $input */
    public function normalize(array $input, Request $request, string $visitorId, int $receivedAt): AnalyticsEvent
    {
        $id         = $this->hexIdentifier($input, 'id');
        $sessionId  = $this->hexIdentifier($input, 'session_id');
        $pageViewId = $this->hexIdentifier($input, 'pageview_id');
        $type       = isset($input['type']) && \is_string($input['type']) ? $input['type'] : '';
        if (!\in_array($type, [
            AnalyticsEvent::TYPE_PAGE_VIEW,
            AnalyticsEvent::TYPE_ENGAGEMENT,
            AnalyticsEvent::TYPE_CUSTOM,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported analytics event type.');
        }

        $path           = $this->path($input['path'] ?? null);
        $title          = $this->text($input['title'] ?? '', 255);
        $referrerHost   = $this->referrerHost($input['referrer'] ?? '');
        $utm            = $this->utm($input['utm'] ?? []);
        $sourceKind     = $this->sourceKind($request, $referrerHost, $utm);
        $sourceIdentity = implode("\0", [
            $sourceKind,
            $referrerHost,
            $utm['source'],
            $utm['medium'],
            $utm['campaign'],
        ]);
        $secret = $this->salt->get();

        $name = '';
        if ($type === AnalyticsEvent::TYPE_CUSTOM) {
            $name = $this->eventName($input['name'] ?? null);
        }

        $engagementSeconds = 0;
        $scrollDepth       = 0;
        if ($type === AnalyticsEvent::TYPE_ENGAGEMENT) {
            $engagementMilliseconds = $this->boundedInteger($input['engagement_ms'] ?? 0, 0, 300000);
            $engagementSeconds      = min(300, (int)round($engagementMilliseconds / 1000));
            $scrollDepth            = $this->boundedInteger($input['scroll_depth'] ?? 0, 0, 100);
            if ($engagementSeconds === 0 && $scrollDepth === 0) {
                throw new \InvalidArgumentException('An engagement event must contain activity.');
            }
        }

        return new AnalyticsEvent(
            $id,
            $type,
            $this->occurredAt($input['occurred_at'] ?? null, $receivedAt),
            $receivedAt,
            hash_hmac('sha256', "event-visitor\0" . $visitorId, $secret),
            hash_hmac('sha256', "event-session\0" . $visitorId . "\0" . $sessionId, $secret),
            $pageViewId,
            hash('sha256', $path),
            $path,
            $title,
            hash('sha256', $sourceIdentity),
            $sourceKind,
            $referrerHost,
            $utm['source'],
            $utm['medium'],
            $utm['campaign'],
            $name,
            $engagementSeconds,
            $scrollDepth,
            $this->propertiesJson($input['properties'] ?? []),
        );
    }

    /** @param array<string, mixed> $input */
    private function hexIdentifier(array $input, string $field): string
    {
        $value = $input[$field] ?? null;
        if (!\is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid analytics ' . $field . '.');
        }

        return $value;
    }

    private function path(mixed $value): string
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > 4096) {
            throw new \InvalidArgumentException('Invalid analytics path.');
        }

        $parsed = parse_url($value);
        if ($parsed === false || !isset($parsed['path'])) {
            throw new \InvalidArgumentException('Invalid analytics path.');
        }

        $path = '/' . ltrim($parsed['path'], '/');
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        if (\strlen($path) > 1024) {
            throw new \InvalidArgumentException('Analytics path is too long.');
        }

        return $path;
    }

    private function occurredAt(mixed $value, int $receivedAt): int
    {
        if (!\is_int($value) && (!\is_string($value) || preg_match('/^[0-9]{1,16}$/D', $value) !== 1)) {
            return $receivedAt;
        }

        $timestamp = (int)$value;
        if ($timestamp > 4_000_000_000) {
            $timestamp = intdiv($timestamp, 1000);
        }

        if ($timestamp < $receivedAt - self::MAX_CLOCK_AGE_SECONDS
            || $timestamp > $receivedAt + self::MAX_CLOCK_LEAD_SECONDS
        ) {
            return $receivedAt;
        }

        return $timestamp;
    }

    private function referrerHost(mixed $value): string
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > 4096) {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!\is_string($host)) {
            return '';
        }

        return $this->text(strtolower(rtrim($host, '.')), 255);
    }

    /** @return array{source: string, medium: string, campaign: string} */
    private function utm(mixed $value): array
    {
        if (!\is_array($value)) {
            $value = [];
        }

        return [
            'source'   => $this->text($value['source'] ?? '', 100),
            'medium'   => $this->text($value['medium'] ?? '', 100),
            'campaign' => $this->text($value['campaign'] ?? '', 150),
        ];
    }

    /** @param array{source: string, medium: string, campaign: string} $utm */
    private function sourceKind(Request $request, string $referrerHost, array $utm): string
    {
        if ($utm['source'] !== '' || $utm['medium'] !== '' || $utm['campaign'] !== '') {
            return 'campaign';
        }

        if ($referrerHost === '') {
            return 'direct';
        }

        if (hash_equals(strtolower($request->getHost()), $referrerHost)) {
            return 'internal';
        }

        foreach (self::SEARCH_DOMAINS as $domain) {
            if ($this->belongsToDomain($referrerHost, $domain)) {
                return 'search';
            }
        }
        if (preg_match('/(?:^|\.)google\.[a-z0-9.-]+$/D', $referrerHost) === 1
            || preg_match('/(?:^|\.)yandex\.[a-z0-9.-]+$/D', $referrerHost) === 1
            || preg_match('/(?:^|\.)search\.yahoo\.[a-z0-9.-]+$/D', $referrerHost) === 1
        ) {
            return 'search';
        }

        foreach (self::SOCIAL_DOMAINS as $domain) {
            if ($this->belongsToDomain($referrerHost, $domain)) {
                return 'social';
            }
        }

        return 'referral';
    }

    private function belongsToDomain(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    private function eventName(mixed $value): string
    {
        if (!\is_string($value) || preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid analytics event name.');
        }

        return $value;
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): int
    {
        if (!\is_int($value)) {
            throw new \InvalidArgumentException('Invalid numeric analytics value.');
        }

        if ($value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException('Analytics value is out of range.');
        }

        return $value;
    }

    private function propertiesJson(mixed $value): string
    {
        if (!\is_array($value) || array_is_list($value) || \count($value) > 16) {
            if ($value === []) {
                return '{}';
            }

            throw new \InvalidArgumentException('Analytics properties must be a small JSON object.');
        }

        $properties = [];
        foreach ($value as $name => $property) {
            if (!\is_string($name) || preg_match('/^[a-zA-Z0-9_.:-]{1,48}$/D', $name) !== 1) {
                throw new \InvalidArgumentException('Invalid analytics property name.');
            }

            if (\is_string($property)) {
                $property = $this->text($property, 200);
            } elseif (!\is_int($property) && !\is_float($property) && !\is_bool($property) && $property !== null) {
                throw new \InvalidArgumentException('Analytics properties must contain scalar values.');
            }

            $properties[$name] = $this->normalizedProperty($name, $property);
        }

        $json = json_encode($properties, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (\strlen($json) > 2048) {
            throw new \InvalidArgumentException('Analytics properties are too large.');
        }

        return $json;
    }

    private function normalizedProperty(string $name, bool|float|int|string|null $value): bool|float|int|string|null
    {
        return match ($name) {
            'content_type' => $this->choiceProperty($value, self::CONTENT_TYPES, $name),
            'device'       => $this->choiceProperty($value, self::DEVICES, $name),
            'browser'      => $this->choiceProperty($value, self::BROWSERS, $name),
            'os'           => $this->choiceProperty($value, self::OPERATING_SYSTEMS, $name),
            'screen'       => $this->choiceProperty($value, self::SCREEN_CLASSES, $name),
            'nav_type'     => $this->choiceProperty($value, self::NAVIGATION_TYPES, $name),
            'content_id'   => $this->textProperty($value, 100, $name),
            'author',
            'section'      => $this->textProperty($value, 120, $name),
            'language'     => $this->languageProperty($value),
            'published_at' => $this->integerProperty($value, 0, 4_102_444_800, $name),
            'word_count'   => $this->integerProperty($value, 0, 200_000, $name),
            'lcp_ms'       => $this->integerProperty($value, 0, 120_000, $name),
            'cls_milli'    => $this->integerProperty($value, 0, 10_000, $name),
            'inp_ms'       => $this->integerProperty($value, 0, 60_000, $name),
            default        => $value,
        };
    }

    /** @param list<string> $choices */
    private function choiceProperty(bool|float|int|string|null $value, array $choices, string $name): string
    {
        if (!\is_string($value) || !\in_array($value, $choices, true)) {
            throw new \InvalidArgumentException('Invalid analytics ' . $name . ' property.');
        }

        return $value;
    }

    private function textProperty(bool|float|int|string|null $value, int $maximumBytes, string $name): string
    {
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException('Invalid analytics ' . $name . ' property.');
        }

        return $this->text($value, $maximumBytes);
    }

    private function languageProperty(bool|float|int|string|null $value): string
    {
        if (!\is_string($value) || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid analytics language property.');
        }

        return $value;
    }

    private function integerProperty(
        bool|float|int|string|null $value,
        int $minimum,
        int $maximum,
        string $name,
    ): int {
        if (!\is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \InvalidArgumentException('Invalid analytics ' . $name . ' property.');
        }

        return $value;
    }

    private function text(mixed $value, int $maximumBytes): string
    {
        if (!\is_string($value)) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        if (\strlen($value) <= $maximumBytes) {
            return $value;
        }

        return mb_strcut($value, 0, $maximumBytes, 'UTF-8');
    }
}
