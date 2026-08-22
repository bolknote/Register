<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Presentation;

final class ActivityStreamsContext
{
    public const string ACTIVITY_STREAMS = 'https://www.w3.org/ns/activitystreams';

    public const string PUBLIC_COLLECTION = 'https://www.w3.org/ns/activitystreams#Public';

    public const string SECURITY_V1 = 'https://w3id.org/security/v1';

    /** @return list<string|array<string, mixed>> */
    public static function actor(): array
    {
        return [
            self::ACTIVITY_STREAMS,
            self::SECURITY_V1,
            [
                'as'                        => 'https://www.w3.org/ns/activitystreams#',
                'manuallyApprovesFollowers' => 'as:manuallyApprovesFollowers',
                'toot'                      => 'http://joinmastodon.org/ns#',
                'discoverable'              => 'toot:discoverable',
                'featured'                  => ['@id' => 'toot:featured', '@type' => '@id'],
                'schema'                    => 'http://schema.org#',
                'PropertyValue'             => 'schema:PropertyValue',
                'value'                     => 'schema:value',
            ],
        ];
    }

    /** @suppress PhanEmptyPrivateMethod Prevent instantiation of this constants-only namespace. */
    private function __construct()
    {
    }
}
