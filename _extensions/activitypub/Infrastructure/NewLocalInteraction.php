<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

final readonly class NewLocalInteraction
{
    public function __construct(
        public int    $localActorId,
        public int    $remoteActorId,
        public string $remoteObjectUrl,
        public string $type,
        public string $emoji,
        public int    $localActivityId,
        public int    $createdAt,
    ) {
        if ($localActorId < 1
            || $remoteActorId < 1
            || !str_starts_with($remoteObjectUrl, 'https://')
            || \strlen($remoteObjectUrl) > 2_048
            || !\in_array($type, ['like', 'emoji_react', 'announce'], true)
            || mb_strlen($emoji) > 64
            || ($type === 'emoji_react' && $emoji === '')
            || ($type !== 'emoji_react' && $emoji !== '')
            || $localActivityId < 1
            || $createdAt < 1
        ) {
            throw new \InvalidArgumentException('A new local ActivityPub interaction is invalid.');
        }
    }
}
