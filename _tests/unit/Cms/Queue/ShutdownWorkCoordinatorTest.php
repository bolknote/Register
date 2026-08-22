<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Queue;

use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Register\Core\Queue\BackgroundWorkRunnerInterface;
use Register\Core\Queue\ShutdownRuntimeInterface;
use Register\Core\Queue\ShutdownWorkCoordinator;

final class ShutdownWorkCoordinatorTest extends Unit
{
    public function testRegistersIgnoreUserAbortBeforeShutdownCallback(): void
    {
        [$coordinator, $runtime] = $this->coordinator();

        $coordinator->register();
        $coordinator->register();

        self::assertSame(['ignore_user_abort', 'register_shutdown'], $runtime->events);
        self::assertInstanceOf(\Closure::class, $runtime->callback);
    }

    public function testDoesNotRegisterOutsideWebSapi(): void
    {
        [$coordinator, $runtime] = $this->coordinator();
        $runtime->webSapi = false;

        $coordinator->register();

        self::assertSame([], $runtime->events);
        self::assertNull($runtime->callback);
    }

    public function testRunsDetachedWorkOnlyAfterResponseCompletion(): void
    {
        [$coordinator, $runtime, $runner] = $this->coordinator();
        $runtime->detached = true;
        $runtime->safeBudget = 3.5;

        $coordinator->register();

        $runtime->invokeShutdown();
        self::assertCount(0, $runner->calls);

        $coordinator->finishResponse();
        $coordinator->finishResponse();

        $runtime->invokeShutdown();

        self::assertSame([[3.5, 5]], $runner->calls);
        self::assertSame(1, $runtime->finishCalls);
        self::assertSame(1, $runtime->apmCalls);
    }

    public function testAttachedSliceCanRunBoundedNetworkHandlersWithoutResponseDetachment(): void
    {
        [$coordinator, $runtime, $runner] = $this->coordinator();
        $runtime->detached = false;
        $coordinator->register();
        $coordinator->finishResponse();

        $runtime->invokeShutdown();

        self::assertSame([[4.5, 5]], $runner->calls);
    }

    public function testSkipsWorkAfterFatalErrorOrExhaustedRequestLimit(): void
    {
        [$coordinator, $runtime, $runner] = $this->coordinator();
        $coordinator->register();
        $coordinator->finishResponse();

        $runtime->lastError = E_ERROR;
        $runtime->invokeShutdown();
        self::assertSame([], $runner->calls);

        [$coordinator, $runtime, $runner] = $this->coordinator();
        $coordinator->register();
        $coordinator->finishResponse();

        $runtime->safeBudget = 0.0;
        $runtime->invokeShutdown();
        self::assertSame([], $runner->calls);
    }

    public function testSkipsWorkWhileForegroundTransactionIsOpen(): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        [$coordinator, $runtime, $runner] = $this->coordinator($pdo);
        $coordinator->register();
        $coordinator->finishResponse();

        $runtime->invokeShutdown();

        self::assertSame([], $runner->calls);
        $pdo->rollBack();
    }

    public function testExplicitlySuppressesAttachedBackgroundWork(): void
    {
        [$coordinator, $runtime, $runner] = $this->coordinator();
        $coordinator->register();
        $coordinator->suppressBackgroundWork();
        $coordinator->finishResponse();

        $runtime->invokeShutdown();

        self::assertSame([], $runner->calls);
        self::assertSame(1, $runtime->finishCalls);
    }

    /** @return array{ShutdownWorkCoordinator, FakeShutdownRuntime, FakeBackgroundWorkRunner} */
    private function coordinator(?\PDO $pdo = null): array
    {
        $runtime = new FakeShutdownRuntime();
        $runner  = new FakeBackgroundWorkRunner();

        return [
            new ShutdownWorkCoordinator(
                $pdo ?? $this->pdo(),
                new NullLogger(),
                $runtime,
                static fn(): BackgroundWorkRunnerInterface => $runner,
                1000.0,
            ),
            $runtime,
            $runner,
        ];
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}

final class FakeBackgroundWorkRunner implements BackgroundWorkRunnerInterface
{
    /** @var list<array{float, int}> */
    public array $calls = [];

    #[\Override]
    public function run(float $maxSeconds = 5.0, int $maxJobs = 5): int
    {
        $this->calls[] = [$maxSeconds, $maxJobs];
        return 0;
    }
}

final class FakeShutdownRuntime implements ShutdownRuntimeInterface
{
    /** @var list<string> */
    public array $events = [];

    public bool $webSapi = true;

    public bool $developmentServer = false;

    public bool $detached = false;

    public float $safeBudget = 5.0;

    public ?int $lastError = null;

    public ?\Closure $callback = null;

    public int $finishCalls = 0;

    public int $apmCalls = 0;

    #[\Override]
    public function isWebSapi(): bool
    {
        return $this->webSapi;
    }

    #[\Override]
    public function isDevelopmentServer(): bool
    {
        return $this->developmentServer;
    }

    #[\Override]
    public function ignoreUserAbort(): void
    {
        $this->events[] = 'ignore_user_abort';
    }

    #[\Override]
    public function registerShutdownFunction(\Closure $callback): void
    {
        $this->events[] = 'register_shutdown';
        $this->callback = $callback;
    }

    #[\Override]
    public function closeSession(): bool
    {
        return true;
    }

    #[\Override]
    public function finishResponse(): bool
    {
        ++$this->finishCalls;
        return $this->detached;
    }

    #[\Override]
    public function lastErrorType(): ?int
    {
        return $this->lastError;
    }

    #[\Override]
    public function startApmBackgroundTransaction(): void
    {
        ++$this->apmCalls;
    }

    #[\Override]
    public function remainingExecutionSeconds(float $requestStartedAt, float $requestedSeconds): float
    {
        unset($requestStartedAt);
        return min($requestedSeconds, $this->safeBudget);
    }

    public function invokeShutdown(): void
    {
        if (!$this->callback instanceof \Closure) {
            throw new \LogicException('A shutdown callback has not been registered.');
        }

        ($this->callback)();
    }
}
