<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Queue;

final class NativeShutdownRuntime implements ShutdownRuntimeInterface
{
    private const float EXECUTION_LIMIT_RESERVE_SECONDS = 2.0;

    #[\Override]
    public function isWebSapi(): bool
    {
        return !\in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true);
    }

    #[\Override]
    public function isDevelopmentServer(): bool
    {
        return PHP_SAPI === 'cli-server';
    }

    #[\Override]
    public function ignoreUserAbort(): void
    {
        ignore_user_abort(true);
    }

    #[\Override]
    public function registerShutdownFunction(\Closure $callback): void
    {
        register_shutdown_function($callback);
    }

    #[\Override]
    public function closeSession(): bool
    {
        return session_status() !== PHP_SESSION_ACTIVE || session_write_close();
    }

    #[\Override]
    public function finishResponse(): bool
    {
        if (\function_exists('fastcgi_finish_request')) {
            return fastcgi_finish_request();
        }

        $litespeedFinishRequest = 'litespeed_finish_request';
        if (\function_exists($litespeedFinishRequest)) {
            // @phan-suppress-next-line PhanUndeclaredFunctionInCallable Provided by LiteSpeed at runtime.
            return \call_user_func($litespeedFinishRequest);
        }

        flush();
        return false;
    }

    #[\Override]
    public function lastErrorType(): ?int
    {
        $lastError = error_get_last();
        return \is_array($lastError) ? $lastError['type'] : null;
    }

    #[\Override]
    public function startApmBackgroundTransaction(): void
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

    #[\Override]
    public function remainingExecutionSeconds(float $requestStartedAt, float $requestedSeconds): float
    {
        if (!is_finite($requestedSeconds) || $requestedSeconds <= 0.0) {
            throw new \InvalidArgumentException('Requested background execution time must be positive and finite.');
        }

        $configuredLimit = ini_get('max_execution_time');
        $executionLimit  = filter_var($configuredLimit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($executionLimit === false || $executionLimit === 0) {
            return $requestedSeconds;
        }

        $elapsed   = max(0.0, microtime(true) - $requestStartedAt);
        $remaining = (float)$executionLimit - $elapsed - self::EXECUTION_LIMIT_RESERVE_SECONDS;

        return max(0.0, min($requestedSeconds, $remaining));
    }
}
