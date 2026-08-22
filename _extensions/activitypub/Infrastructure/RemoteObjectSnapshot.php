<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

final readonly class RemoteObjectSnapshot
{
    /** @param array<string, mixed> $document */
    public function __construct(
        public int    $id,
        public int    $remoteObjectId,
        public string $bodyHash,
        public array  $document,
        public int    $fetchedAt,
    ) {
        if ($id < 1
            || $remoteObjectId < 1
            || preg_match('/^[a-f0-9]{64}$/D', $bodyHash) !== 1
            || $fetchedAt < 1
        ) {
            throw new \InvalidArgumentException('A remote ActivityPub object snapshot is invalid.');
        }
    }
}
