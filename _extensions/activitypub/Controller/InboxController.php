<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Controller;

use Psr\Log\LoggerInterface;
use S2\Cms\Framework\ControllerInterface;
use s2_extensions\activitypub\Application\InboxRateLimiter;
use s2_extensions\activitypub\Application\ActivationProbeService;
use s2_extensions\activitypub\Application\InboxRequestException;
use s2_extensions\activitypub\Application\InboxRequestValidator;
use s2_extensions\activitypub\Application\PublicFederationAccess;
use s2_extensions\activitypub\Domain\FederationLifecycleState;
use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalActorState;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Inbox\InboxQueue;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\InboxRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\NewInboxItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Persists a bounded first-seen envelope and returns before any remote network access. */
final readonly class InboxController implements ControllerInterface
{
    private const int IP_LIMIT = 60;

    private const int ACTOR_ORIGIN_LIMIT = 120;

    private const int RATE_WINDOW_SECONDS = 60;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private FederationStateRepository     $stateRepository,
        private LocalActorRepository          $actorRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private PublicFederationAccess        $access,
        private InboxRequestValidator         $validator,
        private InboxRateLimiter              $rateLimiter,
        private InboxRepository               $inboxRepository,
        private InboxQueue                    $queue,
        private ActivityPubResponseFactory    $responseFactory,
        private LoggerInterface                $logger,
        ?\Closure                             $clock = null,
        private ?ActivationProbeService        $activationProbeService = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (!$this->access->installationIsPublic()) {
            $publicId = $request->attributes->getString('publicId');
            try {
                if ($publicId !== '' && $this->activationProbeService?->acceptInbox($publicId, $request) === true) {
                    return $this->responseFactory->accepted();
                }
            } catch (InboxRequestException $exception) {
                return $this->responseFactory->inboxError($request, $exception->httpStatus, $exception->getMessage());
            }

            return $this->responseFactory->notFound($request);
        }

        $state = $this->stateRepository->lifecycleState();
        if ($state === FederationLifecycleState::DECOMMISSIONING
            || $state === FederationLifecycleState::DECOMMISSIONED
        ) {
            return $this->responseFactory->gone($request);
        }

        $publicId = $request->attributes->getString('publicId');
        $actor    = null;
        if ($publicId !== '') {
            $actor = $this->actorRepository->findByPublicId($publicId);
            if (!$actor instanceof LocalActor || !$this->access->actorIsPublic($actor)) {
                return $this->responseFactory->notFound($request);
            }

            if ($actor->state !== LocalActorState::ACTIVE) {
                return $this->responseFactory->gone($request);
            }
        }

        $now = ($this->clock)();
        try {
            $ip = trim($request->getClientIp() ?? 'unknown');
            $retryAt = $this->rateLimiter->consume(
                'inbox_ip',
                $ip === '' ? 'unknown' : $ip,
                self::IP_LIMIT,
                self::RATE_WINDOW_SECONDS,
                $now,
            );
            if ($retryAt !== null) {
                return $this->rateLimited($request, $retryAt, $now);
            }

            $urls      = $this->urlGeneratorFactory->create();
            $targetUri = $actor instanceof LocalActor
                ? $urls->actorInbox($actor->publicId)
                : $urls->sharedInbox();
            $validated = $this->validator->validate($request, $targetUri);
            $retryAt = $this->rateLimiter->consume(
                'inbox_actor',
                $validated->effectiveOrigin,
                self::ACTOR_ORIGIN_LIMIT,
                self::RATE_WINDOW_SECONDS,
                $now,
            );
            if ($retryAt !== null) {
                return $this->rateLimited($request, $retryAt, $now);
            }

            $this->inboxRepository->receive(new NewInboxItem(
                $actor?->id,
                $validated->activity->type,
                $validated->activity->id,
                $validated->activity->actorUrl,
                $validated->keyId,
                $validated->signatureType,
                $validated->effectiveOrigin,
                $validated->rawBody,
                $validated->transportJson,
                $now,
            ));
            $this->queue->wakeForNextPending();
        } catch (InboxRequestException $exception) {
            return $this->responseFactory->inboxError(
                $request,
                $exception->httpStatus,
                $exception->getMessage(),
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to durably accept an ActivityPub inbox envelope.', [
                'exception' => $exception,
                'request_id' => substr(hash('sha256', $request->getContent()), 0, 16),
            ]);

            return $this->responseFactory->inboxError(
                $request,
                Response::HTTP_SERVICE_UNAVAILABLE,
                'The ActivityPub inbox cannot durably accept this request right now.',
                60,
            );
        }

        return $this->responseFactory->accepted();
    }

    private function rateLimited(Request $request, int $retryAt, int $now): Response
    {
        return $this->responseFactory->inboxError(
            $request,
            Response::HTTP_TOO_MANY_REQUESTS,
            'The ActivityPub inbox rate limit has been exceeded.',
            max(1, $retryAt - $now),
        );
    }
}
