<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Register\Comment\CommentImportService;
use Register\Comment\CommentRepository;
use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Live\LiveUpdateRepository;
use Register\Live\LiveUpdateSchema;
use Register\Module\Reactions\ReactionAggregateRepository;
use Register\Module\Reactions\ReactionAggregateSchema;
use Register\Core\Config\DynamicSecretParameterRegistry;
use Register\Core\Config\DynamicSecretStore;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpClientInterface;
use Register\Core\HttpClient\HttpResponse;
use Register\Core\HttpClient\Remote\HostResolverInterface;
use Register\Core\HttpClient\Remote\PublicAddressGuard;
use Register\Core\HttpClient\Remote\SafeRemoteHttpClient;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Queue\QueueConsumer;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerRegistry;
use Register\Core\Queue\QueuePublisher;
use Register\Extension\activitypub\Application\ActivationCheckResult;
use Register\Extension\activitypub\Application\ActivationReadinessCheck;
use Register\Extension\activitypub\Application\ActivationReadinessReport;
use Register\Extension\activitypub\Application\FederationActivationService;
use Register\Extension\activitypub\Application\InboxActivityProcessor;
use Register\Extension\activitypub\Application\InboxInteractionProcessor;
use Register\Extension\activitypub\Application\InboxRateLimiter;
use Register\Extension\activitypub\Application\InboxRequestValidator;
use Register\Extension\activitypub\Application\OutgoingFollowService;
use Register\Extension\activitypub\Application\OutgoingInteractionService;
use Register\Extension\activitypub\Application\OutgoingReplyService;
use Register\Extension\activitypub\Application\PublicFederationAccess;
use Register\Extension\activitypub\Application\SiteActorDraft;
use Register\Extension\activitypub\Application\SiteActorProvisioner;
use Register\Extension\activitypub\Content\PortableHtmlSanitizer;
use Register\Extension\activitypub\Controller\InboxController;
use Register\Extension\activitypub\Controller\ObjectController;
use Register\Extension\activitypub\Delivery\DeliveryPlanner;
use Register\Extension\activitypub\Delivery\DeliveryQueue;
use Register\Extension\activitypub\Discovery\RemoteActorDiscovery;
use Register\Extension\activitypub\Discovery\WebFingerClient;
use Register\Extension\activitypub\Domain\ActorType;
use Register\Extension\activitypub\Domain\CanonicalBasePath;
use Register\Extension\activitypub\Domain\CanonicalOrigin;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalHandle;
use Register\Extension\activitypub\Domain\ModerationAction;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Inbox\InboxQueue;
use Register\Extension\activitypub\Inbox\InboxQueueHandler;
use Register\Extension\activitypub\Inbox\IncomingSignatureVerifier;
use Register\Extension\activitypub\Inbox\RemoteActorDocumentValidator;
use Register\Extension\activitypub\Inbox\RemoteActorFetchClient;
use Register\Extension\activitypub\Inbox\RemoteObjectDocumentValidator;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;
use Register\Extension\activitypub\Infrastructure\DeliveryRepository;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\FollowRepository;
use Register\Extension\activitypub\Infrastructure\InboxRepository;
use Register\Extension\activitypub\Infrastructure\InteractionRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\LocalInteractionRepository;
use Register\Extension\activitypub\Infrastructure\ModerationRuleRepository;
use Register\Extension\activitypub\Infrastructure\NotificationRepository;
use Register\Extension\activitypub\Infrastructure\NewStoredObject;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Infrastructure\RemoteObjectRepository;
use Register\Extension\activitypub\Infrastructure\StoredObjectRepresentation;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\LocalActivityDocumentBuilder;
use Register\Extension\activitypub\Presentation\LocalNoteDocumentBuilder;
use Register\Extension\activitypub\Presentation\RemoteCommentTextFormatter;
use Register\Extension\activitypub\Security\ActivityPubSecret;
use Register\Extension\activitypub\Security\ActorKeyVault;
use Register\Extension\activitypub\Security\HttpSignatureRequest;
use Register\Extension\activitypub\Security\LegacyHttpSignature;
use Register\Extension\activitypub\Security\LocalActorSigningService;
use Register\Extension\activitypub\Security\Rfc9421HttpSignature;
use Register\Extension\activitypub\Security\RsaCrypto;
use Register\Extension\activitypub\Security\RsaKeyPair;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class InboxTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_activitypub_inbox_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testControllerReturnsFast202AndKeepsFirstSeenDeduplicatedEnvelope(): void
    {
        $environment = $this->environment();
        $request = $environment->followRequest();

        self::assertSame(Response::HTTP_ACCEPTED, $environment->controller->handle($request)->getStatusCode());
        self::assertSame(Response::HTTP_ACCEPTED, $environment->controller->handle($request)->getStatusCode());
        self::assertSame(0, $environment->transport->requestCount());
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::INBOX_TABLE));

        $inbox = $environment->singleInboxRow();
        self::assertSame('received', $inbox['state']);
        self::assertSame('Follow', $inbox['activity_type']);
        self::assertSame(hash('sha256', $request->getContent()), $inbox['body_hash']);

        $queue = $environment->queueRow(InboxQueue::JOB_ID, InboxQueue::CODE);
        self::assertNotNull($queue);
        self::assertSame(2, (int)$queue['generation']);
        self::assertSame(0, (int)$queue['attempts']);
    }

    public function testControllerRejectsUnsignedWrongTypeAndOversizedRequestsLocally(): void
    {
        $environment = $this->environment();
        $unsigned = Request::create(
            $environment->sharedInboxUrl(),
            'POST',
            server: ['REMOTE_ADDR' => '203.0.113.10', 'CONTENT_TYPE' => 'application/activity+json'],
            content: $environment->followBody(),
        );
        self::assertSame(Response::HTTP_UNAUTHORIZED, $environment->controller->handle($unsigned)->getStatusCode());

        $wrongType = $environment->followRequest();
        $wrongType->headers->set('Content-Type', 'text/plain');
        self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $environment->controller->handle($wrongType)->getStatusCode());

        $oversized = $environment->followRequest();
        $oversized->headers->set('Content-Length', '1048577');
        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $environment->controller->handle($oversized)->getStatusCode());
        self::assertSame(0, $environment->transport->requestCount());
        self::assertSame(0, $environment->tableCount(ActivityPubSchema::INBOX_TABLE));
    }

    public function testShutdownFetchesActorThenVerifiesFollowAndQueuesDirectAccept(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        self::assertSame(Response::HTTP_ACCEPTED, $environment->controller->handle($environment->followRequest())->getStatusCode());

        self::assertTrue($environment->runInboxQueue());
        self::assertSame(1, $environment->transport->requestCount());
        self::assertSame('GET', $environment->transport->requests[0]['method']);
        self::assertSame($environment->remoteActorUrl, $environment->transport->requests[0]['url']);
        self::assertSame('delayed', $environment->singleInboxRow()['state']);
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::REMOTE_ACTOR_TABLE));
        self::assertSame(0, $environment->tableCount(ActivityPubSchema::FOLLOW_TABLE));

        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(1, $environment->transport->requestCount());
        $inbox = $environment->singleInboxRow();
        self::assertSame('processed', $inbox['state']);
        self::assertStringContainsString('accepted', (string)$inbox['result_detail']);

        $follow = $environment->singleRow(ActivityPubSchema::FOLLOW_TABLE);
        self::assertSame('incoming', $follow['direction']);
        self::assertSame('accepted', $follow['state']);
        self::assertSame($environment->actor->id, (int)$follow['local_actor_id']);

        $activity = $environment->singleRow(ActivityPubSchema::ACTIVITY_TABLE);
        self::assertSame('Accept', $activity['activity_type']);
        self::assertSame('direct', $activity['visibility']);
        self::assertSame('direct', $activity['delivery_intent']);
        $document = json_decode((string)$activity['serialized_body'], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame('Accept', $document['type']);
        self::assertSame($environment->remoteActorUrl, $document['to'][0]);

        $delivery = $environment->singleRow(ActivityPubSchema::DELIVERY_TABLE);
        self::assertSame($environment->remoteInboxUrl, $delivery['inbox_url']);
        self::assertSame('pending', $delivery['state']);
        self::assertNull($environment->queueRow(InboxQueue::JOB_ID, InboxQueue::CODE));
        self::assertNotNull($environment->queueRow(DeliveryQueue::JOB_ID, DeliveryQueue::CODE));
    }

    public function testBadSignatureForcesOneActorRefreshThenDeadLetters(): void
    {
        $environment = $this->environmentWithActorResponses(2);
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->followRequest(tamperSignature: true))->getStatusCode(),
        );

        self::assertTrue($environment->runInboxQueue());
        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());
        $refresh = $environment->singleInboxRow();
        self::assertSame('delayed', $refresh['state']);
        self::assertSame(1, (int)$refresh['key_refresh_count']);
        self::assertSame(1, (int)$refresh['force_key_refresh']);

        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(2, $environment->transport->requestCount());
        self::assertSame(0, (int)$environment->singleInboxRow()['force_key_refresh']);

        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());
        $failed = $environment->singleInboxRow();
        self::assertSame('failed', $failed['state']);
        self::assertSame('signature', $failed['error_code']);
        self::assertSame(0, $environment->tableCount(ActivityPubSchema::FOLLOW_TABLE));
        self::assertNull($environment->queueRow(InboxQueue::JOB_ID, InboxQueue::CODE));
    }

    public function testActorFetchRejectsSsrfBeforeOpeningTransport(): void
    {
        $environment = $this->environment(
            [new HttpResponse(['HTTP/1.1 200 OK'], 200, '{}')],
            ['remote.example' => ['127.0.0.1']],
        );
        self::assertSame(Response::HTTP_ACCEPTED, $environment->controller->handle($environment->followRequest())->getStatusCode());

        self::assertTrue($environment->runInboxQueue());
        self::assertSame(0, $environment->transport->requestCount());
        $inbox = $environment->singleInboxRow();
        self::assertSame('failed', $inbox['state']);
        self::assertSame('unsafe_address', $inbox['error_code']);
    }

    public function testPausedFederationAcceptsEnvelopeButDefersAllRemoteWork(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $environment->setLifecycle(FederationLifecycleState::PAUSED);
        self::assertSame(Response::HTTP_ACCEPTED, $environment->controller->handle($environment->followRequest())->getStatusCode());

        self::assertTrue($environment->runInboxQueue());
        self::assertSame(0, $environment->transport->requestCount());
        self::assertSame('received', $environment->singleInboxRow()['state']);
        $queue = $environment->queueRow(InboxQueue::JOB_ID, InboxQueue::CODE);
        self::assertNotNull($queue);
        self::assertSame($environment->clock->now + 300, (int)$queue['available_at']);

        $environment->setLifecycle(FederationLifecycleState::ACTIVE);
        $environment->clock->now += 300;
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(1, $environment->transport->requestCount());
    }

    public function testRfc9421EnvelopeIsAcceptedForDeferredVerification(): void
    {
        $environment = $this->environment();
        $response = $environment->controller->handle($environment->followRequest(rfc9421: true));

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        self::assertSame('rfc9421', $environment->singleInboxRow()['signature_type']);
        self::assertSame(0, $environment->transport->requestCount());
    }

    public function testPublicReplyIsSanitizedImportedWithProvenanceAndModeration(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $localObject = $environment->createLocalObject();
        $activityId  = $environment->remoteActorUrl . '/activities/create-reply-1';
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/reply-1';
        $request = $environment->activityRequest([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $activityId,
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => [
                'id'           => $remoteObjectUrl,
                'type'         => 'Note',
                'attributedTo' => $environment->remoteActorUrl,
                'url'          => $remoteObjectUrl,
                'content'      => '<p>Hello <strong>world</strong><script>alert(1)</script> '
                    . '<a href="/source">source</a></p>',
                'inReplyTo'    => $environment->localObjectUrl($localObject),
                'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now - 5),
                'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc'           => [],
            ],
        ]);

        self::assertSame(Response::HTTP_ACCEPTED, $environment->controller->handle($request)->getStatusCode());
        self::assertTrue($environment->runInboxQueue());
        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());

        self::assertSame(1, $environment->tableCount(ActivityPubSchema::REMOTE_OBJECT_TABLE));
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::INTERACTION_TABLE));
        self::assertSame(1, $environment->tableCount(CommentSchema::TABLE_NAME));
        $comment = $environment->singleRow(CommentSchema::TABLE_NAME);
        self::assertSame('', $comment['email']);
        self::assertSame('', $comment['ip']);
        self::assertSame('Alice', $comment['nick']);
        self::assertSame(0, (int)$comment['shown']);
        self::assertStringContainsString('[B]world[/B]', (string)$comment['text']);
        self::assertStringContainsString('https://remote.example/source', (string)$comment['text']);
        self::assertStringNotContainsString('alert', (string)$comment['text']);

        $interaction = $environment->singleRow(ActivityPubSchema::INTERACTION_TABLE);
        self::assertSame('reply', $interaction['interaction_type']);
        self::assertSame($activityId, $interaction['remote_activity_url']);
        self::assertSame($remoteObjectUrl, $interaction['remote_object_url']);
        self::assertSame((int)$comment['id'], (int)$interaction['local_comment_id']);
        self::assertSame($localObject->id, (int)$interaction['local_object_id']);
        $provenance = json_decode((string)$interaction['provenance_json'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($provenance);
        self::assertSame($environment->remoteActorUrl, $provenance['actor']);

        $notification = $environment->singleRow(ActivityPubSchema::NOTIFICATION_TABLE);
        self::assertSame('moderation_reply', $notification['notification_type']);
        self::assertSame($environment->actor->id, (int)$notification['local_actor_id']);
        $recipient = $environment->singleRow(ActivityPubSchema::REMOTE_RECIPIENT_TABLE);
        self::assertSame('inbox', $recipient['recipient_kind']);
        self::assertSame($environment->actor->id, (int)$recipient['local_actor_id']);

        $remoteObject = $environment->singleRow(ActivityPubSchema::REMOTE_OBJECT_TABLE);
        $snapshot = $environment->rowById(
            ActivityPubSchema::REMOTE_SNAPSHOT_TABLE,
            (int)$remoteObject['current_snapshot_id'],
        );
        self::assertNotNull($snapshot);
        self::assertStringNotContainsString('<script', (string)$snapshot['document_json']);
    }

    public function testOwnedRemoteFeaturedAddAndRemoveAreAppliedToAdvertisedCollection(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $localActorUrl = 'https://journal.example/activitypub/actors/' . $environment->actor->publicId;
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/featured-note';
        $featuredUrl = $environment->remoteActorUrl . '/collections/featured';
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/create-featured-note',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => [
                'id'           => $remoteObjectUrl,
                'type'         => 'Note',
                'attributedTo' => $environment->remoteActorUrl,
                'content'      => '<p>A reader entry that may be pinned.</p>',
                'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now),
                'to'           => [$localActorUrl],
                'cc'           => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($create))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());
        self::assertNull($environment->remoteObjectRepository->findByUrl($remoteObjectUrl)?->featuredAt);

        $environment->clock->now += 1;
        $add = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/add-featured-note',
            'type'     => 'Add',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $remoteObjectUrl,
            'target'   => $featuredUrl,
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($add))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(
            $environment->clock->now,
            $environment->remoteObjectRepository->findByUrl($remoteObjectUrl)?->featuredAt,
        );

        $environment->clock->now += 1;
        $remove = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/remove-featured-note',
            'type'     => 'Remove',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $remoteObjectUrl,
            'target'   => $featuredUrl,
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($remove))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        self::assertNull($environment->remoteObjectRepository->findByUrl($remoteObjectUrl)?->featuredAt);

        $environment->clock->now += 1;
        $invalidActivityId = $environment->remoteActorUrl . '/activities/add-to-local-featured';
        $invalid = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $invalidActivityId,
            'type'     => 'Add',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $remoteObjectUrl,
            'target'   => $localActorUrl . '/featured',
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($invalid))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        $rejected = $environment->rowByUrl(ActivityPubSchema::INBOX_TABLE, 'activity_url', $invalidActivityId);
        self::assertNotNull($rejected);
        self::assertSame('failed', $rejected['state']);
        self::assertSame('activity_rejected', $rejected['error_code']);
        self::assertNull($environment->remoteObjectRepository->findByUrl($remoteObjectUrl)?->featuredAt);
    }

    public function testOwnedReplyUpdateAndDeleteMutateOnlyImportedComment(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $localObject = $environment->createLocalObject();
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/reply-lifecycle';
        $baseObject = [
            'id'           => $remoteObjectUrl,
            'type'         => 'Note',
            'attributedTo' => $environment->remoteActorUrl,
            'url'          => $remoteObjectUrl,
            'content'      => '<p>First version.</p>',
            'inReplyTo'    => $environment->localObjectUrl($localObject),
            'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now - 5),
            'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
        ];
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/create-lifecycle',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $baseObject,
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($create))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());

        $environment->clock->now += 1;
        $updatedObject = $baseObject;
        $updatedObject['content'] = '<p>Second <em>version</em>.</p>';
        $updatedObject['updated'] = gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now);
        $update = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/update-lifecycle',
            'type'     => 'Update',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $updatedObject,
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($update))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        $comment = $environment->singleRow(CommentSchema::TABLE_NAME);
        self::assertSame('Second [I]version[/I].', $comment['text']);
        self::assertSame(0, (int)$comment['deleted']);

        $environment->clock->now += 1;
        $delete = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/delete-lifecycle',
            'type'     => 'Delete',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $remoteObjectUrl,
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($delete))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());

        self::assertSame(0, $environment->tableCount(CommentSchema::TABLE_NAME));
        self::assertSame('deleted', $environment->singleRow(ActivityPubSchema::REMOTE_OBJECT_TABLE)['state']);
        self::assertSame('deleted', $environment->singleRow(ActivityPubSchema::INTERACTION_TABLE)['state']);
        self::assertSame(3, $environment->tableCount(ActivityPubSchema::INBOX_TABLE));
        self::assertSame(3, $environment->tableCount(ActivityPubSchema::REMOTE_SNAPSHOT_TABLE));
    }

    public function testLikeEmojiAnnounceAndStrictUndoMaintainIndividualAggregates(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $localObject = $environment->createLocalObject();
        $localObjectUrl = $environment->localObjectUrl($localObject);
        $likeId = $environment->remoteActorUrl . '/activities/like-local';
        $like = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $likeId,
            'type'     => 'Like',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $localObjectUrl,
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($like))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());

        $aggregate = $environment->singleRow(ReactionAggregateSchema::TABLE_NAME);
        self::assertSame('post', $aggregate['target_type']);
        self::assertSame('like', $aggregate['reaction']);
        self::assertSame(42, (int)$aggregate['target_id']);
        self::assertSame(1, (int)$aggregate['reaction_count']);

        $environment->clock->now += 1;
        $emoji = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/emoji-local',
            'type'     => 'EmojiReact',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $localObjectUrl,
            'content'  => '🔥',
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($emoji))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());

        $environment->clock->now += 1;
        $announce = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/announce-local',
            'type'     => 'Announce',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $localObjectUrl,
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($announce))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(3, $environment->tableCount(ReactionAggregateSchema::TABLE_NAME));
        self::assertSame(3, $environment->tableCount(ActivityPubSchema::INTERACTION_TABLE));

        $environment->clock->now += 1;
        $undo = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/undo-like-local',
            'type'     => 'Undo',
            'actor'    => $environment->remoteActorUrl,
            'object'   => [
                'id'     => $likeId,
                'type'   => 'Like',
                'actor'  => $environment->remoteActorUrl,
                'object' => $localObjectUrl,
            ],
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($undo))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(2, $environment->tableCount(ReactionAggregateSchema::TABLE_NAME));
        $undone = $environment->rowByUrl(ActivityPubSchema::INTERACTION_TABLE, 'remote_activity_url', $likeId);
        self::assertNotNull($undone);
        self::assertSame('undone', $undone['state']);
        self::assertSame(3, $environment->tableCount(ActivityPubSchema::NOTIFICATION_TABLE));
    }

    public function testDirectNoteStaysPrivateAndBtoIsRemovedFromStoredSnapshot(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $localActorUrl = 'https://journal.example/activitypub/actors/' . $environment->actor->publicId;
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/private-note';
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/private-note',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => [
                'id'           => $remoteObjectUrl,
                'type'         => 'Note',
                'attributedTo' => $environment->remoteActorUrl,
                'content'      => '<p>Private but not encrypted.</p>',
                'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now),
                'to'           => [],
                'bto'          => [$localActorUrl],
            ],
        ];
        $request = $environment->activityRequest($create, $environment->actor->publicId);
        self::assertSame(Response::HTTP_ACCEPTED, $environment->controller->handle($request)->getStatusCode());
        self::assertTrue($environment->runInboxQueue());
        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());

        self::assertSame(0, $environment->tableCount(CommentSchema::TABLE_NAME));
        self::assertSame('direct', $environment->singleRow(ActivityPubSchema::REMOTE_OBJECT_TABLE)['visibility']);
        self::assertSame('direct_note', $environment->singleRow(ActivityPubSchema::INTERACTION_TABLE)['interaction_type']);
        self::assertSame('private_note', $environment->singleRow(ActivityPubSchema::NOTIFICATION_TABLE)['notification_type']);
        self::assertSame('addressed', $environment->singleRow(ActivityPubSchema::REMOTE_RECIPIENT_TABLE)['recipient_kind']);
        $remoteObject = $environment->singleRow(ActivityPubSchema::REMOTE_OBJECT_TABLE);
        $snapshot = $environment->rowById(
            ActivityPubSchema::REMOTE_SNAPSHOT_TABLE,
            (int)$remoteObject['current_snapshot_id'],
        );
        self::assertNotNull($snapshot);
        $document = json_decode((string)$snapshot['document_json'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertArrayNotHasKey('bto', $document);
        self::assertSame([], $document['to']);
    }

    public function testReferencedCreateFetchesOneSafeObjectHopAndResumesFromDatabase(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $localObject = $environment->createLocalObject();
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/referenced-reply';
        $environment->transport->append(new HttpResponse(
            ['HTTP/1.1 200 OK', 'Content-Type: application/activity+json'],
            200,
            json_encode([
                '@context'     => 'https://www.w3.org/ns/activitystreams',
                'id'           => $remoteObjectUrl,
                'type'         => 'Note',
                'attributedTo' => $environment->remoteActorUrl,
                'content'      => '<p>Fetched reply.</p>',
                'inReplyTo'    => $environment->localObjectUrl($localObject),
                'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now),
                'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/referenced-create',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $remoteObjectUrl,
        ];

        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($create))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(1, $environment->transport->requestCount());
        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(2, $environment->transport->requestCount());
        self::assertSame($remoteObjectUrl, $environment->transport->requests[1]['url']);
        self::assertSame('GET', $environment->transport->requests[1]['method']);
        self::assertSame('ready', $environment->singleInboxRow()['fetch_kind']);
        self::assertSame(0, $environment->tableCount(CommentSchema::TABLE_NAME));

        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(2, $environment->transport->requestCount());
        self::assertSame('processed', $environment->singleInboxRow()['state']);
        self::assertSame('Fetched reply.', $environment->singleRow(CommentSchema::TABLE_NAME)['text']);
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::REMOTE_OBJECT_TABLE));
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::INTERACTION_TABLE));
    }

    public function testSecureModeObjectFetchGetsExactlyOneFreshlySignedRetry(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $localObject = $environment->createLocalObject();
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/secure-reply';
        $remoteObject = json_encode([
            '@context'     => 'https://www.w3.org/ns/activitystreams',
            'id'           => $remoteObjectUrl,
            'type'         => 'Note',
            'attributedTo' => $environment->remoteActorUrl,
            'content'      => '<p>Signed-fetch reply.</p>',
            'inReplyTo'    => $environment->localObjectUrl($localObject),
            'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now),
            'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $environment->transport->append(new HttpResponse(['HTTP/1.1 401 Unauthorized'], 401, ''));
        $environment->transport->append(new HttpResponse(
            ['HTTP/1.1 200 OK', 'Content-Type: application/activity+json'],
            200,
            $remoteObject,
        ));
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/secure-create',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $remoteObjectUrl,
        ];

        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($create))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());
        $retry = $environment->singleInboxRow();
        self::assertSame('object', $retry['fetch_kind']);
        self::assertSame(1, (int)$retry['fetch_signed']);

        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(3, $environment->transport->requestCount());
        self::assertArrayHasKey('Signature', $environment->transport->requests[2]['headers']);
        self::assertArrayHasKey('Date', $environment->transport->requests[2]['headers']);
        self::assertStringContainsString('/activitypub/keys/', $environment->transport->requests[2]['headers']['Signature']);
        self::assertSame(0, (int)$environment->singleInboxRow()['fetch_signed']);

        $environment->clock->now += 1;
        self::assertTrue($environment->runInboxQueue());
        self::assertSame('Signed-fetch reply.', $environment->singleRow(CommentSchema::TABLE_NAME)['text']);
        self::assertSame(3, $environment->transport->requestCount());
    }

    public function testDiscoveryFollowIdempotencyAndUndoAreDurable(): void
    {
        $environment = $this->environment([], ['remote.example' => ['93.184.216.34']]);
        $environment->transport->append(new HttpResponse(
            ['HTTP/1.1 200 OK', 'Content-Type: application/jrd+json'],
            200,
            json_encode([
                'subject' => 'acct:alice@remote.example',
                'aliases' => [$environment->remoteActorUrl],
                'links'   => [[
                    'rel'  => 'self',
                    'type' => 'application/activity+json',
                    'href' => $environment->remoteActorUrl,
                ]],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
        $environment->transport->append(new HttpResponse(
            ['HTTP/1.1 200 OK', 'Content-Type: application/activity+json'],
            200,
            $environment->remoteActorDocument(),
        ));

        $remoteActor = $environment->actorDiscovery->discover(
            '@alice@remote.example',
            $environment->actor->id,
            $environment->clock->now,
        );
        self::assertSame('alice', $remoteActor->preferredUsername);
        self::assertSame(2, $environment->transport->requestCount());
        self::assertStringContainsString('/.well-known/webfinger?resource=acct%3Aalice%40remote.example', $environment->transport->requests[0]['url']);
        self::assertSame($environment->remoteActorUrl, $environment->transport->requests[1]['url']);

        $follow = $environment->outgoingFollowService->follow(
            $environment->actor->id,
            $remoteActor->id,
            $environment->clock->now,
        );
        $sameFollow = $environment->outgoingFollowService->follow(
            $environment->actor->id,
            $remoteActor->id,
            $environment->clock->now + 1,
        );
        self::assertSame($follow->id, $sameFollow->id);
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::FOLLOW_TABLE));
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::ACTIVITY_TABLE));
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::DELIVERY_TABLE));

        $environment->clock->now += 2;
        $undo = $environment->outgoingFollowService->unfollow(
            $environment->actor->id,
            $remoteActor->id,
            $environment->clock->now,
        );
        self::assertNotNull($undo);
        self::assertSame('Undo', $undo->type);
        self::assertNull($environment->outgoingFollowService->unfollow(
            $environment->actor->id,
            $remoteActor->id,
            $environment->clock->now + 1,
        ));
        self::assertSame('ended', $environment->singleRow(ActivityPubSchema::FOLLOW_TABLE)['state']);
        self::assertSame(2, $environment->tableCount(ActivityPubSchema::ACTIVITY_TABLE));
        self::assertSame(2, $environment->tableCount(ActivityPubSchema::DELIVERY_TABLE));
        $undoDocument = json_decode($undo->serializedBody, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($undoDocument);
        self::assertSame('Follow', $undoDocument['object']['type']);
        self::assertSame($follow->publicId, basename((string)$undoDocument['object']['id']));
    }

    public function testSignedMoveRefetchesReciprocalAliasAndMigratesFollowGraph(): void
    {
        $environment = $this->environment([], [
            'remote.example' => ['93.184.216.34'],
            'new.example'    => ['93.184.216.35'],
        ]);
        $targetUrl = 'https://new.example/users/alice';
        $targetKeyPair = (new RsaCrypto())->generateKeyPair();
        $environment->transport->append(new HttpResponse(
            ['HTTP/1.1 200 OK', 'Content-Type: application/activity+json'],
            200,
            $environment->remoteActorDocument(),
        ));
        $environment->transport->append(new HttpResponse(
            ['HTTP/1.1 200 OK', 'Content-Type: application/activity+json'],
            200,
            json_encode([
                '@context'         => 'https://www.w3.org/ns/activitystreams',
                'id'               => $targetUrl,
                'type'             => 'Person',
                'preferredUsername' => 'alice',
                'name'             => 'Alice Moved',
                'inbox'            => $targetUrl . '/inbox',
                'alsoKnownAs'      => [$environment->remoteActorUrl],
                'publicKey'        => [
                    'id'           => $targetUrl . '#main-key',
                    'owner'        => $targetUrl,
                    'publicKeyPem' => $targetKeyPair->publicKeyPem,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
        $moveRequest = $environment->activityRequest([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/move-1',
            'type'     => 'Move',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $environment->remoteActorUrl,
            'target'   => $targetUrl,
            'to'       => ['https://journal.example/activitypub/actors/' . $environment->actor->publicId . '/followers'],
        ]);
        self::assertSame(Response::HTTP_ACCEPTED, $environment->controller->handle($moveRequest)->getStatusCode());

        self::assertTrue($environment->runInboxQueue());
        self::assertSame(1, $environment->transport->requestCount());
        $oldActor = $environment->remoteActorRepository->findByUrl($environment->remoteActorUrl);
        self::assertNotNull($oldActor);
        $outgoingFollow = $environment->outgoingFollowService->follow(
            $environment->actor->id,
            $oldActor->id,
            $environment->clock->now,
        );
        self::assertTrue($environment->followRepository->recordOutgoingResponse(
            $oldActor->id,
            $outgoingFollow->id,
            true,
            $environment->clock->now,
        ));
        $environment->followRepository->recordIncoming(
            $environment->actor->id,
            $oldActor->id,
            $environment->remoteActorUrl . '/activities/follow-journal',
            true,
            $environment->clock->now,
        );
        // This fixture advances only the inbox handler. The already-materialized outgoing
        // delivery remains in its domain table, while its unrelated wake-up is removed.
        $environment->dbLayer->delete('queue')
            ->where('code = :code')->setParameter('code', DeliveryQueue::CODE)
            ->execute()
        ;

        ++$environment->clock->now;
        self::assertTrue($environment->runInboxQueue());
        self::assertSame(2, $environment->transport->requestCount());
        self::assertSame($targetUrl, $environment->transport->requests[1]['url']);
        self::assertSame('ready', $environment->singleInboxRow()['fetch_kind']);

        ++$environment->clock->now;
        self::assertTrue($environment->runInboxQueue());
        $inbox = $environment->singleInboxRow();
        self::assertSame('processed', $inbox['state']);
        self::assertStringContainsString('1 outgoing and 1 incoming', (string)$inbox['result_detail']);
        $movedActor = $environment->remoteActorRepository->findByUrl($environment->remoteActorUrl);
        $targetActor = $environment->remoteActorRepository->findByUrl($targetUrl);
        self::assertNotNull($movedActor);
        self::assertNotNull($targetActor);
        self::assertSame('moved', $movedActor->state);
        self::assertSame($targetUrl, $movedActor->movedToUrl);

        $relationships = $environment->dbLayer->select('direction', 'remote_actor_id', 'state')
            ->from(ActivityPubSchema::FOLLOW_TABLE)
            ->orderBy('id')
            ->execute()
            ->fetchAssocAll()
        ;
        self::assertSame([
            ['direction' => 'outgoing', 'remote_actor_id' => $oldActor->id, 'state' => 'ended'],
            ['direction' => 'incoming', 'remote_actor_id' => $targetActor->id, 'state' => 'accepted'],
            ['direction' => 'outgoing', 'remote_actor_id' => $targetActor->id, 'state' => 'pending'],
        ], $relationships);
        self::assertSame(2, $environment->tableCount(ActivityPubSchema::ACTIVITY_TABLE));
        self::assertSame(2, $environment->transport->requestCount());
    }

    public function testTrustedReplyBecomesPublicRepliesCollectionMember(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $environment->moderationRepository->store(
            'actor',
            $environment->remoteActorUrl,
            ModerationAction::TRUST,
            100,
            ['reason' => 'test fixture'],
            $environment->clock->now,
        );
        $localObject = $environment->createLocalObject();
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/public-reply';
        $request = $environment->activityRequest([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/public-reply',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => [
                'id'           => $remoteObjectUrl,
                'type'         => 'Note',
                'attributedTo' => $environment->remoteActorUrl,
                'content'      => '<p>A trusted reply.</p>',
                'inReplyTo'    => $environment->localObjectUrl($localObject),
                'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now),
                'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
            ],
        ]);
        self::assertSame(Response::HTTP_ACCEPTED, $environment->controller->handle($request)->getStatusCode());
        self::assertTrue($environment->runInboxQueue());
        ++$environment->clock->now;
        self::assertTrue($environment->runInboxQueue());

        self::assertSame(1, $environment->interactionRepository->publicReplyCount($localObject->id));
        $reply = $environment->interactionRepository->publicRepliesPage($localObject->id, null, 10)[0] ?? null;
        self::assertNotNull($reply);
        self::assertTrue($reply->isPublic);
        self::assertSame($remoteObjectUrl, $reply->remoteObjectUrl);
    }

    public function testReaderReplyStoresResolvableNoteBeforeDirectDelivery(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/reader-entry';
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/reader-entry',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => [
                'id'           => $remoteObjectUrl,
                'type'         => 'Note',
                'attributedTo' => $environment->remoteActorUrl,
                'content'      => '<p>A remote reader entry.</p>',
                'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now),
                'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc'           => ['https://journal.example/activitypub/actors/' . $environment->actor->publicId],
            ],
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($create))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        ++$environment->clock->now;
        self::assertTrue($environment->runInboxQueue());
        $remoteObject = $environment->remoteObjectRepository->findByUrl($remoteObjectUrl);
        self::assertNotNull($remoteObject);

        ++$environment->clock->now;
        $note = $environment->outgoingReplyService->reply(
            $environment->actor->id,
            $remoteObject->id,
            "Hello <reader>!\n\nSecond paragraph.",
            'public',
            $environment->clock->now,
        );
        self::assertSame('live', $note->state);
        self::assertSame($remoteObjectUrl, $note->inReplyToUrl);
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::LOCAL_NOTE_TABLE));
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::ACTIVITY_TABLE));
        self::assertSame(1, $environment->tableCount(ActivityPubSchema::DELIVERY_TABLE));
        $noteDocument = json_decode($note->snapshotJson, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($noteDocument);
        self::assertSame('Note', $noteDocument['type']);
        self::assertStringContainsString('&lt;reader&gt;', (string)$noteDocument['content']);
        self::assertStringNotContainsString('<reader>', (string)$noteDocument['content']);
        $activity = $environment->singleRow(ActivityPubSchema::ACTIVITY_TABLE);
        self::assertSame($note->id, (int)$activity['local_note_id']);
        self::assertSame('Create', $activity['activity_type']);
        self::assertSame($environment->remoteInboxUrl, $environment->singleRow(ActivityPubSchema::DELIVERY_TABLE)['inbox_url']);

        ++$environment->clock->now;
        $updated = $environment->outgoingReplyService->update(
            $environment->actor->id,
            $note->id,
            'Edited reply.',
            $environment->clock->now,
        );
        self::assertSame($note->publicId, $updated->publicId);
        self::assertGreaterThan($note->updatedAt, $updated->updatedAt);
        self::assertStringContainsString('Edited reply.', $updated->snapshotJson);
        self::assertSame(2, $environment->tableCount(ActivityPubSchema::ACTIVITY_TABLE));
        self::assertSame(2, $environment->tableCount(ActivityPubSchema::DELIVERY_TABLE));

        ++$environment->clock->now;
        $deleted = $environment->outgoingReplyService->delete(
            $environment->actor->id,
            $note->id,
            $environment->clock->now,
        );
        self::assertSame('tombstoned', $deleted->state);
        self::assertNotNull($deleted->deletedAt);
        self::assertSame(3, $environment->tableCount(ActivityPubSchema::ACTIVITY_TABLE));
        self::assertSame(3, $environment->tableCount(ActivityPubSchema::DELIVERY_TABLE));
        $activityTypes = $environment->dbLayer->select('activity_type')
            ->from(ActivityPubSchema::ACTIVITY_TABLE)
            ->orderBy('id')
            ->execute()
            ->fetchColumn()
        ;
        self::assertSame(['Create', 'Update', 'Delete'], $activityTypes);
    }

    public function testDirectLocalNoteIsNeverPubliclyDereferenceable(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/direct-target';
        $request = $environment->activityRequest([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/direct-target',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => [
                'id'           => $remoteObjectUrl,
                'type'         => 'Note',
                'attributedTo' => $environment->remoteActorUrl,
                'content'      => '<p>Private target.</p>',
                'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now),
                'to'           => ['https://journal.example/activitypub/actors/' . $environment->actor->publicId],
            ],
        ]);
        self::assertSame(Response::HTTP_ACCEPTED, $environment->controller->handle($request)->getStatusCode());
        self::assertTrue($environment->runInboxQueue());
        ++$environment->clock->now;
        self::assertTrue($environment->runInboxQueue());
        $remoteObject = $environment->remoteObjectRepository->findByUrl($remoteObjectUrl);
        self::assertNotNull($remoteObject);
        $note = $environment->outgoingReplyService->reply(
            $environment->actor->id,
            $remoteObject->id,
            'Private local answer.',
            'direct',
            ++$environment->clock->now,
        );
        $controller = new ObjectController(
            $environment->federationRepository,
            new PublicFederationAccess($environment->stateRepository),
            new FederationUrlGeneratorFactory($environment->stateRepository),
            new ActivityPubResponseFactory(),
        );
        $objectRequest = Request::create('/activitypub/objects/' . $note->publicId);
        $objectRequest->attributes->set('publicId', $note->publicId);

        self::assertSame(Response::HTTP_NOT_FOUND, $controller->handle($objectRequest)->getStatusCode());
    }

    public function testTrustedRemoteReplyAndReactionTargetLocalReaderNote(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $environment->moderationRepository->store(
            'actor',
            $environment->remoteActorUrl,
            ModerationAction::TRUST,
            100,
            ['reason' => 'local Note thread fixture'],
            $environment->clock->now,
        );
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/thread-root';
        $rootCreate = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/thread-root',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => [
                'id'           => $remoteObjectUrl,
                'type'         => 'Note',
                'attributedTo' => $environment->remoteActorUrl,
                'content'      => '<p>Thread root.</p>',
                'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now),
                'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc'           => ['https://journal.example/activitypub/actors/' . $environment->actor->publicId],
            ],
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($rootCreate))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        ++$environment->clock->now;
        self::assertTrue($environment->runInboxQueue());
        $remoteObject = $environment->remoteObjectRepository->findByUrl($remoteObjectUrl);
        self::assertNotNull($remoteObject);
        $localNote = $environment->outgoingReplyService->reply(
            $environment->actor->id,
            $remoteObject->id,
            'Local reader reply.',
            'public',
            ++$environment->clock->now,
        );
        $localNoteUrl = 'https://journal.example/activitypub/objects/' . $localNote->publicId;
        $remoteReplyUrl = $environment->remoteActorUrl . '/objects/reply-to-local-note';
        $replyCreate = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/reply-to-local-note',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => [
                'id'           => $remoteReplyUrl,
                'type'         => 'Note',
                'attributedTo' => $environment->remoteActorUrl,
                'content'      => '<p>Remote answer to the local Note.</p>',
                'inReplyTo'    => $localNoteUrl,
                'published'    => gmdate('Y-m-d\TH:i:s\Z', ++$environment->clock->now),
                'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc'           => ['https://journal.example/activitypub/actors/' . $environment->actor->publicId],
            ],
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($replyCreate))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        self::assertTrue($environment->runInboxQueue());
        $replyInbox = $environment->rowByUrl(
            ActivityPubSchema::INBOX_TABLE,
            'activity_url',
            $replyCreate['id'],
        );
        self::assertNotNull($replyInbox);
        self::assertSame('processed', $replyInbox['state'], (string)$replyInbox['result_detail']);
        $replyInteraction = $environment->interactionRepository->findByActivityUrl($replyCreate['id']);
        self::assertNotNull($replyInteraction);
        self::assertSame($localNote->id, $replyInteraction->localNoteId);
        self::assertTrue($replyInteraction->isPublic);
        self::assertSame(1, $environment->interactionRepository->publicLocalNoteReplyCount($localNote->id));
        $reply = $environment->interactionRepository->publicLocalNoteRepliesPage($localNote->id, null, 10)[0] ?? null;
        self::assertNotNull($reply);
        self::assertSame($localNote->id, $reply->localNoteId);
        self::assertSame($remoteReplyUrl, $reply->remoteObjectUrl);

        $like = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/like-local-note',
            'type'     => 'Like',
            'actor'    => $environment->remoteActorUrl,
            'object'   => $localNoteUrl,
            'to'       => ['https://journal.example/activitypub/actors/' . $environment->actor->publicId],
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($like))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        $likeInteraction = $environment->interactionRepository->findByActivityUrl($like['id']);
        self::assertNotNull($likeInteraction);
        self::assertSame($localNote->id, $likeInteraction->localNoteId);
        self::assertSame(1, (int)$environment->dbLayer->select('COUNT(*)')
            ->from(ReactionAggregateSchema::TABLE_NAME)
            ->where('target_type = :type')->setParameter('type', 'activitypub_note')
            ->andWhere('target_id = :id')->setParameter('id', $localNote->id)
            ->execute()
            ->result());
    }

    public function testReaderInteractionsAreIdempotentAndUndoExactOriginal(): void
    {
        $environment = $this->environmentWithActorResponses(1);
        $remoteObjectUrl = $environment->remoteActorUrl . '/objects/reader-reactions';
        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $environment->remoteActorUrl . '/activities/reader-reactions',
            'type'     => 'Create',
            'actor'    => $environment->remoteActorUrl,
            'object'   => [
                'id'           => $remoteObjectUrl,
                'type'         => 'Note',
                'attributedTo' => $environment->remoteActorUrl,
                'content'      => '<p>React to this.</p>',
                'published'    => gmdate('Y-m-d\TH:i:s\Z', $environment->clock->now),
                'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
                'cc'           => ['https://journal.example/activitypub/actors/' . $environment->actor->publicId],
            ],
        ];
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $environment->controller->handle($environment->activityRequest($create))->getStatusCode(),
        );
        self::assertTrue($environment->runInboxQueue());
        ++$environment->clock->now;
        self::assertTrue($environment->runInboxQueue());
        $remoteObject = $environment->remoteObjectRepository->findByUrl($remoteObjectUrl);
        self::assertNotNull($remoteObject);

        ++$environment->clock->now;
        $like = $environment->outgoingInteractionService->create(
            $environment->actor->id,
            $remoteObject->id,
            'like',
            now: $environment->clock->now,
        );
        $sameLike = $environment->outgoingInteractionService->create(
            $environment->actor->id,
            $remoteObject->id,
            'like',
            now: $environment->clock->now + 1,
        );
        self::assertSame($like->id, $sameLike->id);
        $environment->outgoingInteractionService->create(
            $environment->actor->id,
            $remoteObject->id,
            'emoji_react',
            '✨',
            $environment->clock->now + 2,
        );
        $environment->outgoingInteractionService->create(
            $environment->actor->id,
            $remoteObject->id,
            'announce',
            now: $environment->clock->now + 3,
        );
        self::assertSame(3, $environment->tableCount(ActivityPubSchema::LOCAL_INTERACTION_TABLE));
        self::assertSame(3, $environment->tableCount(ActivityPubSchema::ACTIVITY_TABLE));
        self::assertSame(3, $environment->tableCount(ActivityPubSchema::DELIVERY_TABLE));

        $ended = $environment->outgoingInteractionService->undo(
            $environment->actor->id,
            $remoteObject->id,
            'like',
            now: $environment->clock->now + 4,
        );
        self::assertNotNull($ended);
        self::assertSame('ended', $ended->state);
        self::assertNotNull($ended->undoActivityId);
        $sameEnded = $environment->outgoingInteractionService->undo(
            $environment->actor->id,
            $remoteObject->id,
            'like',
            now: $environment->clock->now + 5,
        );
        self::assertNotNull($sameEnded);
        self::assertSame($ended->undoActivityId, $sameEnded->undoActivityId);
        self::assertSame(4, $environment->tableCount(ActivityPubSchema::ACTIVITY_TABLE));
        self::assertSame(4, $environment->tableCount(ActivityPubSchema::DELIVERY_TABLE));
        $undoActivity = $environment->federationRepository->findActivityById($ended->undoActivityId);
        self::assertNotNull($undoActivity);
        $undoDocument = json_decode($undoActivity->serializedBody, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($undoDocument);
        self::assertSame('Undo', $undoDocument['type']);
        self::assertSame('Like', $undoDocument['object']['type']);
    }

    /**
     * @param list<HttpResponse|\Throwable> $responses
     * @param array<string, list<string>> $dnsAnswers
     */
    private function environment(array $responses = [], array $dnsAnswers = []): InboxTestEnvironment
    {
        return new InboxTestEnvironment(
            $this->temporaryDirectory,
            new InboxTestTransport($responses),
            new InboxTestHostResolver($dnsAnswers),
            new InboxTestClock(10_000),
        );
    }

    private function environmentWithActorResponses(int $count): InboxTestEnvironment
    {
        $environment = $this->environment([], ['remote.example' => ['93.184.216.34']]);
        for ($i = 0; $i < $count; ++$i) {
            $environment->transport->append(new HttpResponse(
                ['HTTP/1.1 200 OK', 'Content-Type: application/activity+json'],
                200,
                $environment->remoteActorDocument(),
            ));
        }

        return $environment;
    }
}

