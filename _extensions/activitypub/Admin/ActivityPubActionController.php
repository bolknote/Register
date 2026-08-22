<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Admin;

use Psr\Log\LoggerInterface;
use S2\AdminYard\Translator;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Security\Http\AdminMutationGuard;
use s2_extensions\activitypub\Application\OutgoingFollowService;
use s2_extensions\activitypub\Application\AuthorActorDraft;
use s2_extensions\activitypub\Application\AuthorActorService;
use s2_extensions\activitypub\Application\ActivationReadinessStarter;
use s2_extensions\activitypub\Application\FederationActivationService;
use s2_extensions\activitypub\Application\SiteActorDraft;
use s2_extensions\activitypub\Application\ActorKeyRotationService;
use s2_extensions\activitypub\Application\ActorIdentityMigrationService;
use s2_extensions\activitypub\Application\FederationLifecycleService;
use s2_extensions\activitypub\Application\FederationPolicyService;
use s2_extensions\activitypub\Application\OutgoingInteractionService;
use s2_extensions\activitypub\Application\OutgoingReplyService;
use s2_extensions\activitypub\Application\ContentBackfillStarter;
use Register\Content\ContentId;
use s2_extensions\activitypub\Delivery\DeliveryQueue;
use s2_extensions\activitypub\Discovery\RemoteActorDiscovery;
use s2_extensions\activitypub\Domain\ModerationAction;
use s2_extensions\activitypub\Domain\ActorType;
use s2_extensions\activitypub\Domain\CanonicalBasePath;
use s2_extensions\activitypub\Domain\CanonicalOrigin;
use s2_extensions\activitypub\Domain\ContentDeliveryMode;
use s2_extensions\activitypub\Domain\FederationPolicy;
use s2_extensions\activitypub\Domain\LocalHandle;
use s2_extensions\activitypub\Domain\PostObjectType;
use s2_extensions\activitypub\Domain\RemoteActor;
use s2_extensions\activitypub\Inbox\InboxQueue;
use s2_extensions\activitypub\Media\RemoteAvatarQueue;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\ModerationRuleRepository;
use s2_extensions\activitypub\Infrastructure\RemoteActorRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ActivityPubActionController
{
    public function __construct(
        private PermissionChecker          $permissionChecker,
        private ActivityPubAdminAccess     $access,
        private ActivityPubToken           $token,
        private AdminMutationGuard         $mutationGuard,
        private RemoteActorDiscovery       $actorDiscovery,
        private RemoteActorRepository      $remoteActorRepository,
        private LocalActorRepository       $localActorRepository,
        private OutgoingFollowService      $followService,
        private OutgoingReplyService       $replyService,
        private OutgoingInteractionService $interactionService,
        private ActorKeyRotationService    $keyRotationService,
        private ActorIdentityMigrationService $identityMigrationService,
        private FederationLifecycleService $lifecycleService,
        private FederationPolicyService    $policyService,
        private ActivationReadinessStarter $activationStarter,
        private FederationActivationService $activationService,
        private AuthorActorService          $authorActorService,
        private ModerationRuleRepository   $moderationRepository,
        private DeliveryQueue              $deliveryQueue,
        private InboxQueue                 $inboxQueue,
        private RemoteAvatarQueue          $avatarQueue,
        private ContentBackfillStarter     $backfillStarter,
        private Translator                 $translator,
        private LoggerInterface            $logger,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        if (!$this->mutationGuard->isPost($request)) {
            return $this->error('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$this->access->canAccess()) {
            return $this->error('Permission denied.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->mutationGuard->hasValidCsrfToken($request, $this->token->value())) {
            return $this->error('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $operation = $request->request->getString('operation');
        if (!$this->access->canPerform(
            $operation,
            $request->request->getInt('author_id'),
            $request->request->getInt('local_actor_id'),
        )) {
            return $this->error('Permission denied.', Response::HTTP_FORBIDDEN);
        }

        try {
            return match ($operation) {
                'discover'   => $this->discover($request),
                'setup_start' => $this->setupStart($request),
                'setup_activate' => $this->setupActivate($request),
                'policy_save' => $this->savePolicy($request),
                'author_save' => $this->saveAuthor($request),
                'follow'     => $this->follow($request),
                'unfollow'   => $this->unfollow($request),
                'reply'      => $this->reply($request),
                'reply_update' => $this->updateReply($request),
                'reply_delete' => $this->deleteReply($request),
                'like'       => $this->interaction($request, 'like'),
                'unlike'     => $this->undoInteraction($request, 'like'),
                'emoji'      => $this->interaction($request, 'emoji_react'),
                'unemoji'    => $this->undoInteraction($request, 'emoji_react'),
                'announce'   => $this->interaction($request, 'announce'),
                'unannounce' => $this->undoInteraction($request, 'announce'),
                'moderate'   => $this->moderate($request),
                'push_queue' => $this->pushQueue(),
                'pause'      => $this->pause(),
                'resume'     => $this->resume(),
                'rotate_key' => $this->rotateKey($request),
                'change_handle' => $this->changeHandle($request),
                'move_actor' => $this->moveActor($request),
                'decommission' => $this->decommission($request),
                'backfill_latest' => $this->backfillLatest($request),
                'backfill_selected' => $this->backfillSelected($request),
                default      => $this->error('Unknown ActivityPub action.', Response::HTTP_BAD_REQUEST),
            };
        } catch (\DomainException | \InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, false);
        } catch (\Throwable $exception) {
            $this->logger->error('ActivityPub admin action failed.', [
                'operation' => $operation,
                'exception' => $exception,
            ]);

            return $this->error('The ActivityPub action could not be completed.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function discover(Request $request): JsonResponse
    {
        $localActorId = $request->request->getInt('local_actor_id');
        if (!$this->localActorRepository->findById($localActorId) instanceof \s2_extensions\activitypub\Domain\LocalActor) {
            throw new \DomainException('The selected local ActivityPub actor does not exist.');
        }

        $actor = $this->actorDiscovery->discover(
            $request->request->getString('handle'),
            $localActorId,
            time(),
        );

        return $this->success('ActivityPub actor verified.', [
            'actor' => $this->actorPayload($actor),
        ]);
    }

    private function setupStart(Request $request): JsonResponse
    {
        $actorType = ActorType::tryFrom($request->request->getString('actor_type'));
        if (!$actorType instanceof ActorType || $actorType === ActorType::PERSON) {
            throw new \InvalidArgumentException('The collective ActivityPub actor type is invalid.');
        }

        $attempt = $this->activationStarter->start(
            new SiteActorDraft(
                $actorType,
                new LocalHandle($request->request->getString('handle')),
                $request->request->getString('display_name'),
                $request->request->getString('summary_html'),
                $request->request->getString('profile_url'),
                $this->media($request, 'avatar_url'),
                $this->media($request, 'header_url'),
                discoverable: $request->request->getBoolean('discoverable'),
            ),
            new CanonicalOrigin($request->request->getString('canonical_origin')),
            new CanonicalBasePath($request->request->getString('base_path')),
        );

        return $this->success($attempt->state->value === 'checking'
            ? 'ActivityPub activation checks queued.'
            : 'ActivityPub activation checks found blocking errors.', [
                'attempt_id' => $attempt->id,
                'state'      => $attempt->state->value,
            ]);
    }

    private function setupActivate(Request $request): JsonResponse
    {
        if (!hash_equals('ACTIVATE', $request->request->getString('confirmation'))) {
            throw new \DomainException('ActivityPub activation confirmation is invalid.');
        }

        $actor = $this->activationService->activateAttempt($request->request->getString('attempt_id'));

        return $this->success('ActivityPub federation activated.', ['actor_public_id' => $actor->publicId]);
    }

    private function savePolicy(Request $request): JsonResponse
    {
        $postObjectType = PostObjectType::tryFrom($request->request->getString('post_object_type'));
        $contentMode = ContentDeliveryMode::tryFrom($request->request->getString('content_mode'));
        $defaultVisibility = $request->request->getString('default_visibility');
        if (!$postObjectType instanceof PostObjectType
            || !$contentMode instanceof ContentDeliveryMode
            || !\in_array($defaultVisibility, ['public', 'unlisted'], true)
        ) {
            throw new \InvalidArgumentException('The ActivityPub federation policy is invalid.');
        }

        $this->policyService->save(new FederationPolicy(
            $request->request->getBoolean('posts_enabled'),
            $request->request->getBoolean('pages_enabled'),
            $postObjectType,
            $contentMode,
            $defaultVisibility,
        ));

        return $this->success('ActivityPub federation policy saved.');
    }

    private function saveAuthor(Request $request): JsonResponse
    {
        $actor = $this->authorActorService->save(new AuthorActorDraft(
            $request->request->getInt('author_id'),
            new LocalHandle($request->request->getString('handle')),
            $request->request->getString('display_name'),
            $request->request->getString('summary_html'),
            $request->request->getString('profile_url'),
            $this->media($request, 'avatar_url'),
            $this->media($request, 'header_url'),
            discoverable: $request->request->getBoolean('discoverable'),
        ));

        return $this->success('ActivityPub author identity saved.', [
            'actor_public_id' => $actor->publicId,
            'actor_id'        => $actor->id,
        ]);
    }

    private function follow(Request $request): JsonResponse
    {
        $activity = $this->followService->follow(
            $request->request->getInt('local_actor_id'),
            $request->request->getInt('remote_actor_id'),
        );

        return $this->success('ActivityPub Follow queued.', ['activity_id' => $activity->publicId]);
    }

    private function unfollow(Request $request): JsonResponse
    {
        $activity = $this->followService->unfollow(
            $request->request->getInt('local_actor_id'),
            $request->request->getInt('remote_actor_id'),
        );

        return $this->success($activity instanceof \s2_extensions\activitypub\Infrastructure\StoredActivityRepresentation
            ? 'ActivityPub Undo Follow queued.'
            : 'The ActivityPub follow had already ended.');
    }

    private function reply(Request $request): JsonResponse
    {
        $note = $this->replyService->reply(
            $request->request->getInt('local_actor_id'),
            $request->request->getInt('remote_object_id'),
            $request->request->getString('text'),
            $request->request->getString('visibility', 'public'),
        );

        return $this->success('ActivityPub reply queued.', ['object_id' => $note->publicId]);
    }

    private function updateReply(Request $request): JsonResponse
    {
        $note = $this->replyService->update(
            $request->request->getInt('local_actor_id'),
            $request->request->getInt('local_note_id'),
            $request->request->getString('text'),
        );

        return $this->success('ActivityPub reply update queued.', ['object_id' => $note->publicId]);
    }

    private function deleteReply(Request $request): JsonResponse
    {
        $note = $this->replyService->delete(
            $request->request->getInt('local_actor_id'),
            $request->request->getInt('local_note_id'),
        );

        return $this->success('ActivityPub reply deletion queued.', ['object_id' => $note->publicId]);
    }

    private function interaction(Request $request, string $type): JsonResponse
    {
        $interaction = $this->interactionService->create(
            $request->request->getInt('local_actor_id'),
            $request->request->getInt('remote_object_id'),
            $type,
            $type === 'emoji_react' ? $request->request->getString('emoji') : '',
        );

        return $this->success('ActivityPub interaction queued.', ['interaction_id' => $interaction->id]);
    }

    private function undoInteraction(Request $request, string $type): JsonResponse
    {
        $interaction = $this->interactionService->undo(
            $request->request->getInt('local_actor_id'),
            $request->request->getInt('remote_object_id'),
            $type,
            $type === 'emoji_react' ? $request->request->getString('emoji') : '',
        );

        if (!$interaction instanceof \s2_extensions\activitypub\Domain\LocalInteraction) {
            return $this->success('The ActivityPub interaction had already ended.');
        }

        return $this->success($interaction->state !== 'ended'
            ? 'The ActivityPub interaction had already ended.'
            : 'ActivityPub interaction Undo queued.');
    }

    private function moderate(Request $request): JsonResponse
    {
        $actor = $this->remoteActorRepository->findById($request->request->getInt('remote_actor_id'));
        if (!$actor instanceof RemoteActor) {
            throw new \DomainException('The remote ActivityPub actor does not exist.');
        }

        $action = ModerationAction::tryFrom($request->request->getString('moderation_action'));
        if (!$action instanceof ModerationAction) {
            throw new \InvalidArgumentException('The ActivityPub moderation action is invalid.');
        }

        $this->moderationRepository->store(
            'actor',
            $actor->actorUrl,
            $action,
            1_000,
            ['source' => 'admin'],
            time(),
        );

        return $this->success('ActivityPub moderation rule saved.');
    }

    private function pushQueue(): JsonResponse
    {
        $this->deliveryQueue->wakeForNextPending();
        $this->inboxQueue->wakeForNextPending();
        $this->avatarQueue->wakeForNextPending();

        return $this->success('ActivityPub queue wake-up scheduled.');
    }

    private function pause(): JsonResponse
    {
        $this->lifecycleService->pause();

        return $this->success('ActivityPub federation paused.');
    }

    private function resume(): JsonResponse
    {
        $this->lifecycleService->resume();

        return $this->success('ActivityPub federation resumed.');
    }

    private function rotateKey(Request $request): JsonResponse
    {
        $key = $this->keyRotationService->rotate($request->request->getInt('local_actor_id'));

        return $this->success('ActivityPub actor key rotated.', ['key_public_id' => $key->publicId]);
    }

    private function changeHandle(Request $request): JsonResponse
    {
        $activity = $this->identityMigrationService->changeHandle(
            $request->request->getInt('local_actor_id'),
            $request->request->getString('new_handle'),
        );

        return $this->success($activity instanceof \s2_extensions\activitypub\Infrastructure\StoredActivityRepresentation
            ? 'ActivityPub actor handle changed and Update queued.'
            : 'The ActivityPub actor already uses this handle.');
    }

    private function moveActor(Request $request): JsonResponse
    {
        if (!hash_equals('MOVE', $request->request->getString('confirmation'))) {
            throw new \DomainException('ActivityPub Move confirmation is invalid.');
        }

        $activity = $this->identityMigrationService->move(
            $request->request->getInt('local_actor_id'),
            $request->request->getInt('remote_actor_id'),
        );

        return $this->success('ActivityPub actor Move queued.', ['activity_id' => $activity->publicId]);
    }

    private function decommission(Request $request): JsonResponse
    {
        if (!hash_equals('DECOMMISSION', $request->request->getString('confirmation'))) {
            throw new \DomainException('ActivityPub decommission confirmation is invalid.');
        }

        $count = $this->lifecycleService->decommission();

        return $this->success('ActivityPub decommission started.', ['delete_activities' => $count]);
    }

    private function backfillLatest(Request $request): JsonResponse
    {
        $userId = $this->permissionChecker->getUserId();
        if ($userId === null) {
            throw new \DomainException('Permission denied.');
        }

        $job = $this->backfillStarter->latestPosts(
            $request->request->getInt('limit'),
            $userId,
        );

        return $this->success('ActivityPub historical projection queued.', [
            'job_id'      => $job->id,
            'total_count' => $job->totalCount,
        ]);
    }

    private function backfillSelected(Request $request): JsonResponse
    {
        $userId = $this->permissionChecker->getUserId();
        if ($userId === null) {
            throw new \DomainException('Permission denied.');
        }

        $values = preg_split('/[\s,]+/u', trim($request->request->getString('content_ids')), -1, PREG_SPLIT_NO_EMPTY);
        if (!\is_array($values)) {
            throw new \InvalidArgumentException('The ActivityPub backfill selection is invalid.');
        }

        $contentIds = [];
        foreach ($values as $value) {
            $contentIds[] = ContentId::fromString($value);
        }

        $job = $this->backfillStarter->selected($contentIds, $userId);

        return $this->success('ActivityPub historical projection queued.', [
            'job_id'      => $job->id,
            'total_count' => $job->totalCount,
        ]);
    }

    /** @return array<string, int|string|null> */
    private function actorPayload(RemoteActor $actor): array
    {
        return [
            'id'                 => $actor->id,
            'url'                => $actor->actorUrl,
            'type'               => $actor->actorType,
            'username'           => $actor->preferredUsername,
            'display_name'       => $actor->displayName,
            'inbox'              => $actor->inboxUrl,
            'shared_inbox'       => $actor->sharedInboxUrl,
            'key_id'             => $actor->publicKeyId,
        ];
    }

    /** @return array{url:string}|null */
    private function media(Request $request, string $field): ?array
    {
        $url = trim($request->request->getString($field));

        return $url === '' ? null : ['url' => $url];
    }

    /** @param array<string, mixed> $data */
    private function success(string $message, array $data = []): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'message' => $this->translator->trans($message),
            ...$data,
        ]);
    }

    private function error(string $message, int $status, bool $translate = true): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $translate ? $this->translator->trans($message) : $message,
        ], $status);
    }

}
