<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

final readonly class InboxReceipt
{
    public function __construct(public int $id, public bool $inserted)
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('An ActivityPub inbox receipt identifier must be positive.');
        }
    }
}
