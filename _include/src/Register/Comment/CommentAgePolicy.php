<?php
/**
 * @copyright 2026 Register contributors
 * @license https://opensource.org/license/mit MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Core\Config\DynamicConfigProvider;

/** A publication-age limit for new post comments; existing discussions stay visible. */
final readonly class CommentAgePolicy
{
    public const string CONFIG_KEY = 'REGISTER_COMMENT_MAX_AGE_DAYS';

    public const int MAX_DAYS = 36500;

    public function __construct(private DynamicConfigProvider $configProvider)
    {
    }

    public function days(): int
    {
        try {
            $value = $this->configProvider->get(self::CONFIG_KEY);
        } catch (\LogicException) {
            // Updating an older installation must not silently close its discussions.
            return 0;
        }

        $days = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_DAYS]]);

        return \is_int($days) ? $days : 0;
    }

    public function closesAt(?int $publishedAt): ?int
    {
        $days = $this->days();
        if ($days === 0 || $publishedAt === null) {
            return null;
        }

        return $publishedAt + $days * 86400;
    }

    public function isClosed(?int $publishedAt, ?int $now = null): bool
    {
        $closesAt = $this->closesAt($publishedAt);

        return $closesAt !== null && $closesAt <= ($now ?? time());
    }
}