/** @internal */
final readonly class InboxTestEnvironment
{
    public const string REMOTE_ACTOR_URL = 'https://remote.example/users/alice';

    public const string REMOTE_INBOX_URL = 'https://remote.example/users/alice/inbox';

    public \PDO $pdo;

    public DbLayerSqlite $dbLayer;

    public FederationStateRepository $stateRepository;

    public LocalActorRepository $actorRepository;

    public LocalFederationRepository $federationRepository;

    public ModerationRuleRepository $moderationRepository;

    public RemoteActorRepository $remoteActorRepository;

    public FollowRepository $followRepository;

    public InteractionRepository $interactionRepository;

    public OutgoingFollowService $outgoingFollowService;

    public OutgoingReplyService $outgoingReplyService;

    public OutgoingInteractionService $outgoingInteractionService;

    public RemoteObjectRepository $remoteObjectRepository;

    public RemoteActorDiscovery $actorDiscovery;

    public InboxRepository $inboxRepository;

    public InboxController $controller;

    public QueueConsumer $consumer;

    public LocalActor $actor;

    public RsaKeyPair $remoteKeyPair;

    public string $remoteActorUrl;

    public string $remoteInboxUrl;

    private LegacyHttpSignature $legacySignature;

    private Rfc9421HttpSignature $rfc9421Signature;

    public function __construct(
        string                       $temporaryDirectory,
        public InboxTestTransport    $transport,
        InboxTestHostResolver        $resolver,
        public InboxTestClock        $clock,
    ) {
        $this->remoteActorUrl = self::REMOTE_ACTOR_URL;
        $this->remoteInboxUrl = self::REMOTE_INBOX_URL;
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->createQueueTable();
        $this->dbLayer = new DbLayerSqlite($this->pdo);
        ActivityPubSchema::install($this->dbLayer);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $this->pdo->exec('CREATE TABLE userpics (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        CommentSchema::create($this->dbLayer);
        LiveUpdateSchema::create($this->dbLayer);
        ReactionAggregateSchema::create($this->dbLayer);

        $registry = new DynamicSecretParameterRegistry(['CORE_TEST_SECRET']);
        $registry->registerExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $secrets = new DynamicSecretStore($temporaryDirectory . '/config.secrets.php', $registry);
        $secrets->getOrCreateExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $this->stateRepository = new FederationStateRepository($this->dbLayer);
        $this->actorRepository = new LocalActorRepository($this->dbLayer);
        $transaction           = new PortableDatabaseTransaction($this->pdo);
        $rsaCrypto             = new RsaCrypto();
        $this->legacySignature = new LegacyHttpSignature($rsaCrypto);
        $this->rfc9421Signature = new Rfc9421HttpSignature($rsaCrypto);
        $this->remoteKeyPair   = $rsaCrypto->generateKeyPair();
        $vault                 = new ActorKeyVault($secrets);
        $htmlSanitizer         = new PortableHtmlSanitizer(new HttpClient());
        $provisioner           = new SiteActorProvisioner(
            $this->stateRepository,
            $this->actorRepository,
            new PublicIdGenerator(),
            $rsaCrypto,
            $vault,
            $transaction,
            $htmlSanitizer,
        );
        $draftActor = $provisioner->provision(new SiteActorDraft(
            ActorType::SERVICE,
            new LocalHandle('journal'),
            'Journal',
            '<p>A journal.</p>',
            'https://journal.example/about',
        ), $clock->now - 100);
        $activation = new FederationActivationService(
            $this->dbLayer,
            $this->stateRepository,
            $this->actorRepository,
            $transaction,
        );
        $this->actor = $activation->activate(new ActivationReadinessReport(
            $draftActor->publicId,
            new CanonicalOrigin('https://journal.example'),
            new CanonicalBasePath(''),
            $clock->now - 50,
            array_map(
                static fn(ActivationReadinessCheck $check): ActivationCheckResult => new ActivationCheckResult(
                    $check,
                    true,
                    'Passed.',
                ),
                ActivationReadinessCheck::cases(),
            ),
        ), $clock->now - 25);

        $urlFactory           = new FederationUrlGeneratorFactory($this->stateRepository);
        $signingService       = new LocalActorSigningService(
            $this->actorRepository,
            $urlFactory,
            $vault,
            $this->legacySignature,
            $this->rfc9421Signature,
        );
        $canonicalJson        = new CanonicalJson();
        $this->federationRepository = new LocalFederationRepository($this->dbLayer);
        $deliveryRepository   = new DeliveryRepository($this->dbLayer);
        $queuePublisher       = new QueuePublisher($this->pdo, '');
        $deliveryQueue        = new DeliveryQueue($queuePublisher, $deliveryRepository);
        $deliveryPlanner      = new DeliveryPlanner($deliveryRepository, $deliveryQueue);
        $this->inboxRepository = new InboxRepository($this->dbLayer);
        $inboxQueue            = new InboxQueue($queuePublisher, $this->inboxRepository);
        $safeHttpClient        = new SafeRemoteHttpClient($transport, new PublicAddressGuard($resolver));
        $this->followRepository = new FollowRepository($this->dbLayer);
        $followRepository       = $this->followRepository;

        $this->moderationRepository = new ModerationRuleRepository($this->dbLayer);
        $this->remoteActorRepository = new RemoteActorRepository($this->dbLayer);
        $this->outgoingFollowService = new OutgoingFollowService(
            $this->stateRepository,
            $this->actorRepository,
            $this->remoteActorRepository,
            $followRepository,
            $this->moderationRepository,
            $this->federationRepository,
            $urlFactory,
            new PublicIdGenerator(),
            new LocalActivityDocumentBuilder(),
            $canonicalJson,
            $deliveryPlanner,
            $transaction,
        );
        $notificationRepository = new NotificationRepository($this->dbLayer, $canonicalJson);
        $this->interactionRepository = new InteractionRepository($this->dbLayer, $canonicalJson);
        $this->remoteObjectRepository = new RemoteObjectRepository($this->dbLayer, $canonicalJson);
        $interactionProcessor  = new InboxInteractionProcessor(
            new RemoteObjectDocumentValidator($htmlSanitizer),
            $this->remoteObjectRepository,
            $this->interactionRepository,
            $this->federationRepository,
            $this->actorRepository,
            $followRepository,
            $urlFactory,
            new CommentImportService(new CommentRepository(
                $this->dbLayer,
                new LiveUpdateRepository($this->dbLayer),
                new EventDispatcher(),
            )),
            new RemoteCommentTextFormatter(),
            new ReactionAggregateRepository($this->dbLayer),
            $this->moderationRepository,
            $notificationRepository,
        );
        $activityProcessor     = new InboxActivityProcessor(
            $this->stateRepository,
            $this->actorRepository,
            $this->federationRepository,
            $followRepository,
            $urlFactory,
            new PublicIdGenerator(),
            new LocalActivityDocumentBuilder(),
            $canonicalJson,
            $deliveryPlanner,
            $interactionProcessor,
            $this->moderationRepository,
            $notificationRepository,
            $this->remoteActorRepository,
            $this->outgoingFollowService,
        );
        $actorFetchClient = new RemoteActorFetchClient($safeHttpClient, $signingService);
        $actorValidator = new RemoteActorDocumentValidator($rsaCrypto, $canonicalJson);
        $this->actorDiscovery = new RemoteActorDiscovery(
            new WebFingerClient($safeHttpClient),
            $actorFetchClient,
            $actorValidator,
            $this->remoteActorRepository,
        );
        $this->outgoingReplyService = new OutgoingReplyService(
            $this->stateRepository,
            $this->actorRepository,
            $this->remoteActorRepository,
            $this->remoteObjectRepository,
            $this->moderationRepository,
            $this->federationRepository,
            $urlFactory,
            new PublicIdGenerator(),
            new LocalNoteDocumentBuilder(),
            new LocalActivityDocumentBuilder(),
            $canonicalJson,
            $deliveryPlanner,
            $transaction,
        );
        $localInteractionRepository = new LocalInteractionRepository($this->dbLayer);
        $this->outgoingInteractionService = new OutgoingInteractionService(
            $this->stateRepository,
            $this->actorRepository,
            $this->remoteActorRepository,
            $this->remoteObjectRepository,
            $localInteractionRepository,
            $this->moderationRepository,
            $this->federationRepository,
            $urlFactory,
            new PublicIdGenerator(),
            new LocalActivityDocumentBuilder(),
            $canonicalJson,
            $deliveryPlanner,
            $transaction,
        );
        $handler = new InboxQueueHandler(
            $this->inboxRepository,
            $this->actorRepository,
            $this->remoteActorRepository,
            $actorFetchClient,
            $actorValidator,
            new IncomingSignatureVerifier($this->legacySignature, $this->rfc9421Signature),
            $activityProcessor,
            $this->stateRepository,
            $transaction,
            $inboxQueue,
            $clock(...),
        );
        $this->consumer = new QueueConsumer(
            $this->pdo,
            '',
            new NullLogger(),
            new QueueHandlerRegistry($handler),
        );
        $this->controller = new InboxController(
            $this->stateRepository,
            $this->actorRepository,
            $urlFactory,
            new PublicFederationAccess($this->stateRepository),
            new InboxRequestValidator($this->legacySignature, $this->rfc9421Signature, $clock(...)),
            new InboxRateLimiter($this->dbLayer),
            $this->inboxRepository,
            $inboxQueue,
            new ActivityPubResponseFactory(),
            new NullLogger(),
            $clock(...),
        );
    }

    public function sharedInboxUrl(): string
    {
        return 'https://journal.example/activitypub/inbox';
    }

    public function followBody(?string $activityId = null): string
    {
        return json_encode([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $activityId ?? $this->remoteActorUrl . '/activities/follow-journal',
            'type'     => 'Follow',
            'actor'    => $this->remoteActorUrl,
            'object'   => 'https://journal.example/activitypub/actors/' . $this->actor->publicId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function followRequest(
        bool $rfc9421 = false,
        bool $tamperSignature = false,
        ?string $activityId = null,
    ): Request {
        $document = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => $activityId ?? $this->remoteActorUrl . '/activities/follow-journal',
            'type'     => 'Follow',
            'actor'    => $this->remoteActorUrl,
            'object'   => 'https://journal.example/activitypub/actors/' . $this->actor->publicId,
        ];

        return $this->activityRequest($document, null, $rfc9421, $tamperSignature);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function activityRequest(
        array   $document,
        ?string $targetActorPublicId = null,
        bool    $rfc9421 = false,
        bool    $tamperSignature = false,
    ): Request {
        $body = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $target = $targetActorPublicId === null
            ? $this->sharedInboxUrl()
            : 'https://journal.example/activitypub/actors/' . $targetActorPublicId . '/inbox';
        $signatureRequest = new HttpSignatureRequest(
            'POST',
            $target,
            ['Content-Type' => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE],
            $body,
        );
        $keyId = $this->remoteActorUrl . '#main-key';
        $signed = $rfc9421
            ? $this->rfc9421Signature->sign($signatureRequest, $keyId, $this->remoteKeyPair->privateKeyPem, $this->clock->now)
            : $this->legacySignature->sign($signatureRequest, $keyId, $this->remoteKeyPair->privateKeyPem, $this->clock->now);
        $headers = [
            'Content-Type' => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE,
            ...$signed->headers,
        ];
        if ($tamperSignature) {
            $offset = \strlen($headers['Signature']) - 2;
            $headers['Signature'][$offset] = $headers['Signature'][$offset] === 'A' ? 'B' : 'A';
        }

        $request = Request::create(
            $target,
            'POST',
            server: ['REMOTE_ADDR' => '203.0.113.10'],
            content: $body,
        );
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        if ($targetActorPublicId !== null) {
            $request->attributes->set('publicId', $targetActorPublicId);
        }

        return $request;
    }

    public function createLocalObject(int $contentId = 42): StoredObjectRepresentation
    {
        $publicId = (new PublicIdGenerator())->generate();
        $objectUrl = 'https://journal.example/activitypub/objects/' . $publicId;
        $snapshot = json_encode([
            '@context'     => 'https://www.w3.org/ns/activitystreams',
            'id'           => $objectUrl,
            'type'         => 'Article',
            'attributedTo' => 'https://journal.example/activitypub/actors/' . $this->actor->publicId,
            'url'          => 'https://journal.example/posts/local-' . $contentId,
            'content'      => '<p>Local post.</p>',
            'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->federationRepository->insertObject(new NewStoredObject(
            $publicId,
            ContentId::post($contentId),
            1,
            $this->actor->id,
            'Article',
            'public',
            'https://journal.example/posts/local-' . $contentId,
            $snapshot,
            hash('sha256', $snapshot),
            $this->clock->now - 100,
            $this->clock->now - 100,
            $this->clock->now - 100,
        ));
    }

    public function localObjectUrl(StoredObjectRepresentation $object): string
    {
        return 'https://journal.example/activitypub/objects/' . $object->publicId;
    }

    public function remoteActorDocument(): string
    {
        return json_encode([
            '@context'         => 'https://www.w3.org/ns/activitystreams',
            'id'               => $this->remoteActorUrl,
            'type'             => 'Person',
            'preferredUsername' => 'alice',
            'name'             => 'Alice',
            'inbox'            => $this->remoteInboxUrl,
            'endpoints'        => ['sharedInbox' => 'https://remote.example/inbox'],
            'featured'         => $this->remoteActorUrl . '/collections/featured',
            'publicKey'        => [
                'id'           => $this->remoteActorUrl . '#main-key',
                'owner'        => $this->remoteActorUrl,
                'publicKeyPem' => $this->remoteKeyPair->publicKeyPem,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @phpstan-impure Advances the database-backed queue and mutates its domain tables. */
    public function runInboxQueue(): bool
    {
        return $this->consumer->runQueue(
            $this->clock->now,
            new QueueExecutionBudget(10.0, static fn(): float => 0.0),
        );
    }

    public function setLifecycle(FederationLifecycleState $state): void
    {
        $statement = $this->pdo->prepare('UPDATE ' . ActivityPubSchema::STATE_TABLE
            . ' SET lifecycle_state = :state, updated_at = :updated_at WHERE id = :id');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to prepare the ActivityPub state fixture.');
        }

        $statement->execute([
            'state'      => $state->value,
            'updated_at' => $this->clock->now,
            'id'         => 'installation',
        ]);
    }

    public function tableCount(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to count an ActivityPub test table.');
        }

        $value = $statement->fetchColumn();
        return $value === false ? 0 : (int)$value;
    }

    /**
     * @phpstan-impure
     * @return array<string, mixed>
     */
    public function singleInboxRow(): array
    {
        return $this->singleRow(ActivityPubSchema::INBOX_TABLE);
    }

    /**
     * @phpstan-impure
     * @return array<string, mixed>
     */
    public function singleRow(string $table): array
    {
        $statement = $this->pdo->query('SELECT * FROM ' . $table);
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to query an ActivityPub test table.');
        }

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if (\count($rows) !== 1) {
            throw new \RuntimeException('Expected exactly one ActivityPub fixture row in ' . $table . '.');
        }

        return $rows[0];
    }

    /** @return array<string, mixed>|null */
    public function rowById(string $table, int $id): ?array
    {
        return $this->rowByUrl($table, 'id', $id);
    }

    /** @return array<string, mixed>|null */
    public function rowByUrl(string $table, string $column, int|string $value): ?array
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1
            || preg_match('/^[a-z][a-z0-9_]*$/D', $column) !== 1
        ) {
            throw new \InvalidArgumentException('An ActivityPub fixture lookup identifier is invalid.');
        }

        $statement = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . $column . ' = :value');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to query an ActivityPub fixture row.');
        }

        $statement->execute(['value' => $value]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function queueRow(string $id, string $code): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM queue WHERE id = :id AND code = :code');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to query the ActivityPub queue fixture.');
        }

        $statement->execute(['id' => $id, 'code' => $code]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? $row : null;
    }

    private function createQueueTable(): void
    {
        $this->pdo->exec('CREATE TABLE queue (
            id TEXT NOT NULL,
            code TEXT NOT NULL,
            payload TEXT NOT NULL,
            generation INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            available_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL,
            last_error TEXT,
            failed_at INTEGER,
            PRIMARY KEY (id, code)
        )');
    }
}

/** @internal */
final class InboxTestTransport implements HttpClientInterface
{
    /**
     * @var list<array{
     *     method: string,
     *     url: string,
     *     headers: array<string, string>,
     *     body: string|null,
     *     options: array<string, int|bool|string>
     * }>
     */
    public array $requests = [];

    /** @param list<HttpResponse|\Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    public function append(HttpResponse|\Throwable $response): void
    {
        $this->responses[] = $response;
    }

    public function requestCount(): int
    {
        return \count($this->requests);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, int|bool|string> $options
     */
    #[\Override]
    public function request(
        string  $method,
        string  $url,
        array   $headers = [],
        ?string $body = null,
        array   $options = [],
    ): HttpResponse {
        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
            'options' => $options,
        ];
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        if (!$response instanceof HttpResponse) {
            throw new \RuntimeException('The ActivityPub inbox test transport has no response queued.');
        }

        return $response;
    }

    #[\Override]
    public function resolveRedirectUrl(string $location, string $currentUrl): string
    {
        return (new HttpClient())->resolveRedirectUrl($location, $currentUrl);
    }
}

/** @internal */
final readonly class InboxTestHostResolver implements HostResolverInterface
{
    /** @param array<string, list<string>> $answers */
    public function __construct(private array $answers)
    {
    }

    /** @return list<string> */
    #[\Override]
    public function resolve(string $host, ?float $timeoutSeconds = null): array
    {
        unset($timeoutSeconds);

        return $this->answers[$host] ?? [];
    }
}

/** @internal */
final class InboxTestClock
{
    public function __construct(public int $now)
    {
    }

    public function __invoke(): int
    {
        return $this->now;
    }
}
