<?php
/**
 * @copyright 2023-2026 Roman Parpalak
 * @license MIT
 * @package S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

interface QueueHandlerInterface
{
    /**
     * @return list<string>
     */
    public function codes(): array;

    /**
     * @param array<mixed> $payload
     */
    public function handle(string $id, string $code, array $payload): void;
}
