<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Inbox;

use Register\Core\HttpClient\HttpClientException;
use Register\Core\HttpClient\Remote\RemoteHostResolutionFailed;
use Register\Core\HttpClient\Remote\SafeRemoteResponse;
use Register\Core\HttpClient\Remote\UnsafeRemoteAddress;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueueTimeBudgetExceeded;
use Register\Extension\activitypub\Application\InboxActivityProcessor;
use Register\Extension\activitypub\Application\IncomingActivity;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\InboxState;
use Register\Extension\activitypub\Domain\RemoteActor;
use Register\Extension\activitypub\Infrastructure\ClaimedInboxItem;
use Register\Extension\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\InboxRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Security\SignatureVerificationFailed;

/** Advances one inbox envelope through at most one remote network hop. */
final readonly class InboxQueueHandler implements QueueHandlerInterface
{
    private const int MAX_ATTEMPTS = 12;

    private const int MAX_REDIRECTS = 3;

    private const int PAUSE_POLL_SECONDS = 5 * 60;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private InboxRepository             $inboxRepository,
        private LocalActorRepository         $localActorRepository,
        private RemoteActorRepository       $actorRepository,
        private RemoteActorFetchClient      $fetchClient,
        private RemoteActorDocumentValidator $actorValidator,
        private IncomingSignatureVerifier   $signatureVerifier,
        private InboxActivityProcessor      $activityProcessor,
        private FederationStateRepository   $stateRepository,
        private PortableDatabaseTransaction $transaction,
        private InboxQueue                  $queue,
        ?\Closure                           $clock = null,
        private ?ActivityPubRunnerTelemetryRepository $telemetry = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [InboxQueue::CODE];
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
        if ($id !== InboxQueue::JOB_ID || $code !== InboxQueue::CODE || $payload !== []) {
            throw new \InvalidArgumentException('Invalid ActivityPub inbox wake-up job.');
        }

        $now = ($this->clock)();
        $this->telemetry?->record($code, $now);
        if ($this->stateRepository->lifecycleState() !== FederationLifecycleState::ACTIVE) {
            if ($this->inboxRepository->earliestPendingAt() !== null) {
                $this->queue->wake($now + self::PAUSE_POLL_SECONDS);
            }

            return;
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $this->inboxRepository->recoverStaleClaims($now);
        $item = $this->inboxRepository->claimNext($now);
        if (!$item instanceof ClaimedInboxItem) {
            $this->queue->wakeForNextPending();
            return;
        }

        try {
            $activity = IncomingActivity::fromJson($item->rawBody);
            $actor    = $this->actorRepository->findByUrl($item->actorUrl);
            if ($this->mustFetchActor($item, $actor, $now)) {
                $this->fetchActor($item, $budget, $now);
            } else {
                if (!$actor instanceof RemoteActor) {
                    throw new \LogicException('A fresh remote ActivityPub actor cache entry was expected.');
                }

                $this->verifyAndProcess($item, $activity, $actor, $budget, $now);
            }
        } catch (UnsafeRemoteAddress $exception) {
            $this->fail($item, 'unsafe_address', $exception->getMessage(), $now);
        } catch (QueueTimeBudgetExceeded $exception) {
            $this->delay($item, $now + 1, 'budget', $exception->getMessage(), $now);
        } catch (HttpClientException | RemoteHostResolutionFailed $exception) {
            $this->transientFailure($item, 'network', $exception->getMessage(), $now);
        } catch (SignatureVerificationFailed $exception) {
            if ($item->keyRefreshCount === 0) {
                $this->inboxRepository->requestKeyRefresh($item, $exception->getMessage(), $now);
            } else {
                $this->fail($item, 'signature', $exception->getMessage(), $now);
            }
        } catch (\DomainException | \InvalidArgumentException $exception) {
            $this->fail($item, 'activity_rejected', $exception->getMessage(), $now);
        }

        $this->queue->wakeForNextPending();
    }

    private function mustFetchActor(ClaimedInboxItem $item, ?RemoteActor $actor, int $now): bool
    {
        return $item->forceKeyRefresh
            || !$actor instanceof RemoteActor
            || !$actor->cacheIsFresh($now)
            || !hash_equals($item->keyId, $actor->publicKeyId);
    }

    private function fetchActor(ClaimedInboxItem $item, QueueExecutionBudget $budget, int $now): void
    {
        $fetchUrl = $item->fetchKind === 'actor' ? $item->fetchUrl : $item->actorUrl;
        $remote = $this->fetchClient->fetch(
            $fetchUrl,
            $budget,
            $item->fetchSigned ? $this->signingActorId($item) : null,
            $item->fetchSigned ? $now : null,
        );
        $status = $remote->response->statusCode;
        if ($status >= 200 && $status < 300) {
            $body = $remote->response->content;
            if (!\is_string($body)) {
                $this->fail($item, 'actor_empty', 'The remote actor response has no body.', $now);
                return;
            }

            $actor = $this->actorValidator->validate($item->actorUrl, $item->keyId, $body, $now);
            $this->actorRepository->save($actor);
            $this->inboxRepository->markActorFetched($item, $now);
            return;
        }

        if ($status >= 300 && $status < 400) {
            $this->redirect($item, 'actor', $fetchUrl, $remote, $status, $now);
            return;
        }

        if ($status === 404 || $status === 410) {
            $this->fail($item, 'actor_gone', 'The remote activity actor no longer exists.', $now);
            return;
        }

        if ($status === 401 || $status === 403) {
            if (!$item->fetchSigned) {
                $this->inboxRepository->requestSignedFetch($item, 'actor', $fetchUrl, $now);
            } else {
                $this->fail($item, 'actor_authorization', 'The remote actor endpoint rejected a signed GET.', $now);
            }

            return;
        }

        if ($status === 429) {
            $this->delay($item, $this->retryAfter($remote, $now), 'rate_limited', 'The remote actor endpoint requested a later retry.', $now);
            return;
        }

        if ($status === 408 || $status === 425 || $status >= 500 || $status === 0) {
            $this->transientFailure($item, 'actor_temporary', 'The remote actor endpoint is temporarily unavailable.', $now);
            return;
        }

        $this->fail($item, 'actor_rejected', 'The remote actor endpoint permanently rejected retrieval.', $now);
    }

    private function verifyAndProcess(
        ClaimedInboxItem $item,
        IncomingActivity $activity,
        RemoteActor      $actor,
        QueueExecutionBudget $budget,
        int              $now,
    ): void {
        $verified = $this->signatureVerifier->verify($item, $activity, $actor, $now);
        if ($activity->type === 'Move' && !$this->prepareMoveTarget($item, $activity, $budget, $now)) {
            return;
        }

        if (\in_array($activity->type, ['Create', 'Update'], true) && $activity->objectDocument() === null) {
            if ($item->fetchKind === 'ready') {
                $activity = $activity->withFetchedObject($item->fetchedObjectJson);
            } else {
                $this->fetchObject($item, $activity, $budget, $now);
                return;
            }
        }

        $this->transaction->run(function () use ($item, $activity, $actor, $now, $verified): void {
            $result = $this->activityProcessor->process($item, $activity, $actor, $now);
            $this->inboxRepository->markTerminal(
                $item,
                $result->state,
                '',
                $result->detail,
                $now,
                $verified->keyId,
            );
        });
    }

    private function prepareMoveTarget(
        ClaimedInboxItem     $item,
        IncomingActivity     $activity,
        QueueExecutionBudget $budget,
        int                  $now,
    ): bool {
        $targetUrl = $activity->targetIri();
        if ($targetUrl === null) {
            throw new \DomainException('An ActivityPub Move must identify its target actor.');
        }

        if ($item->fetchKind === 'ready' && $item->fetchedObjectJson !== '') {
            $target = $this->actorValidator->validateForDiscovery($targetUrl, $item->fetchedObjectJson, $now);
            $this->actorRepository->save($target);

            return true;
        }

        $target = $this->actorRepository->findByUrl($targetUrl);
        if ($target instanceof RemoteActor
            && $target->state === 'active'
            && $target->cacheIsFresh($now)
            && \in_array($activity->actorUrl, $target->alsoKnownAs, true)
        ) {
            return true;
        }

        $this->fetchMoveTarget($item, $targetUrl, $budget, $now);

        return false;
    }

    private function fetchMoveTarget(
        ClaimedInboxItem     $item,
        string               $targetUrl,
        QueueExecutionBudget $budget,
        int                  $now,
    ): void {
        $fetchUrl = $item->fetchKind === 'move_target' ? $item->fetchUrl : $targetUrl;
        $remote = $this->fetchClient->fetch(
            $fetchUrl,
            $budget,
            $item->fetchSigned ? $this->signingActorId($item) : null,
            $item->fetchSigned ? $now : null,
        );
        $status = $remote->response->statusCode;
        if ($status >= 200 && $status < 300) {
            $body = $remote->response->content;
            if (!\is_string($body)) {
                $this->fail($item, 'move_target_empty', 'The ActivityPub Move target response has no body.', $now);
                return;
            }

            $this->inboxRepository->markMoveTargetFetched($item, $body, $now);
            return;
        }

        if ($status >= 300 && $status < 400) {
            $this->redirect($item, 'move_target', $fetchUrl, $remote, $status, $now);
            return;
        }

        if ($status === 404 || $status === 410) {
            $this->fail($item, 'move_target_gone', 'The ActivityPub Move target no longer exists.', $now);
            return;
        }

        if ($status === 401 || $status === 403) {
            if (!$item->fetchSigned) {
                $this->inboxRepository->requestSignedFetch($item, 'move_target', $fetchUrl, $now);
            } else {
                $this->fail($item, 'move_target_authorization', 'The ActivityPub Move target rejected a signed GET.', $now);
            }

            return;
        }

        if ($status === 429) {
            $this->delay($item, $this->retryAfter($remote, $now), 'rate_limited', 'The ActivityPub Move target requested a later retry.', $now);
            return;
        }

        if ($status === 408 || $status === 425 || $status >= 500 || $status === 0) {
            $this->transientFailure($item, 'move_target_temporary', 'The ActivityPub Move target is temporarily unavailable.', $now);
            return;
        }

        $this->fail($item, 'move_target_rejected', 'The ActivityPub Move target permanently rejected retrieval.', $now);
    }

    private function fetchObject(
        ClaimedInboxItem     $item,
        IncomingActivity     $activity,
        QueueExecutionBudget $budget,
        int                  $now,
    ): void {
        $objectUrl = $activity->objectIri();
        if ($objectUrl === null) {
            throw new \DomainException('An ActivityPub Create or Update must identify its object.');
        }

        $fetchUrl = $item->fetchKind === 'object' ? $item->fetchUrl : $objectUrl;
        $remote   = $this->fetchClient->fetchObject(
            $fetchUrl,
            $budget,
            $item->fetchSigned ? $this->signingActorId($item) : null,
            $item->fetchSigned ? $now : null,
        );
        $status   = $remote->response->statusCode;
        if ($status >= 200 && $status < 300) {
            $body = $remote->response->content;
            if (!\is_string($body)) {
                $this->fail($item, 'object_empty', 'The remote object response has no body.', $now);
                return;
            }

            $activity->withFetchedObject($body);
            $this->inboxRepository->markObjectFetched($item, $body, $now);
            return;
        }

        if ($status >= 300 && $status < 400) {
            $this->redirect($item, 'object', $fetchUrl, $remote, $status, $now);
            return;
        }

        if ($status === 404 || $status === 410) {
            $this->fail($item, 'object_gone', 'The remote activity object no longer exists.', $now);
            return;
        }

        if ($status === 401 || $status === 403) {
            if (!$item->fetchSigned) {
                $this->inboxRepository->requestSignedFetch($item, 'object', $fetchUrl, $now);
            } else {
                $this->fail($item, 'object_authorization', 'The remote object endpoint rejected a signed GET.', $now);
            }

            return;
        }

        if ($status === 429) {
            $this->delay($item, $this->retryAfter($remote, $now), 'rate_limited', 'The remote object endpoint requested a later retry.', $now);
            return;
        }

        if ($status === 408 || $status === 425 || $status >= 500 || $status === 0) {
            $this->transientFailure($item, 'object_temporary', 'The remote object endpoint is temporarily unavailable.', $now);
            return;
        }

        $this->fail($item, 'object_rejected', 'The remote object endpoint permanently rejected retrieval.', $now);
    }

    private function redirect(
        ClaimedInboxItem  $item,
        string            $fetchKind,
        string            $currentUrl,
        SafeRemoteResponse $remote,
        int               $status,
        int               $now,
    ): void {
        if ($remote->redirectUrl === null) {
            $this->fail($item, 'redirect_missing', 'The remote actor redirect has no usable Location.', $now);
            return;
        }

        if ($item->fetchRedirectCount >= self::MAX_REDIRECTS) {
            $this->fail($item, 'redirect_limit', 'The remote actor exceeded the redirect limit.', $now);
            return;
        }

        try {
            $this->inboxRepository->markFetchRedirected(
                $item,
                $fetchKind,
                $currentUrl,
                $remote->redirectUrl,
                $now,
            );
        } catch (\DomainException | \InvalidArgumentException $exception) {
            $this->fail($item, 'redirect_invalid', $exception->getMessage() . ' HTTP ' . $status . '.', $now);
        }
    }

    private function transientFailure(ClaimedInboxItem $item, string $errorCode, string $detail, int $now): void
    {
        $retryAt = $now + $this->retryDelay($item);
        if ($item->attemptCount >= self::MAX_ATTEMPTS || $retryAt >= $item->rawExpiresAt) {
            $this->fail($item, 'attempts_exhausted', $detail, $now);
            return;
        }

        $this->delay($item, $retryAt, $errorCode, $detail, $now);
    }

    private function delay(ClaimedInboxItem $item, int $availableAt, string $errorCode, string $detail, int $now): void
    {
        $this->inboxRepository->markDelayed($item, $availableAt, $errorCode, $detail, $now);
    }

    private function fail(ClaimedInboxItem $item, string $errorCode, string $detail, int $now): void
    {
        $this->inboxRepository->markTerminal($item, InboxState::FAILED, $errorCode, $detail, $now);
    }

    private function retryDelay(ClaimedInboxItem $item): int
    {
        $base = min(6 * 60 * 60, 30 << min(max(0, $item->attemptCount - 1), 9));
        $jitter = (int)hexdec(substr(hash('sha256', 'inbox:' . $item->id . ':' . $item->attemptCount), 0, 4))
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

    private function signingActorId(ClaimedInboxItem $item): int
    {
        if ($item->targetLocalActorId !== null) {
            $target = $this->localActorRepository->findById($item->targetLocalActorId);
            if ($target instanceof \Register\Extension\activitypub\Domain\LocalActor) {
                if ($target->state === \Register\Extension\activitypub\Domain\LocalActorState::ACTIVE) {
                    return $target->id;
                }
            }
        }

        $siteActor = $this->localActorRepository->siteActor();
        if (!$siteActor instanceof \Register\Extension\activitypub\Domain\LocalActor) {
            throw new \RuntimeException('No active local actor is available for a signed ActivityPub fetch.');
        }

        if ($siteActor->state !== \Register\Extension\activitypub\Domain\LocalActorState::ACTIVE) {
            throw new \RuntimeException('No active local actor is available for a signed ActivityPub fetch.');
        }

        return $siteActor->id;
    }
}
