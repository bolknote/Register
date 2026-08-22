<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

final readonly class RemoteAvatarPublishResult
{
    public function __construct(
        public bool    $published,
        public ?string $replacedStorageKey,
    ) {
    }
}
