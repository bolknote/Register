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
use Register\Extension\activitypub\Application\SiteActorDraft;
use Register\Extension\activitypub\Application\SiteActorProvisioner;
use Register\Extension\activitypub\Content\PortableHtmlSanitizer;
use Register\Extension\activitypub\Delivery\ActivityDeliveryClient;
use Register\Extension\activitypub\Delivery\DeliveryPlanner;
use Register\Extension\activitypub\Delivery\DeliveryQueue;
use Register\Extension\activitypub\Delivery\DeliveryQueueHandler;
use Register\Extension\activitypub\Delivery\MentionDeliveryPlanner;
use Register\Extension\activitypub\Delivery\MentionDeliveryQueue;
use Register\Extension\activitypub\Delivery\MentionDeliveryQueueHandler;
use Register\Extension\activitypub\Delivery\OriginDeliveryThrottle;
use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Domain\ActorKind;
use Register\Extension\activitypub\Domain\ActorType;
use Register\Extension\activitypub\Domain\CanonicalBasePath;
use Register\Extension\activitypub\Domain\CanonicalOrigin;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\LocalHandle;
use Register\Extension\activitypub\Domain\NewLocalActor;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;
use Register\Extension\activitypub\Infrastructure\ClaimedDelivery;
use Register\Extension\activitypub\Infrastructure\DeliveryRepository;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\ModerationRuleRepository;
use Register\Extension\activitypub\Infrastructure\NewStoredActivity;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation;
use Register\Extension\activitypub\Inbox\RemoteActorDocumentValidator;
use Register\Extension\activitypub\Inbox\RemoteActorFetchClient;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Security\ActivityPubSecret;
use Register\Extension\activitypub\Security\ActorKeyVault;
use Register\Extension\activitypub\Security\HttpSignatureRequest;
use Register\Extension\activitypub\Security\LegacyHttpSignature;
use Register\Extension\activitypub\Security\LocalActorSigningService;
use Register\Extension\activitypub\Security\Rfc9421HttpSignature;
use Register\Extension\activitypub\Security\RsaCrypto;
use Symfony\Component\Filesystem\Filesystem;

