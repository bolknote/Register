<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

interface ShutdownRuntimeInterface
{
    public function isWebSapi(): bool;

    public function isDevelopmentServer(): bool;

    public function ignoreUserAbort(): void;

    /** @param \Closure(): void $callback */
    public function registerShutdownFunction(\Closure $callback): void;

    /** Returns false only when an active session could not be closed. */
    public function closeSession(): bool;

    /** Finishes the client response and returns whether it was detached from the PHP worker. */
    public function finishResponse(): bool;

    public function lastErrorType(): ?int;

    public function startApmBackgroundTransaction(): void;

    /** Returns the safe part of the requested budget left after the foreground request. */
    public function remainingExecutionSeconds(float $requestStartedAt, float $requestedSeconds): float;
}
