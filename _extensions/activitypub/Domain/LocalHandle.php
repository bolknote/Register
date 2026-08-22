<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

final readonly class LocalHandle
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $normalized) !== 1) {
            throw new \InvalidArgumentException('An ActivityPub handle must be 1–32 lowercase ASCII letters, digits, underscores, or hyphens.');
        }

        $this->value = $normalized;
    }
}
