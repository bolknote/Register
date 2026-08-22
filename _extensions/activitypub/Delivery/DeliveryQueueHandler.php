<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Delivery;

use Register\Core\HttpClient\HttpClientException;
use Register\Core\HttpClient\Remote\RemoteHostResolutionFailed;
use Register\Core\HttpClient\Remote\SafeRemoteResponse;
use Register\Core\HttpClient\Remote\UnsafeRemoteAddress;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueueTimeBudgetExceeded;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Application\FederationLifecycleService;
use Register\Extension\activitypub\Infrastructure\ClaimedDelivery;
use Register\Extension\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use Register\Extension\activitypub\Infrastructure\DeliveryRepository;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;

/** Advances exactly one durable delivery by at most one network hop. */
final readonly class DeliveryQueueHandler implements QueueHandlerInterface
{
    private const int MAX_ATTEMPTS = 12;

    private const int MAX_REDIRECTS = 3;

    private const int PAUSE_POLL_SECONDS = 5 * 60;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private DeliveryRepository        $repository,
        private ActivityDeliveryClient    $client,
        private OriginDeliveryThrottle    $throttle,
        private FederationStateRepository $stateRepository,
        private DeliveryQueue             $queue,
        ?\Closure                         $clock = null,
        private ?FederationLifecycleService $lifecycleService = null,
        private ?ActivityPubRunnerTelemetryRepository $telemetry = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [DeliveryQueue::CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 0.55;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        if ($id !== DeliveryQueue::JOB_ID || $code !== DeliveryQueue::CODE || $payload !== []) {
            throw new \InvalidArgumentException('Invalid ActivityPub delivery wake-up job.');
        }

        $now = ($this->clock)();
        $this->telemetry?->record($code, $now);
        $lifecycle = $this->stateRepository->lifecycleState();
        if (!\in_array($lifecycle, [
            FederationLifecycleState::ACTIVE,
            FederationLifecycleState::DECOMMISSIONING,
        ], true)) {
            if ($this->repository->earliestPendingAt() !== null) {
                $this->queue->wake($now + self::PAUSE_POLL_SECONDS);
            }

            return;
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $this->repository->recoverStaleClaims($now);
        $delivery = $this->repository->claimNext($now);
        if (!$delivery instanceof ClaimedDelivery) {
            $this->queue->wakeForNextPending();
            $this->finishDecommission($now);
            return;
        }

        $retryAt = $this->throttle->claim($delivery->effectiveOrigin, $now);
        if ($retryAt !== null) {
            $this->repository->markDelayed(
                $delivery,
                $retryAt,
                null,
                'origin_throttle',
                'Another delivery already owns this remote-origin request slot.',
                $now,
            );
            $this->repository->recordAttempt(
                $delivery,
                'throttled',
                null,
                'origin_throttle',
                'Deferred without network I/O.',
                $now,
                $now,
            );
            $this->queue->wakeForNextPending();
            return;
        }

        try {
            $response = $this->client->send($delivery, $budget, $now);
            $this->applyResponse($delivery, $response, $now);
        } catch (UnsafeRemoteAddress $exception) {
            $this->fail($delivery, null, 'unsafe_address', $exception->getMessage(), $now);
        } catch (QueueTimeBudgetExceeded $exception) {
            $this->delay($delivery, $now + 1, null, 'budget', $exception->getMessage(), $now, false);
        } catch (HttpClientException | RemoteHostResolutionFailed $exception) {
            $this->transientFailure($delivery, null, 'network', $exception->getMessage(), $now);
        }

        $this->queue->wakeForNextPending();
        $this->finishDecommission($now);
    }

    private function finishDecommission(int $now): void
    {
        if ($this->stateRepository->lifecycleState() === FederationLifecycleState::DECOMMISSIONING) {
            $this->lifecycleService?->finishIfReady($now);
        }
    }

    private function applyResponse(ClaimedDelivery $delivery, SafeRemoteResponse $remote, int $now): void
    {
        $status = $remote->response->statusCode;
        if ($status >= 200 && $status < 300) {
            $this->repository->markDelivered($delivery, $status, $now);
            $this->repository->recordAttempt($delivery, 'delivered', $status, '', 'Remote inbox accepted the activity.', $now, $now);
            $this->throttle->recordSuccess($delivery->effectiveOrigin, $now);
            return;
        }

        if ($status >= 300 && $status < 400) {
            if ($remote->redirectUrl === null) {
                $this->fail($delivery, $status, 'redirect_missing', 'Remote inbox redirect has no usable Location.', $now);
                return;
            }

            if ($delivery->redirectCount >= self::MAX_REDIRECTS) {
                $this->fail($delivery, $status, 'redirect_limit', 'Remote inbox exceeded the redirect limit.', $now);
                return;
            }

            try {
                $this->repository->markRedirected($delivery, $remote->redirectUrl, $now);
                $this->repository->recordAttempt(
                    $delivery,
                    'redirected',
                    $status,
                    'redirect',
                    'A validated redirect will be signed as a separate HTTP hop.',
                    $now,
                    $now,
                );
            } catch (\DomainException | \InvalidArgumentException $exception) {
                $this->fail($delivery, $status, 'redirect_invalid', $exception->getMessage(), $now);
            }

            return;
        }

        if ($status === 401 || $status === 403) {
            if ($delivery->authRefreshCount === 0) {
                $this->delay(
                    $delivery,
                    $now + 30,
                    $status,
                    'auth_refresh',
                    'Remote inbox rejected authentication; one compatibility retry is scheduled.',
                    $now,
                    true,
                );
            } else {
                $this->fail($delivery, $status, 'authentication', 'Remote inbox repeatedly rejected the HTTP signature.', $now);
            }

            return;
        }

        if ($status === 404 || $status === 410) {
            $this->fail($delivery, $status, 'endpoint_gone', 'Remote inbox no longer exists.', $now);
            return;
        }

        if ($status === 429) {
            $retryAt = $this->retryAfter($remote, $now);
            $this->throttle->blockUntil($delivery->effectiveOrigin, $retryAt, $now);
            $this->delay($delivery, $retryAt, $status, 'rate_limited', 'Remote inbox requested a later retry.', $now, false);
            return;
        }

        if ($status === 408 || $status === 425 || $status >= 500 || $status === 0) {
            $this->transientFailure($delivery, $status === 0 ? null : $status, 'remote_temporary', 'Remote inbox is temporarily unavailable.', $now);
            return;
        }

        $this->fail($delivery, $status, 'remote_rejected', 'Remote inbox permanently rejected the activity.', $now);
    }

    private function transientFailure(
        ClaimedDelivery $delivery,
        ?int            $httpStatus,
        string          $errorCode,
        string          $detail,
        int             $now,
    ): void {
        $this->throttle->recordTransientFailure($delivery->effectiveOrigin, $now);
        $retryAt = $now + $this->retryDelay($delivery);
        if ($delivery->attemptCount >= self::MAX_ATTEMPTS || $retryAt >= $delivery->expiresAt) {
            $this->fail($delivery, $httpStatus, 'attempts_exhausted', $detail, $now);
            return;
        }

        $this->delay($delivery, $retryAt, $httpStatus, $errorCode, $detail, $now, false);
    }

    private function delay(
        ClaimedDelivery $delivery,
        int             $availableAt,
        ?int            $httpStatus,
        string          $errorCode,
        string          $detail,
        int             $now,
        bool            $incrementAuthRefresh,
    ): void {
        $this->repository->markDelayed(
            $delivery,
            $availableAt,
            $httpStatus,
            $errorCode,
            $detail,
            $now,
            $incrementAuthRefresh,
        );
        $this->repository->recordAttempt(
            $delivery,
            'delayed',
            $httpStatus,
            $errorCode,
            $detail,
            $now,
            $now,
        );
    }

    private function fail(
        ClaimedDelivery $delivery,
        ?int            $httpStatus,
        string          $errorCode,
        string          $detail,
        int             $now,
    ): void {
        $this->repository->markFailed($delivery, $httpStatus, $errorCode, $detail, $now);
        $this->repository->recordAttempt(
            $delivery,
            'failed',
            $httpStatus,
            $errorCode,
            $detail,
            $now,
            $now,
        );
    }

    private function retryDelay(ClaimedDelivery $delivery): int
    {
        $base   = min(6 * 60 * 60, 30 << min(max(0, $delivery->attemptCount - 1), 9));
        $jitter = (int)hexdec(substr(hash('sha256', $delivery->id . ':' . $delivery->attemptCount), 0, 4))
            % max(1, intdiv($base, 5));

        return $base + $jitter;
    }

    private function retryAfter(SafeRemoteResponse $remote, int $now): int
    {
        $value = trim($remote->response->getHeader('Retry-After') ?? '');
        if (preg_match('/^[0-9]{1,7}$/D', $value) === 1) {
            $timestamp = $now + (int)$value;
        } else {
            $parsed    = $value === '' ? false : strtotime($value);
            $timestamp = $parsed === false ? $now + 5 * 60 : $parsed;
        }

        return min($now + 24 * 60 * 60, max($now + 1, $timestamp));
    }
}
