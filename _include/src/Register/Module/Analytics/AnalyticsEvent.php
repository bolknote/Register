<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

/** A normalized, privacy-reduced event safe to persist in the local spool. */
final readonly class AnalyticsEvent
{
    public const string TYPE_PAGE_VIEW = 'pageview';

    public const string TYPE_ENGAGEMENT = 'engagement';

    public const string TYPE_CUSTOM = 'event';

    private const array TYPES = [
        self::TYPE_PAGE_VIEW,
        self::TYPE_ENGAGEMENT,
        self::TYPE_CUSTOM,
    ];

    private const array SOURCE_KINDS = [
        'campaign',
        'direct',
        'internal',
        'referral',
        'search',
        'social',
    ];

    public function __construct(
        public string $id,
        public string $type,
        public int    $occurredAt,
        public int    $receivedAt,
        public string $visitorKey,
        public string $sessionKey,
        public string $pageViewId,
        public string $pageKey,
        public string $path,
        public string $title,
        public string $sourceKey,
        public string $sourceKind,
        public string $referrerHost,
        public string $utmSource,
        public string $utmMedium,
        public string $utmCampaign,
        public string $name,
        public int    $engagementSeconds,
        public int    $scrollDepth,
        public string $propertiesJson,
    ) {
        self::assertValid($this);
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'id'                 => $this->id,
            'type'               => $this->type,
            'occurred_at'        => $this->occurredAt,
            'received_at'        => $this->receivedAt,
            'visitor_key'        => $this->visitorKey,
            'session_key'        => $this->sessionKey,
            'pageview_id'        => $this->pageViewId,
            'page_key'           => $this->pageKey,
            'path'               => $this->path,
            'title'              => $this->title,
            'source_key'         => $this->sourceKey,
            'source_kind'        => $this->sourceKind,
            'referrer_host'      => $this->referrerHost,
            'utm_source'         => $this->utmSource,
            'utm_medium'         => $this->utmMedium,
            'utm_campaign'       => $this->utmCampaign,
            'name'               => $this->name,
            'engagement_seconds' => $this->engagementSeconds,
            'scroll_depth'       => $this->scrollDepth,
            'properties_json'    => $this->propertiesJson,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $strings = [
            'id',
            'type',
            'visitor_key',
            'session_key',
            'pageview_id',
            'page_key',
            'path',
            'title',
            'source_key',
            'source_kind',
            'referrer_host',
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'name',
            'properties_json',
        ];
        foreach ($strings as $field) {
            if (!isset($data[$field]) || !\is_string($data[$field])) {
                throw new \UnexpectedValueException('An analytics spool record has an invalid ' . $field . ' field.');
            }
        }

        $integers = ['occurred_at', 'received_at', 'engagement_seconds', 'scroll_depth'];
        foreach ($integers as $field) {
            if (!isset($data[$field]) || !\is_int($data[$field])) {
                throw new \UnexpectedValueException('An analytics spool record has an invalid ' . $field . ' field.');
            }
        }

        return new self(
            $data['id'],
            $data['type'],
            $data['occurred_at'],
            $data['received_at'],
            $data['visitor_key'],
            $data['session_key'],
            $data['pageview_id'],
            $data['page_key'],
            $data['path'],
            $data['title'],
            $data['source_key'],
            $data['source_kind'],
            $data['referrer_host'],
            $data['utm_source'],
            $data['utm_medium'],
            $data['utm_campaign'],
            $data['name'],
            $data['engagement_seconds'],
            $data['scroll_depth'],
            $data['properties_json'],
        );
    }

    private static function assertValid(self $event): void
    {
        self::assertDigest($event->id, 32, 'event identifier');
        self::assertDigest($event->visitorKey, 64, 'visitor key');
        self::assertDigest($event->sessionKey, 64, 'session key');
        self::assertDigest($event->pageViewId, 32, 'page-view identifier');
        self::assertDigest($event->pageKey, 64, 'page key');
        self::assertDigest($event->sourceKey, 64, 'source key');

        if (!\in_array($event->type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported analytics event type.');
        }

        if (!\in_array($event->sourceKind, self::SOURCE_KINDS, true)) {
            throw new \InvalidArgumentException('Unsupported analytics source kind.');
        }

        if ($event->occurredAt <= 0 || $event->receivedAt <= 0) {
            throw new \InvalidArgumentException('Analytics timestamps must be positive.');
        }

        if (!str_starts_with($event->path, '/') || \strlen($event->path) > 1024) {
            throw new \InvalidArgumentException('An analytics path must be a bounded absolute path.');
        }

        foreach ([
            [$event->title, 255, 'title'],
            [$event->referrerHost, 255, 'referrer host'],
            [$event->utmSource, 100, 'UTM source'],
            [$event->utmMedium, 100, 'UTM medium'],
            [$event->utmCampaign, 150, 'UTM campaign'],
            [$event->name, 64, 'event name'],
        ] as [$value, $limit, $label]) {
            if (\strlen($value) > $limit) {
                throw new \InvalidArgumentException('Analytics ' . $label . ' is too long.');
            }
        }

        if ($event->name !== '' && preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/D', $event->name) !== 1) {
            throw new \InvalidArgumentException('Analytics event name contains unsupported characters.');
        }

        if ($event->engagementSeconds < 0 || $event->engagementSeconds > 300) {
            throw new \InvalidArgumentException('Analytics engagement duration is out of range.');
        }

        if ($event->scrollDepth < 0 || $event->scrollDepth > 100) {
            throw new \InvalidArgumentException('Analytics scroll depth is out of range.');
        }

        if (\strlen($event->propertiesJson) > 2048) {
            throw new \InvalidArgumentException('Analytics properties are too large.');
        }

        try {
            $properties = json_decode($event->propertiesJson, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Analytics properties must be valid JSON.', 0, $exception);
        }
        if (!\is_array($properties)) {
            throw new \InvalidArgumentException('Analytics properties must be a JSON object.');
        }
    }

    private static function assertDigest(string $value, int $length, string $label): void
    {
        if (preg_match('/^[a-f0-9]{' . $length . '}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid analytics ' . $label . '.');
        }
    }
}
