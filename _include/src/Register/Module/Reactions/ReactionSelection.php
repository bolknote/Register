<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

/** A public reaction value together with its compact database representation. */
final readonly class ReactionSelection
{
    private function __construct(
        public string $value,
        public string $storageValue,
    ) {
    }

    /** @param array<string, int> $availableExtraCounts */
    public static function fromAvailableValue(string $value, array $availableExtraCounts): ?self
    {
        $builtIn = ReactionType::tryFrom($value);
        if ($builtIn instanceof ReactionType) {
            return new self($builtIn->value, $builtIn->value);
        }

        if (!array_key_exists($value, $availableExtraCounts)) {
            return null;
        }

        return self::fromImportedEmoji($value);
    }

    public static function fromImportedEmoji(string $emoji): self
    {
        if ($emoji === '' || mb_strlen($emoji) > 64) {
            throw new \InvalidArgumentException('An imported reaction emoji is invalid.');
        }

        // Local reactions have a 16-character legacy column. Keep the public emoji in the API
        // while storing a deterministic compact key that cannot collide with built-in names.
        return new self($emoji, 'e' . substr(hash('sha256', $emoji), 0, 15));
    }
}
