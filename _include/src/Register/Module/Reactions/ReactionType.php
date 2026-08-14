<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

enum ReactionType: string
{
    case LIKE = 'like';

    case LOVE = 'love';

    case HAHA = 'haha';

    case WOW = 'wow';

    case SAD = 'sad';

    case ANGRY = 'angry';

    public function emoji(): string
    {
        return match ($this) {
            self::LIKE  => '👍',
            self::LOVE  => '❤️',
            self::HAHA  => '😂',
            self::WOW   => '😮',
            self::SAD   => '😢',
            self::ANGRY => '😡',
        };
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::LIKE  => 'reaction.like',
            self::LOVE  => 'reaction.love',
            self::HAHA  => 'reaction.haha',
            self::WOW   => 'reaction.wow',
            self::SAD   => 'reaction.sad',
            self::ANGRY => 'reaction.angry',
        };
    }
}
