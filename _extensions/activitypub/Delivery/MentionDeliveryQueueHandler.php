<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Delivery;

use Psr\Log\LoggerInterface;
use Register\Core\HttpClient\Remote\SafeRemoteResponse;
use Register\Core\HttpClient\Remote\UnsafeRemoteAddress;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\RemoteActor;
use Register\Extension\activitypub\Inbox\RemoteActorDocumentValidator;
use Register\Extension\activitypub\Inbox\RemoteActorFetchClient;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation;

/** Resolves one previously unknown Mention actor, then materializes its durable delivery. */
final readonly class MentionDeliveryQueueHandler implements QueueHandlerInterface
{
    private const int MAX_REDIRECTS = 3;

    private const int DELIVERY_TTL_SECONDS = 7 * 24 * 60 * 60;

    private const int PAUSE_POLL_SECONDS = 5 * 60;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private LocalFederationRepository     $federationRepository,
        private RemoteActorRepository         $actorRepository,
        private RemoteActorFetchClient        $fetchClient,
        private RemoteActorDocumentValidator  $actorValidator,
        private FederationStateRepository     $stateRepository,
        private MentionDeliveryPlanner        $planner,
        private MentionDeliveryQueue          $queue,
        private LoggerInterface               $logger,
        ?\Closure                             $clock = null,
        private ?ActivityPubRunnerTelemetryRepository $telemetry = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [MentionDeliveryQueue::CODE];
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
        $activityId = $payload['activity_id'] ?? null;
        $localActorId = $payload['local_actor_id'] ?? null;
        $remoteActorUrl = $payload['remote_actor_url'] ?? null;
        if ($code !== MentionDeliveryQueue::CODE
            || \count($payload) !== 3
            || !\is_int($activityId)
            || !\is_int($localActorId)
            || !\is_string($remoteActorUrl)
            || !hash_equals(MentionDeliveryQueue::jobId($activityId, $remoteActorUrl), $id)
        ) {
            throw new \InvalidArgumentException('Invalid ActivityPub Mention discovery job.');
        }

        $now = ($this->clock)();
        $this->telemetry?->record($code, $now);
        $lifecycle = $this->stateRepository->lifecycleState();
        if ($lifecycle === FederationLifecycleState::PAUSED) {
            $this->queue->reschedule($activityId, $localActorId, $remoteActorUrl, $now + self::PAUSE_POLL_SECONDS);
            return;
        }

        if ($lifecycle !== FederationLifecycleState::ACTIVE) {
            return;
        }

        $activity = $this->federationRepository->findActivityById($activityId);
        if (!$activity instanceof StoredActivityRepresentation) {
            return;
        }

        if ($activity->actorId !== $localActorId
            || $activity->deliveryIntent !== ActivityDeliveryIntent::FOLLOWERS
            || $activity->publishedAt + self::DELIVERY_TTL_SECONDS <= $now
        ) {
            return;
        }

        $actor = $this->actorRepository->findByUrl($remoteActorUrl);
        if (!$actor instanceof RemoteActor) {
            $budget->checkpoint($this->minimumExecutionTime());
            try {
                $actor = $this->fetchActor($activity, $remoteActorUrl, $budget, $now);
            } catch (UnsafeRemoteAddress $exception) {
                $this->logger->warning('Deferred ActivityPub Mention actor was rejected by SSRF policy.', [
                    'activity_id' => $activity->id,
                    'actor_url'   => $remoteActorUrl,
                    'error'       => $exception->getMessage(),
                ]);
                return;
            } catch (\DomainException | \InvalidArgumentException $exception) {
                $this->logger->warning('Deferred ActivityPub Mention actor document was rejected.', [
                    'activity_id' => $activity->id,
                    'actor_url'   => $remoteActorUrl,
                    'error'       => $exception->getMessage(),
                ]);
                return;
            }
        }

        if ($actor instanceof RemoteActor) {
            $this->planner->planRecipients($activity, [$remoteActorUrl], $now);
        }
    }

    private function fetchActor(
        StoredActivityRepresentation $activity,
        string                       $expectedActorUrl,
        QueueExecutionBudget         $budget,
        int                          $now,
    ): ?RemoteActor {
        $url = $expectedActorUrl;
        $redirects = [];
        $signed = false;
        while (true) {
            $remote = $this->fetchClient->fetch(
                $url,
                $budget,
                $signed ? $activity->actorId : null,
                $signed ? $now : null,
            );
            $status = $remote->response->statusCode;
            if ($status >= 200 && $status < 300) {
                $body = $remote->response->content;
                if (!\is_string($body)) {
                    throw new \DomainException('The deferred ActivityPub Mention actor response has no body.');
                }

                $fetched = $this->actorValidator->validateForDiscovery($expectedActorUrl, $body, $now);

                return $this->actorRepository->save($fetched);
            }

            if ($status >= 300 && $status < 400) {
                $next = $remote->redirectUrl;
                if ($next === null || \count($redirects) >= self::MAX_REDIRECTS || isset($redirects[$next])) {
                    throw new \DomainException('The deferred ActivityPub Mention actor has an invalid redirect chain.');
                }

                $redirects[$url] = true;
                $url = $next;
                $signed = false;
                continue;
            }

            if (($status === 401 || $status === 403) && !$signed) {
                $signed = true;
                continue;
            }

            if ($status === 429) {
                $this->queue->reschedule(
                    $activity->id,
                    $activity->actorId,
                    $expectedActorUrl,
                    $this->retryAfter($remote, $now),
                );
                return null;
            }

            if ($status === 408 || $status === 425 || $status >= 500 || $status === 0) {
                throw new \RuntimeException('The deferred ActivityPub Mention actor endpoint is temporarily unavailable.');
            }

            if ($status === 404 || $status === 410) {
                throw new \DomainException('The deferred ActivityPub Mention actor no longer exists.');
            }

            throw new \DomainException('The deferred ActivityPub Mention actor endpoint rejected retrieval.');
        }
    }

    private function retryAfter(SafeRemoteResponse $remote, int $now): int
    {
        $value = trim($remote->response->getHeader('Retry-After') ?? '');
        if (preg_match('/^[0-9]{1,7}$/D', $value) === 1) {
            $timestamp = $now + (int)$value;
        } else {
            $parsed = $value === '' ? false : strtotime($value);
            $timestamp = $parsed === false ? $now + 5 * 60 : $parsed;
        }

        return min($now + 24 * 60 * 60, max($now + 1, $timestamp));
    }
}
