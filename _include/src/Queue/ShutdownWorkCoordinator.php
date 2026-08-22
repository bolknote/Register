<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

use Psr\Log\LoggerInterface;

final class ShutdownWorkCoordinator
{
    /** Covers the longest built-in network handler without turning shutdown into an unbounded worker. */
    private const float ATTACHED_WORK_BUDGET_SECONDS = 4.5;

    private const float DETACHED_WORK_BUDGET_SECONDS = 5.0;

    private const array FATAL_ERROR_TYPES = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
        E_RECOVERABLE_ERROR,
    ];

    private bool $registered = false;

    private bool $responseFinished = false;

    private bool $responseDetached = false;

    /** @param \Closure(): BackgroundWorkRunnerInterface $runnerFactory */
    public function __construct(
        private readonly \PDO                    $pdo,
        private readonly LoggerInterface         $logger,
        private readonly ShutdownRuntimeInterface $runtime,
        private readonly \Closure                $runnerFactory,
        private readonly float                   $requestStartedAt,
    ) {
    }

    public function register(): void
    {
        if ($this->registered || !$this->runtime->isWebSapi()) {
            return;
        }

        // This must be enabled before registering the callback: a disconnected client must not cancel queue recovery.
        $this->runtime->ignoreUserAbort();
        $this->runtime->registerShutdownFunction($this->runOnShutdown(...));

        $this->registered = true;
    }

    /** Saves the session and releases its lock before any background work can begin. */
    public function closeSession(): void
    {
        if (!$this->runtime->closeSession()) {
            $this->logger->warning('Unable to close the active session before background work.');
        }
    }

    /** Marks a sent response as detached from the PHP process whenever the SAPI supports it. */
    public function finishResponse(): void
    {
        if ($this->responseFinished) {
            return;
        }

        $this->closeSession();

        $this->responseDetached = $this->runtime->finishResponse();
        $this->responseFinished = true;
        try {
            $this->runtime->startApmBackgroundTransaction();
        } catch (\Throwable $throwable) {
            $this->logger->warning('Unable to start an APM background transaction.', ['exception' => $throwable]);
        }
    }

    private function runOnShutdown(): void
    {
        try {
            if (!$this->responseFinished || $this->hasFatalError()) {
                return;
            }

            if ($this->pdo->inTransaction()) {
                $this->logger->warning('Shutdown background work was skipped because a database transaction is still active.');
                return;
            }

            $detached = $this->responseDetached && !$this->runtime->isDevelopmentServer();
            // A one-second attached slice starves handlers whose bounded network step needs longer:
            // QueueConsumer excludes them before the first attempt, so their rows can never advance.
            $requestedBudget = $detached
                ? self::DETACHED_WORK_BUDGET_SECONDS
                : self::ATTACHED_WORK_BUDGET_SECONDS;
            $maxJobs         = 5;
            $safeBudget      = $this->runtime->remainingExecutionSeconds(
                $this->requestStartedAt,
                $requestedBudget,
            );
            if ($safeBudget < 0.05) {
                $this->logger->notice('Shutdown background work was skipped because the request time limit is exhausted.');
                return;
            }

            ($this->runnerFactory)()->run($safeBudget, $maxJobs);
        } catch (\Throwable $throwable) {
            try {
                $this->logger->error('Shutdown background work failed.', ['exception' => $throwable]);
            } catch (\Throwable) {
                // A shutdown callback must never emit another failure.
            }
        }
    }

    private function hasFatalError(): bool
    {
        $lastErrorType = $this->runtime->lastErrorType();
        return $lastErrorType !== null && \in_array($lastErrorType, self::FATAL_ERROR_TYPES, true);
    }
}
