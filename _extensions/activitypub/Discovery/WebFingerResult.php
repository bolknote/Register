<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Discovery;

use Register\Extension\activitypub\Domain\RemoteHandle;

final readonly class WebFingerResult
{
    /** @param list<string> $aliases */
    public function __construct(
        public RemoteHandle $handle,
        public string       $actorUrl,
        public array               $aliases,
    ) {
        if (!str_starts_with($actorUrl, 'https://')) {
            throw new \InvalidArgumentException('A WebFinger actor URL must use HTTPS.');
        }
    }
}
