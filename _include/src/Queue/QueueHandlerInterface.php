<?php
/**
 * @copyright 2023-2026 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

interface QueueHandlerInterface
{
    /**
     * @return list<string>
     */
    public function codes(): array;

    /** Conservative time required before this handler may start. */
    public function minimumExecutionTime(): float;

    /**
     * @param array<mixed> $payload
     */
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void;
}
