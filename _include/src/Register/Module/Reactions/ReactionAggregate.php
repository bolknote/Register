<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

/** One idempotently addressable imported aggregate or individually reversible remote reaction. */
final readonly class ReactionAggregate
{
    /** @param array<string, mixed> $sourceData */
    public function __construct(
        public ReactionAggregateTargetType $targetType,
        public int                         $targetId,
        public string                      $source,
        public string                      $sourceKey,
        public string                      $reaction,
        public string                      $emoji,
        public int                         $count,
        public int                         $createdAt,
        public array                       $sourceData = [],
    ) {
        if ($targetId <= 0) {
            throw new \InvalidArgumentException('A reaction target identifier must be positive.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $source) !== 1) {
            throw new \InvalidArgumentException('A reaction source identifier is invalid.');
        }

        if ($sourceKey === '' || \strlen($sourceKey) > 128) {
            throw new \InvalidArgumentException('A reaction source key must contain at most 128 bytes.');
        }

        if (\strlen($reaction) > 16 || mb_strlen($emoji) > 64 || ($reaction === '' && $emoji === '')) {
            throw new \InvalidArgumentException('An imported reaction value is invalid.');
        }

        if ($count < 1 || $createdAt < 0) {
            throw new \InvalidArgumentException('Imported reaction count and timestamp are invalid.');
        }
    }
}
