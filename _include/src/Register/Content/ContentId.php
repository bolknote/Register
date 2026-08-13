<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

final readonly class ContentId implements \Stringable
{
    public function __construct(public ContentType $type, public int $value)
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException('A content identifier must be a positive integer.');
        }
    }

    public static function page(int $value): self
    {
        return new self(ContentType::PAGE, $value);
    }

    public static function post(int $value): self
    {
        return new self(ContentType::POST, $value);
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^(page|post):([1-9][0-9]*)$/D', $value, $matches) !== 1) {
            throw new \InvalidArgumentException(\sprintf('Invalid content identifier "%s".', $value));
        }

        $numericId = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($numericId === false) {
            throw new \InvalidArgumentException(\sprintf('Invalid content identifier "%s".', $value));
        }

        return new self(ContentType::from($matches[1]), $numericId);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->value === $other->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->type->value . ':' . $this->value;
    }
}
