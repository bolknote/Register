<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Media;

use Psr\Log\LoggerInterface;
use S2\Cms\HttpClient\HttpClientException;
use S2\Cms\HttpClient\Remote\RemoteHostResolutionFailed;
use S2\Cms\HttpClient\Remote\SafeRemoteResponse;
use S2\Cms\HttpClient\Remote\UnsafeRemoteAddress;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueueTimeBudgetExceeded;
use s2_extensions\activitypub\Domain\FederationLifecycleState;
use s2_extensions\activitypub\Infrastructure\ClaimedRemoteAvatar;
use s2_extensions\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\RemoteAvatarRepository;

/** Advances one durable remote avatar by exactly one SSRF-safe network hop. */
final readonly class RemoteAvatarQueueHandler implements QueueHandlerInterface
{
    private const int MAX_ATTEMPTS = 8;

    private const int MAX_REDIRECTS = 3;

    private const int PAUSE_POLL_SECONDS = 5 * 60;

    private const int PERMANENT_RECHECK_SECONDS = 7 * 24 * 60 * 60;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private RemoteAvatarRepository    $repository,
        private RemoteAvatarFetchClient   $client,
        private RemoteAvatarImageInspector $inspector,
        private RemoteAvatarStorage       $storage,
        private FederationStateRepository $stateRepository,
        private RemoteAvatarQueue         $queue,
        private LoggerInterface           $logger,
        ?\Closure                         $clock = null,
        private ?ActivityPubRunnerTelemetryRepository $telemetry = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [RemoteAvatarQueue::CODE];
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
        if ($id !== RemoteAvatarQueue::JOB_ID || $code !== RemoteAvatarQueue::CODE || $payload !== []) {
            throw new \InvalidArgumentException('Invalid ActivityPub remote avatar wake-up job.');
        }

        $now = ($this->clock)();
        $this->telemetry?->record($code, $now);
        $lifecycle = $this->stateRepository->lifecycleState();
        if ($lifecycle !== FederationLifecycleState::ACTIVE) {
            if ($lifecycle === FederationLifecycleState::PAUSED && $this->repository->earliestPendingAt() !== null) {
                $this->queue->wake($now + self::PAUSE_POLL_SECONDS);
            }

            return;
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $this->repository->recoverStaleClaims($now);
        $avatar = $this->repository->claimNext($now);
        if (!$avatar instanceof ClaimedRemoteAvatar) {
            $this->queue->wakeForNextPending();
            return;
        }

        try {
            $cacheUsable = $this->storage->matches(
                $avatar->storageKey,
                $avatar->contentHash,
                $avatar->byteSize,
            );
            $response = $this->client->fetch(
                $avatar->requestUrl,
                $cacheUsable ? $avatar->conditionalEtag() : null,
                $cacheUsable ? $avatar->conditionalLastModified() : null,
                $budget,
            );
            $this->applyResponse($avatar, $response, $cacheUsable, $now);
        } catch (UnsafeRemoteAddress $exception) {
            $this->fail($avatar, null, 'unsafe_address', $exception->getMessage(), $now);
        } catch (QueueTimeBudgetExceeded $exception) {
            $this->repository->markDelayed($avatar, $now + 1, null, 'budget', $exception->getMessage(), $now);
        } catch (HttpClientException | RemoteHostResolutionFailed $exception) {
            $this->transientFailure($avatar, null, 'network', $exception->getMessage(), $now);
        }

        $this->queue->wakeForNextPending();
    }

    private function applyResponse(
        ClaimedRemoteAvatar $avatar,
        SafeRemoteResponse  $remote,
        bool                $cacheUsable,
        int                 $now,
    ): void {
        $status = $remote->response->statusCode;
        if ($status === 304) {
            if (!$cacheUsable || !$this->repository->markNotModified(
                $avatar,
                $this->etag($remote),
                $this->lastModified($remote),
                $now,
            )) {
                $this->fail($avatar, $status, 'invalid_not_modified', 'The remote server returned 304 without a usable cached representation.', $now);
            }

            return;
        }

        if ($status === 200) {
            $content = $remote->response->content;
            if (!\is_string($content)) {
                $this->fail($avatar, $status, 'missing_body', 'The remote avatar response has no body.', $now);
                return;
            }

            try {
                $image = $this->inspector->inspect($content, $remote->response->getHeader('Content-Type'));
                $storageKey = $this->storage->publish($content, $image, $avatar->publicId);
                $result = $this->repository->markReady(
                    $avatar,
                    $image,
                    $storageKey,
                    $this->etag($remote),
                    $this->lastModified($remote),
                    $status,
                    $now,
                );
                if (!$result->published && $storageKey !== $avatar->storageKey) {
                    $this->removeBestEffort($storageKey);
                }

                if ($result->replacedStorageKey !== null) {
                    $this->removeBestEffort($result->replacedStorageKey);
                }
            } catch (\DomainException $exception) {
                $this->fail($avatar, $status, 'invalid_image', $exception->getMessage(), $now);
            } catch (\RuntimeException $exception) {
                $this->transientFailure($avatar, $status, 'local_storage', $exception->getMessage(), $now);
            }

            return;
        }

        if ($status >= 300 && $status < 400) {
            if ($remote->redirectUrl === null) {
                $this->fail($avatar, $status, 'redirect_missing', 'The remote avatar redirect has no usable Location.', $now);
                return;
            }

            if ($avatar->redirectCount >= self::MAX_REDIRECTS) {
                $this->fail($avatar, $status, 'redirect_limit', 'The remote avatar exceeded the redirect limit.', $now);
                return;
            }

            try {
                $this->repository->markRedirected($avatar, $remote->redirectUrl, $status, $now);
            } catch (\DomainException | \InvalidArgumentException $exception) {
                $this->fail($avatar, $status, 'redirect_invalid', $exception->getMessage(), $now);
            }

            return;
        }

        if ($status === 404 || $status === 410) {
            $this->fail($avatar, $status, 'not_found', 'The remote avatar no longer exists.', $now);
            return;
        }

        if ($status === 429) {
            $this->repository->markDelayed(
                $avatar,
                $this->retryAfter($remote, $now),
                $status,
                'rate_limited',
                'The remote avatar host requested a later retry.',
                $now,
            );
            return;
        }

        if ($status === 408 || $status === 425 || $status >= 500 || $status === 0) {
            $this->transientFailure($avatar, $status === 0 ? null : $status, 'remote_temporary', 'The remote avatar host is temporarily unavailable.', $now);
            return;
        }

        $this->fail($avatar, $status, 'remote_rejected', 'The remote avatar request was permanently rejected.', $now);
    }

    private function transientFailure(
        ClaimedRemoteAvatar $avatar,
        ?int                $httpStatus,
        string              $errorCode,
        string              $detail,
        int                 $now,
    ): void {
        if ($avatar->attemptCount >= self::MAX_ATTEMPTS || $now >= $avatar->giveUpAt) {
            $this->fail($avatar, $httpStatus, 'attempts_exhausted', $detail, $now);
            return;
        }

        $delay = min(6 * 60 * 60, 60 * (1 << min(7, max(0, $avatar->attemptCount - 1))));
        $this->repository->markDelayed(
            $avatar,
            min($avatar->giveUpAt, $now + $delay),
            $httpStatus,
            $errorCode,
            $detail,
            $now,
        );
    }

    private function fail(
        ClaimedRemoteAvatar $avatar,
        ?int                $httpStatus,
        string              $errorCode,
        string              $detail,
        int                 $now,
    ): void {
        $this->repository->markFailed(
            $avatar,
            $httpStatus,
            $errorCode,
            $detail,
            $now + self::PERMANENT_RECHECK_SECONDS,
            $now,
        );
    }

    private function retryAfter(SafeRemoteResponse $remote, int $now): int
    {
        $value = $remote->response->getHeader('Retry-After');
        if ($value !== null) {
            $value = trim($value);
            if (ctype_digit($value)) {
                return $now + min(24 * 60 * 60, max(60, (int)$value));
            }

            $date = strtotime($value);
            if ($date !== false && $date > $now) {
                return min($now + 24 * 60 * 60, max($now + 60, $date));
            }
        }

        return $now + 60 * 60;
    }

    private function etag(SafeRemoteResponse $remote): ?string
    {
        return $this->safeValidatorHeader($remote->response->getHeader('ETag'), 255);
    }

    private function lastModified(SafeRemoteResponse $remote): ?string
    {
        $value = $this->safeValidatorHeader($remote->response->getHeader('Last-Modified'), 128);
        return $value !== null && strtotime($value) !== false ? $value : null;
    }

    private function safeValidatorHeader(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || \strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function removeBestEffort(string $storageKey): void
    {
        try {
            $this->storage->remove($storageKey);
        } catch (\RuntimeException $exception) {
            $this->logger->warning('Unable to remove a superseded remote avatar cache file.', [
                'storage_key' => $storageKey,
                'exception'   => $exception,
            ]);
        }
    }
}
