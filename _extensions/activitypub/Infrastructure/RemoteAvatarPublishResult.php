<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

final readonly class RemoteAvatarPublishResult
{
    public function __construct(
        public bool    $published,
        public ?string $replacedStorageKey,
    ) {
    }
}
