<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

/** Requests a durable delay without treating the current execution as another failed attempt. */
final class QueueDeferredUntil extends \RuntimeException
{
    public function __construct(public readonly int $availableAt)
    {
        if ($availableAt < 0) {
            throw new \InvalidArgumentException('A queue deferral timestamp cannot be negative.');
        }

        parent::__construct('Queue execution has been deferred until a later time.');
    }
}
