<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

use Psr\Log\LoggerInterface;

final class ShutdownWorkCoordinator
{
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

    /** @param \Closure(): BackgroundWorkRunner $runnerFactory */
    public function __construct(
        private readonly \PDO            $pdo,
        private readonly LoggerInterface $logger,
        private readonly \Closure        $runnerFactory,
    ) {
    }

    public function register(): void
    {
        if ($this->registered || !$this->isWebSapi()) {
            return;
        }

        // This must be enabled before registering the callback: a disconnected client must not cancel queue recovery.
        ignore_user_abort(true);
        register_shutdown_function($this->runOnShutdown(...));
        $this->registered = true;
    }

    /** Saves the session and releases its lock before any background work can begin. */
    public function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
            $this->logger->warning('Unable to close the active session before background work.');
        }
    }

    /** Marks a sent response as detached from the PHP process whenever the SAPI supports it. */
    public function finishResponse(): void
    {
        $this->closeSession();

        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            $this->responseDetached = true;
        } elseif (\function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            $this->responseDetached = true;
        } else {
            flush();
        }

        $this->responseFinished = true;
        $this->startApmBackgroundTransaction();
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

            $runner = ($this->runnerFactory)();
            if (!$this->responseDetached || PHP_SAPI === 'cli-server') {
                $runner->run(1.0, 1);
            } else {
                $runner->run();
            }
        } catch (\Throwable $throwable) {
            try {
                $this->logger->error('Shutdown background work failed.', ['exception' => $throwable]);
            } catch (\Throwable) {
                // A shutdown callback must never emit another failure.
            }
        }
    }

    private function isWebSapi(): bool
    {
        return !\in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true);
    }

    private function hasFatalError(): bool
    {
        $lastError = error_get_last();
        return \is_array($lastError) && \in_array($lastError['type'], self::FATAL_ERROR_TYPES, true);
    }

    private function startApmBackgroundTransaction(): void
    {
        if (
            !\extension_loaded('newrelic')
            || !\function_exists('newrelic_end_transaction')
            || !\function_exists('newrelic_start_transaction')
            || !\function_exists('newrelic_name_transaction')
        ) {
            return;
        }

        newrelic_end_transaction();
        $appName = ini_get('newrelic.appname');
        newrelic_start_transaction(\is_string($appName) ? $appName : 'Register');
        newrelic_name_transaction('shutdown_background');
    }
}