final class DeliveryTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_activitypub_delivery_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testPlannerGroupsSharedInboxesAndKeepsRecipientAuditData(): void
    {
        $environment = $this->environment();
        $environment->addFollower(
            'https://social.example/users/alice',
            'https://social.example/users/alice/inbox',
            'https://social.example/inbox',
        );
        $environment->addFollower(
            'https://social.example/users/bob',
            'https://social.example/users/bob/inbox',
            'https://social.example/inbox',
        );
        $environment->addFollower(
            'https://other.example/users/carol',
            'https://other.example/users/carol/inbox',
        );
        $activity = $environment->activity(1_000);

        self::assertSame(2, $environment->planner->plan($activity, 1_000));
        self::assertSame(0, $environment->planner->plan($activity, 1_000));

        $byInbox = [];
        foreach ($environment->deliveryRows() as $row) {
            $byInbox[(string)$row['inbox_url']] = $this->stringList((string)$row['recipient_json']);
        }

        self::assertSame([
            'https://other.example/users/carol/inbox' => ['https://other.example/users/carol'],
            'https://social.example/inbox'            => [
                'https://social.example/users/alice',
                'https://social.example/users/bob',
            ],
        ], $byInbox);

        $queue = $environment->queueRow();
        self::assertNotNull($queue);
        self::assertSame(DeliveryQueue::JOB_ID, $queue['id']);
        self::assertSame(DeliveryQueue::CODE, $queue['code']);
        self::assertSame(1_000, (int)$queue['available_at']);
        self::assertSame(0, (int)$queue['attempts']);
    }

    public function testPlannerUnionsAuthorAndCollectiveFollowersPerSharedInbox(): void
    {
        $environment = $this->environment();
        $authorActorId = $environment->actorRepository->insert(new NewLocalActor(
            (new PublicIdGenerator())->generate(),
            ActorKind::AUTHOR,
            1,
            ActorType::PERSON,
            new LocalHandle('alice'),
            'Alice',
            '<p>Author.</p>',
            'https://journal.example/alice',
        ), LocalActorState::ACTIVE, 900);
        $environment->addFollower(
            'https://social.example/users/site-reader',
            'https://social.example/users/site-reader/inbox',
            'https://social.example/inbox',
            $environment->actor->id,
        );
        $environment->addFollower(
            'https://social.example/users/author-reader',
            'https://social.example/users/author-reader/inbox',
            'https://social.example/inbox',
            $authorActorId,
        );
        $environment->addFollower(
            'https://other.example/users/author-reader',
            'https://other.example/users/author-reader/inbox',
            null,
            $authorActorId,
        );
        $activity = $environment->activity(1_000);

        self::assertSame(2, $environment->planner->planForActors(
            $activity,
            [$environment->actor->id, $authorActorId],
            1_000,
        ));
        $byInbox = [];
        foreach ($environment->deliveryRows() as $row) {
            $byInbox[(string)$row['inbox_url']] = $this->stringList((string)$row['recipient_json']);
        }

        self::assertSame([
            'https://other.example/users/author-reader/inbox' => ['https://other.example/users/author-reader'],
            'https://social.example/inbox' => [
                'https://social.example/users/author-reader',
                'https://social.example/users/site-reader',
            ],
        ], $byInbox);
    }

    public function testDirectMentionMergesRecipientAuditIntoExistingSharedInboxDelivery(): void
    {
        $environment = $this->environment();
        $environment->addFollower(
            'https://social.example/users/alice',
            'https://social.example/users/alice/inbox',
            'https://social.example/inbox',
        );
        $activity = $environment->activity(1_000);

        self::assertSame(1, $environment->planner->plan($activity, 1_000));
        self::assertSame(0, $environment->planner->planDirectRecipients(
            $activity,
            'https://social.example/inbox',
            ['https://social.example/users/bob'],
            1_000,
        ));

        $delivery = $environment->singleDeliveryRow();
        self::assertSame([
            'https://social.example/users/alice',
            'https://social.example/users/bob',
        ], $this->stringList((string)$delivery['recipient_json']));
    }

    public function testUnknownMentionIsResolvedAfterPublicationAndBecomesDurableDelivery(): void
    {
        $remoteActorUrl = 'https://social.example/users/bob';
        $remoteKeyPair = (new RsaCrypto())->generateKeyPair();
        $actorDocument = json_encode([
            '@context'          => 'https://www.w3.org/ns/activitystreams',
            'id'                => $remoteActorUrl,
            'type'              => 'Person',
            'preferredUsername' => 'bob',
            'name'              => 'Bob',
            'inbox'             => $remoteActorUrl . '/inbox',
            'endpoints'         => ['sharedInbox' => 'https://social.example/inbox'],
            'publicKey'         => [
                'id'           => $remoteActorUrl . '#main-key',
                'owner'        => $remoteActorUrl,
                'publicKeyPem' => $remoteKeyPair->publicKeyPem,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $environment = $this->environment(
            [new HttpResponse(['HTTP/1.1 200 OK', 'Content-Type: application/activity+json'], 200, $actorDocument)],
            ['social.example' => ['93.184.216.34']],
            10_000,
        );
        $activity = $environment->activity(10_000);
        $environment->mentionQueue->schedule($activity->id, $environment->actor->id, $remoteActorUrl, 10_000);

        self::assertTrue($environment->runQueue());
        self::assertSame(1, $environment->transport->requestCount());
        self::assertSame('GET', $environment->transport->requests[0]['method']);
        self::assertSame($remoteActorUrl, $environment->transport->requests[0]['url']);
        self::assertSame('93.184.216.34', $environment->transport->requests[0]['options'][HttpClient::RESOLVE_IP]);
        self::assertNotNull($environment->remoteActorRepository->findByUrl($remoteActorUrl));

        $delivery = $environment->singleDeliveryRow();
        self::assertSame('https://social.example/inbox', $delivery['inbox_url']);
        self::assertSame([$remoteActorUrl], $this->stringList((string)$delivery['recipient_json']));
        $queue = $environment->queueRow();
        self::assertNotNull($queue);
        self::assertSame(DeliveryQueue::CODE, $queue['code']);
    }

    public function testShutdownQueueDeliversOneDnsPinnedAndVerifiableSignedPost(): void
    {
        $environment = $this->environment(
            [new HttpResponse(['HTTP/1.1 202 Accepted'], 202, '')],
            ['social.example' => ['93.184.216.34']],
            2_000,
        );
        $environment->addFollower(
            'https://social.example/users/alice',
            'https://social.example/inbox',
        );
        $activity = $environment->activity(2_000);
        $environment->planner->plan($activity, 2_000);

        self::assertTrue($environment->runQueue());

        $delivery = $environment->singleDeliveryRow();
        self::assertSame('delivered', $delivery['state']);
        self::assertSame(202, (int)$delivery['http_status']);
        self::assertSame(1, (int)$delivery['attempt_count']);
        self::assertSame(1, $environment->transport->requestCount());
        self::assertNull($environment->queueRow());

        $request = $environment->transport->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://social.example/inbox', $request['url']);
        self::assertSame($activity->serializedBody, $request['body']);
        self::assertSame('93.184.216.34', $request['options'][HttpClient::RESOLVE_IP]);
        self::assertFalse($request['options'][HttpClient::FOLLOW_REDIRECTS]);
        self::assertArrayHasKey('Date', $request['headers']);
        self::assertArrayHasKey('Digest', $request['headers']);
        self::assertArrayHasKey('Signature', $request['headers']);
        self::assertArrayNotHasKey('Host', $request['headers']);

        $key = $environment->actorRepository->currentKey($environment->actor->id);
        self::assertNotNull($key);
        $verified = $environment->legacySignature->verify(
            new HttpSignatureRequest(
                'POST',
                $request['url'],
                [
                    'Host'         => 'social.example',
                    'Date'         => $request['headers']['Date'],
                    'Digest'       => $request['headers']['Digest'],
                    'Content-Type' => $request['headers']['Content-Type'],
                ],
                $request['body'],
            ),
            $request['headers']['Signature'],
            $key->publicKeyPem,
            2_000,
        );
        self::assertStringEndsWith('/activitypub/keys/' . $key->publicId, $verified->keyId);
    }

    public function testTemporaryFailureUsesDurableBackoffWithoutConsumingGenericRetries(): void
    {
        $environment = $this->environment(
            [new HttpResponse(['HTTP/1.1 503 Service Unavailable'], 503, '')],
            ['social.example' => ['93.184.216.34']],
            3_000,
        );
        $environment->addFollower('https://social.example/users/alice', 'https://social.example/inbox');

        $activity = $environment->activity(3_000);
        $environment->planner->plan($activity, 3_000);

        self::assertTrue($environment->runQueue());

        $delivery = $environment->singleDeliveryRow();
        self::assertSame('delayed', $delivery['state']);
        self::assertSame('remote_temporary', $delivery['error_code']);
        self::assertSame(503, (int)$delivery['http_status']);
        self::assertGreaterThanOrEqual(3_030, (int)$delivery['available_at']);
        self::assertLessThanOrEqual(3_035, (int)$delivery['available_at']);

        $queue = $environment->queueRow();
        self::assertNotNull($queue);
        self::assertSame((int)$delivery['available_at'], (int)$queue['available_at']);
        self::assertSame(0, (int)$queue['attempts']);
        self::assertNull($queue['failed_at']);
        self::assertSame(2, (int)$queue['generation']);

        $attempt = $environment->singleAttemptRow();
        self::assertSame('delayed', $attempt['result']);
        self::assertSame(503, (int)$attempt['http_status']);
    }

    public function testRetryAfterBlocksOriginAndThenResumes(): void
    {
        $environment = $this->environment(
            [
                new HttpResponse(['HTTP/1.1 429 Too Many Requests', 'Retry-After: 120'], 429, ''),
                new HttpResponse(['HTTP/1.1 204 No Content'], 204, ''),
            ],
            ['social.example' => ['93.184.216.34']],
            4_000,
        );
        $environment->addFollower('https://social.example/users/alice', 'https://social.example/inbox');

        $activity = $environment->activity(4_000);
        $environment->planner->plan($activity, 4_000);

        self::assertTrue($environment->runQueue());
        $delivery = $environment->singleDeliveryRow();
        self::assertSame('delayed', $delivery['state']);
        self::assertSame('rate_limited', $delivery['error_code']);
        self::assertSame(4_120, (int)$delivery['available_at']);
        self::assertSame(1, $environment->transport->requestCount());

        $environment->clock->now = 4_119;
        self::assertFalse($environment->runQueue());
        self::assertSame(1, $environment->transport->requestCount());

        $environment->clock->now = 4_120;
        self::assertTrue($environment->runQueue());
        self::assertSame('delivered', $environment->singleDeliveryRow()['state']);
        self::assertSame(2, $environment->transport->requestCount());
        self::assertNull($environment->queueRow());
    }

    public function testRedirectIsPersistedAndResignedAsASeparateHop(): void
    {
        $environment = $this->environment(
            [
                new HttpResponse(['HTTP/1.1 307 Temporary Redirect', 'Location: https://new.example/inbox'], 307, ''),
                new HttpResponse(['HTTP/1.1 202 Accepted'], 202, ''),
            ],
            [
                'old.example' => ['93.184.216.34'],
                'new.example' => ['93.184.216.35'],
            ],
            5_000,
        );
        $environment->addFollower('https://old.example/users/alice', 'https://old.example/inbox');

        $activity = $environment->activity(5_000);
        $environment->planner->plan($activity, 5_000);

        self::assertTrue($environment->runQueue());
        self::assertSame(1, $environment->transport->requestCount());
        $redirected = $environment->singleDeliveryRow();
        self::assertSame('delayed', $redirected['state']);
        self::assertSame('https://new.example/inbox', $redirected['request_url']);
        self::assertSame(1, (int)$redirected['redirect_count']);
        self::assertSame(['https://old.example/inbox'], $this->stringList((string)$redirected['redirect_chain_json']));

        $environment->clock->now = 5_001;
        self::assertTrue($environment->runQueue());
        self::assertSame('delivered', $environment->singleDeliveryRow()['state']);
        self::assertSame(2, $environment->transport->requestCount());
        self::assertSame('https://old.example/inbox', $environment->transport->requests[0]['url']);
        self::assertSame('https://new.example/inbox', $environment->transport->requests[1]['url']);
        self::assertNotSame(
            $environment->transport->requests[0]['headers']['Signature'],
            $environment->transport->requests[1]['headers']['Signature'],
        );
    }

    public function testStaleClaimIsRecoveredAfterAStoppedPhpProcess(): void
    {
        $environment = $this->environment(
            [new HttpResponse(['HTTP/1.1 202 Accepted'], 202, '')],
            ['social.example' => ['93.184.216.34']],
            6_000,
        );
        $environment->addFollower('https://social.example/users/alice', 'https://social.example/inbox');

        $activity = $environment->activity(6_000);
        $environment->planner->plan($activity, 6_000);
        $abandoned = $environment->deliveryRepository->claimNext(6_000);
        self::assertInstanceOf(ClaimedDelivery::class, $abandoned);

        $environment->clock->now = 6_121;
        self::assertTrue($environment->runQueue());

        $delivery = $environment->singleDeliveryRow();
        self::assertSame('delivered', $delivery['state']);
        self::assertSame(2, (int)$delivery['attempt_count']);
        self::assertSame(1, $environment->transport->requestCount());
    }

    public function testPrivateDestinationIsFailedBeforeTransport(): void
    {
        $environment = $this->environment(
            [new HttpResponse(['HTTP/1.1 202 Accepted'], 202, '')],
            ['internal.example' => ['127.0.0.1']],
            7_000,
        );
        $environment->addFollower('https://internal.example/users/alice', 'https://internal.example/inbox');

        $activity = $environment->activity(7_000);
        $environment->planner->plan($activity, 7_000);

        self::assertTrue($environment->runQueue());

        $delivery = $environment->singleDeliveryRow();
        self::assertSame('failed', $delivery['state']);
        self::assertSame('unsafe_address', $delivery['error_code']);
        self::assertSame(0, $environment->transport->requestCount());
        self::assertNull($environment->queueRow());
    }

    public function testPauseStopsNetworkWorkAndDurablySchedulesAResumeProbe(): void
    {
        $environment = $this->environment(
            [new HttpResponse(['HTTP/1.1 202 Accepted'], 202, '')],
            ['social.example' => ['93.184.216.34']],
            8_000,
        );
        $environment->addFollower('https://social.example/users/alice', 'https://social.example/inbox');

        $activity = $environment->activity(8_000);
        $environment->planner->plan($activity, 8_000);
        $environment->setLifecycle(FederationLifecycleState::PAUSED);

        self::assertTrue($environment->runQueue());
        self::assertSame(0, $environment->transport->requestCount());
        self::assertSame('pending', $environment->singleDeliveryRow()['state']);
        $queue = $environment->queueRow();
        self::assertNotNull($queue);
        self::assertSame(8_300, (int)$queue['available_at']);
        self::assertSame(0, (int)$queue['attempts']);

        $environment->setLifecycle(FederationLifecycleState::ACTIVE);
        $environment->clock->now = 8_300;
        self::assertTrue($environment->runQueue());
        self::assertSame(1, $environment->transport->requestCount());
        self::assertSame('delivered', $environment->singleDeliveryRow()['state']);
    }

    public function testAuthenticationGetsExactlyOneCompatibilityRetry(): void
    {
        $environment = $this->environment(
            [
                new HttpResponse(['HTTP/1.1 401 Unauthorized'], 401, ''),
                new HttpResponse(['HTTP/1.1 403 Forbidden'], 403, ''),
            ],
            ['social.example' => ['93.184.216.34']],
            9_000,
        );
        $environment->addFollower('https://social.example/users/alice', 'https://social.example/inbox');

        $activity = $environment->activity(9_000);
        $environment->planner->plan($activity, 9_000);

        self::assertTrue($environment->runQueue());
        $first = $environment->singleDeliveryRow();
        self::assertSame('delayed', $first['state']);
        self::assertSame('auth_refresh', $first['error_code']);
        self::assertSame(1, (int)$first['auth_refresh_count']);
        self::assertSame(9_030, (int)$first['available_at']);

        $environment->clock->now = 9_030;
        self::assertTrue($environment->runQueue());
        $second = $environment->singleDeliveryRow();
        self::assertSame('failed', $second['state']);
        self::assertSame('authentication', $second['error_code']);
        self::assertSame(2, (int)$second['attempt_count']);
        self::assertNull($environment->queueRow());
    }

    /**
     * @param list<HttpResponse|\Throwable> $responses
     * @param array<string, list<string>> $dnsAnswers
     */
    private function environment(
        array $responses = [],
        array $dnsAnswers = [],
        int   $now = 1_000,
    ): DeliveryTestEnvironment {
        return new DeliveryTestEnvironment(
            $this->temporaryDirectory,
            new DeliveryTestTransport($responses),
            new DeliveryTestHostResolver($dnsAnswers),
            new DeliveryTestClock($now),
        );
    }

    /** @return list<string> */
    private function stringList(string $json): array
    {
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('Expected a JSON list in the ActivityPub delivery test.');
        }

        return array_map(static function (mixed $value): string {
            if (!\is_string($value)) {
                throw new \RuntimeException('Expected a JSON string list in the ActivityPub delivery test.');
            }

            return $value;
        }, $decoded);
    }
}

/** @internal */
final readonly class DeliveryTestEnvironment
{
    public \PDO $pdo;

    public DbLayerSqlite $dbLayer;

    public FederationStateRepository $stateRepository;

    public LocalActorRepository $actorRepository;

    public LocalFederationRepository $federationRepository;

    public DeliveryRepository $deliveryRepository;

    public DeliveryQueue $deliveryQueue;

    public DeliveryPlanner $planner;

    public MentionDeliveryQueue $mentionQueue;

    public RemoteActorRepository $remoteActorRepository;

    public LegacyHttpSignature $legacySignature;

    public QueueConsumer $consumer;

    public LocalActor $actor;

    public function __construct(
        string                         $temporaryDirectory,
        public DeliveryTestTransport   $transport,
        DeliveryTestHostResolver       $resolver,
        public DeliveryTestClock       $clock,
    ) {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->createQueueTable();
        $this->dbLayer = new DbLayerSqlite($this->pdo);
        ActivityPubSchema::install($this->dbLayer);

        $registry = new DynamicSecretParameterRegistry(['CORE_TEST_SECRET']);
        $registry->registerExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $secrets = new DynamicSecretStore($temporaryDirectory . '/config.secrets.php', $registry);
        $secrets->getOrCreateExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $this->stateRepository      = new FederationStateRepository($this->dbLayer);
        $this->actorRepository      = new LocalActorRepository($this->dbLayer);
        $this->federationRepository = new LocalFederationRepository($this->dbLayer);
        $this->deliveryRepository   = new DeliveryRepository($this->dbLayer);
        $transaction                = new PortableDatabaseTransaction($this->pdo);
        $urlFactory                 = new FederationUrlGeneratorFactory($this->stateRepository);
        $rsaCrypto                  = new RsaCrypto();
        $keyVault                   = new ActorKeyVault($secrets);
        $provisioner                = new SiteActorProvisioner(
            $this->stateRepository,
            $this->actorRepository,
            new PublicIdGenerator(),
            $rsaCrypto,
            $keyVault,
            $transaction,
            new PortableHtmlSanitizer(new HttpClient()),
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

        $queuePublisher      = new QueuePublisher($this->pdo, '');
        $this->deliveryQueue = new DeliveryQueue($queuePublisher, $this->deliveryRepository);
        $this->planner       = new DeliveryPlanner($this->deliveryRepository, $this->deliveryQueue);
        $this->mentionQueue  = new MentionDeliveryQueue($queuePublisher);
        $this->remoteActorRepository = new RemoteActorRepository($this->dbLayer);
        $mentionPlanner = new MentionDeliveryPlanner(
            $this->remoteActorRepository,
            new ModerationRuleRepository($this->dbLayer),
            $this->planner,
            $this->mentionQueue,
        );
        $this->legacySignature = new LegacyHttpSignature($rsaCrypto);
        $signer = new LocalActorSigningService(
            $this->actorRepository,
            $urlFactory,
            $keyVault,
            $this->legacySignature,
            new Rfc9421HttpSignature($rsaCrypto),
        );
        $handler = new DeliveryQueueHandler(
            $this->deliveryRepository,
            new ActivityDeliveryClient(
                new SafeRemoteHttpClient($transport, new PublicAddressGuard($resolver)),
                $signer,
            ),
            new OriginDeliveryThrottle($this->dbLayer),
            $this->stateRepository,
            $this->deliveryQueue,
            $clock(...),
        );
        $mentionHandler = new MentionDeliveryQueueHandler(
            $this->federationRepository,
            $this->remoteActorRepository,
            new RemoteActorFetchClient(
                new SafeRemoteHttpClient($transport, new PublicAddressGuard($resolver)),
                $signer,
            ),
            new RemoteActorDocumentValidator($rsaCrypto, new CanonicalJson()),
            $this->stateRepository,
            $mentionPlanner,
            $this->mentionQueue,
            new NullLogger(),
            $clock(...),
        );
        $this->consumer = new QueueConsumer(
            $this->pdo,
            '',
            new NullLogger(),
            new QueueHandlerRegistry($handler, $mentionHandler),
        );
    }

    public function addFollower(
        string  $actorUrl,
        string  $inboxUrl,
        ?string $sharedInboxUrl = null,
        ?int    $localActorId = null,
    ): void
    {
        $statement = $this->pdo->prepare('INSERT INTO ' . ActivityPubSchema::REMOTE_ACTOR_TABLE . ' (
            url_hash, actor_url, actor_type, preferred_username, display_name, inbox_url,
            shared_inbox_url, public_key_id, public_key_pem, also_known_as_json,
            state, failure_count, fetched_at, expires_at, created_at, updated_at
        ) VALUES (
            :url_hash, :actor_url, :actor_type, :preferred_username, :display_name, :inbox_url,
            :shared_inbox_url, :public_key_id, :public_key_pem, :also_known_as_json,
            :state, 0, :fetched_at, :expires_at, :created_at, :updated_at
        )');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to prepare the remote actor fixture.');
        }

        $path = parse_url($actorUrl, PHP_URL_PATH);
        $preferredUsername = \is_string($path) && $path !== '' ? basename($path) : 'remote';
        $statement->execute([
            'url_hash'           => hash('sha256', $actorUrl),
            'actor_url'          => $actorUrl,
            'actor_type'         => 'Person',
            'preferred_username' => $preferredUsername,
            'display_name'       => 'Remote follower',
            'inbox_url'          => $inboxUrl,
            'shared_inbox_url'   => $sharedInboxUrl,
            'public_key_id'      => $actorUrl . '#main-key',
            'public_key_pem'     => 'unused-test-key',
            'also_known_as_json' => '[]',
            'state'              => 'active',
            'fetched_at'         => $this->clock->now,
            'expires_at'         => $this->clock->now + 3_600,
            'created_at'         => $this->clock->now,
            'updated_at'         => $this->clock->now,
        ]);
        $remoteActorId = (int)$this->pdo->lastInsertId();

        $statement = $this->pdo->prepare('INSERT INTO ' . ActivityPubSchema::FOLLOW_TABLE . ' (
            direction, local_actor_id, remote_actor_id, state, follow_activity_url,
            follow_activity_hash, created_at, updated_at, accepted_at
        ) VALUES (
            :direction, :local_actor_id, :remote_actor_id, :state, :follow_activity_url,
            :follow_activity_hash, :created_at, :updated_at, :accepted_at
        )');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to prepare the follow fixture.');
        }

        $followActivity = $actorUrl . '#follow-journal';
        $statement->execute([
            'direction'            => 'incoming',
            'local_actor_id'        => $localActorId ?? $this->actor->id,
            'remote_actor_id'       => $remoteActorId,
            'state'                 => 'accepted',
            'follow_activity_url'   => $followActivity,
            'follow_activity_hash'  => hash('sha256', $followActivity),
            'created_at'            => $this->clock->now,
            'updated_at'            => $this->clock->now,
            'accepted_at'           => $this->clock->now,
        ]);
    }

    public function activity(int $now): StoredActivityRepresentation
    {
        $publicId = (new PublicIdGenerator())->generate();
        $body = json_encode([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => 'https://journal.example/activitypub/activities/' . $publicId,
            'type'     => 'Create',
            'actor'    => 'https://journal.example/activitypub/actors/' . $this->actor->publicId,
            'object'   => ['type' => 'Note', 'content' => '<p>Hello federation.</p>'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->federationRepository->insertActivity(new NewStoredActivity(
            $publicId,
            $this->actor->id,
            null,
            'Create',
            'public',
            ActivityDeliveryIntent::FOLLOWERS,
            'delivery-test:' . $publicId,
            $body,
            hash('sha256', $body),
            $now,
            $now,
        ));
    }

    public function runQueue(): bool
    {
        return $this->consumer->runQueue(
            $this->clock->now,
            new QueueExecutionBudget(10.0, static fn(): float => 0.0),
        );
    }

    public function setLifecycle(FederationLifecycleState $state): void
    {
        $statement = $this->pdo->prepare('UPDATE ' . ActivityPubSchema::STATE_TABLE . '
            SET lifecycle_state = :state, updated_at = :updated_at WHERE id = :id');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to prepare the federation state fixture.');
        }

        $statement->execute([
            'state'      => $state->value,
            'updated_at' => $this->clock->now,
            'id'         => 'installation',
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function deliveryRows(): array
    {
        $statement = $this->pdo->query('SELECT * FROM ' . ActivityPubSchema::DELIVERY_TABLE . ' ORDER BY inbox_url');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to query delivery fixtures.');
        }

        return $this->listRows($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    public function singleDeliveryRow(): array
    {
        $rows = $this->deliveryRows();
        if (\count($rows) !== 1) {
            throw new \RuntimeException('Expected exactly one ActivityPub delivery fixture.');
        }

        return $rows[0];
    }

    /**
     * @param array<int|string, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function listRows(array $rows): array
    {
        return array_values($rows);
    }

    /** @return array<string, mixed>|null */
    public function queueRow(): ?array
    {
        $statement = $this->pdo->query('SELECT * FROM queue WHERE id = ' . $this->pdo->quote(DeliveryQueue::JOB_ID)
            . ' AND code = ' . $this->pdo->quote(DeliveryQueue::CODE));
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to query the queue fixture.');
        }

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return \is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    public function singleAttemptRow(): array
    {
        $statement = $this->pdo->query('SELECT * FROM ' . ActivityPubSchema::DELIVERY_ATTEMPT_TABLE);
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to query delivery attempt fixtures.');
        }

        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if (\count($rows) !== 1) {
            throw new \RuntimeException('Expected exactly one ActivityPub delivery attempt fixture.');
        }

        return $rows[0];
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
final class DeliveryTestTransport implements HttpClientInterface
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
            throw new \RuntimeException('The ActivityPub test transport has no response queued.');
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
final readonly class DeliveryTestHostResolver implements HostResolverInterface
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
final class DeliveryTestClock
{
    public function __construct(public int $now)
    {
    }

    public function __invoke(): int
    {
        return $this->now;
    }
}
