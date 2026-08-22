<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use Register\Core\Config\DynamicSecretParameterRegistry;
use Register\Core\Config\DynamicSecretStore;
use Register\Core\HttpClient\HttpClient;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Queue\QueuePublisher;
use Register\Extension\activitypub\Application\ActivationCheckResult;
use Register\Extension\activitypub\Application\ActivationReadinessCheck;
use Register\Extension\activitypub\Application\ActivationReadinessReport;
use Register\Extension\activitypub\Application\FederationActivationService;
use Register\Extension\activitypub\Application\ActorKeyRotationService;
use Register\Extension\activitypub\Application\ActorIdentityMigrationService;
use Register\Extension\activitypub\Application\ActivityPubIdentityRecoveryService;
use Register\Extension\activitypub\Application\FederationLifecycleService;
use Register\Extension\activitypub\Application\SiteActorDraft;
use Register\Extension\activitypub\Application\SiteActorProvisioner;
use Register\Extension\activitypub\Content\PortableHtmlSanitizer;
use Register\Extension\activitypub\Domain\ActorType;
use Register\Extension\activitypub\Domain\ActivityDeliveryIntent;
use Register\Extension\activitypub\Domain\CanonicalBasePath;
use Register\Extension\activitypub\Domain\CanonicalOrigin;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\LocalHandle;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\DeliveryRepository;
use Register\Extension\activitypub\Infrastructure\FollowRepository;
use Register\Extension\activitypub\Infrastructure\InboxRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\ModerationRuleRepository;
use Register\Extension\activitypub\Infrastructure\NewStoredActivity;
use Register\Extension\activitypub\Infrastructure\FetchedRemoteActor;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Security\ActivityPubSecret;
use Register\Extension\activitypub\Security\ActorKeyVault;
use Register\Extension\activitypub\Security\RsaCrypto;
use Register\Extension\activitypub\Delivery\DeliveryPlanner;
use Register\Extension\activitypub\Delivery\DeliveryQueue;
use Register\Extension\activitypub\Inbox\InboxQueue;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\ActorDocumentBuilder;
use Register\Extension\activitypub\Presentation\LocalActivityDocumentBuilder;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Symfony\Component\Filesystem\Filesystem;

final class ActivationTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_activitypub_activation_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testProvisioningStaysPrivateAndVerifiedActivationFreezesIdentityAtomically(): void
    {
        [$pdo, $dbLayer, $stateRepository, $actorRepository, $provisioner, $activation] = $this->services();

        // Exercise savepoint composition: production calls own a transaction, while tests and
        // future product workflows may already have one.
        $pdo->beginTransaction();
        $actor = $provisioner->provision(new SiteActorDraft(
            ActorType::ORGANIZATION,
            new LocalHandle('journal'),
            'The Journal',
            '<p>Independent publishing.</p>',
            'https://journal.example/about',
            metadata: [['name' => 'Editor', 'value' => 'Register']],
        ), 1_000);

        self::assertSame(LocalActorState::DRAFT, $actor->state);
        self::assertSame(FederationLifecycleState::INSTALLED, $stateRepository->lifecycleState());
        self::assertNull($stateRepository->state()->canonicalOrigin);
        $key = $actorRepository->currentKey($actor->id);
        self::assertNotNull($key);
        self::assertStringNotContainsString('PRIVATE KEY', $key->encryptedPrivateKey->ciphertext);

        $report = new ActivationReadinessReport(
            $actor->publicId,
            new CanonicalOrigin('https://journal.example'),
            new CanonicalBasePath('/register'),
            1_050,
            array_map(
                static fn(ActivationReadinessCheck $check): ActivationCheckResult => new ActivationCheckResult($check, true, 'Passed.'),
                ActivationReadinessCheck::cases(),
            ),
        );
        $activated = $activation->activate($report, 1_100);

        self::assertSame(LocalActorState::ACTIVE, $activated->state);
        self::assertSame(FederationLifecycleState::ACTIVE, $stateRepository->lifecycleState());
        $activatedState = $stateRepository->state();
        self::assertInstanceOf(CanonicalOrigin::class, $activatedState->canonicalOrigin);
        self::assertSame('https://journal.example', $activatedState->canonicalOrigin->value);
        self::assertSame('/register', $activatedState->basePath->value);
        self::assertSame(ActorType::ORGANIZATION, $activatedState->siteActorType);
        self::assertSame(1_100, $activatedState->activatedAt);
        self::assertSame(1, (int)$dbLayer->select('COUNT(*)')->from(ActivityPubSchema::ACTOR_TABLE)->execute()->result());
        self::assertSame(1, (int)$dbLayer->select('COUNT(*)')->from(ActivityPubSchema::ACTOR_KEY_TABLE)->execute()->result());

        $pdo->rollBack();
    }

    public function testFailedReadinessCannotPublishDraftIdentity(): void
    {
        [, , $stateRepository, $actorRepository, $provisioner, $activation] = $this->services();
        $actor = $provisioner->provision(new SiteActorDraft(
            ActorType::SERVICE,
            new LocalHandle('blog'),
            'Blog',
            '',
            'https://blog.example/',
        ), 2_000);

        $results = array_map(
            static fn(ActivationReadinessCheck $check): ActivationCheckResult => new ActivationCheckResult(
                $check,
                $check !== ActivationReadinessCheck::ROOT_WEBFINGER,
                $check === ActivationReadinessCheck::ROOT_WEBFINGER ? 'Origin-root rewrite is missing.' : 'Passed.',
            ),
            ActivationReadinessCheck::cases(),
        );

        try {
            $activation->activate(new ActivationReadinessReport(
                $actor->publicId,
                new CanonicalOrigin('https://blog.example'),
                new CanonicalBasePath(''),
                2_050,
                $results,
            ), 2_100);
            self::fail('A failed readiness report must block activation.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('root_webfinger', $exception->getMessage());
        }

        self::assertSame(FederationLifecycleState::INSTALLED, $stateRepository->lifecycleState());
        self::assertSame(LocalActorState::DRAFT, $actorRepository->findByPublicId($actor->publicId)?->state);
    }

    public function testKeyRotationAtomicallyPromotesVerifiedEncryptedKeyAndRetainsOldPublicKey(): void
    {
        [$pdo, , $stateRepository, $actorRepository, $provisioner, $activation, $secretStore] = $this->services();
        $actor = $this->activate($provisioner, $activation, 3_000);
        $oldKey = $actorRepository->currentKey($actor->id);
        self::assertNotNull($oldKey);

        $rotation = new ActorKeyRotationService(
            $stateRepository,
            $actorRepository,
            new PublicIdGenerator(),
            new RsaCrypto(),
            new ActorKeyVault($secretStore),
            new PortableDatabaseTransaction($pdo),
        );
        $newKey = $rotation->rotate($actor->id, 3_100);

        self::assertNotSame($oldKey->publicId, $newKey->publicId);
        self::assertTrue($newKey->current);
        self::assertStringNotContainsString('PRIVATE KEY', $newKey->encryptedPrivateKey->ciphertext);
        $retainedOldKey = $actorRepository->keyByPublicId($oldKey->publicId);
        self::assertNotNull($retainedOldKey);
        self::assertFalse($retainedOldKey->current);
        self::assertSame(3_100, $retainedOldKey->retiredAt);
    }

    public function testPauseResumeAndDecommissionLeaveTombstoneAfterActorDeleteDelivery(): void
    {
        [$pdo, $dbLayer, $stateRepository, $actorRepository, $provisioner, $activation] = $this->services();
        $actor = $this->activate($provisioner, $activation, 4_000);
        $queuePublisher = new QueuePublisher($pdo, '');
        $deliveryRepository = new DeliveryRepository($dbLayer);
        $deliveryQueue = new DeliveryQueue($queuePublisher, $deliveryRepository);
        $planner = new DeliveryPlanner($deliveryRepository, $deliveryQueue);
        $inboxRepository = new InboxRepository($dbLayer);
        $lifecycle = new FederationLifecycleService(
            $stateRepository,
            $actorRepository,
            $federationRepository = new LocalFederationRepository($dbLayer),
            $deliveryRepository,
            $inboxRepository,
            new FederationUrlGeneratorFactory($stateRepository),
            new PublicIdGenerator(),
            new LocalActivityDocumentBuilder(),
            new CanonicalJson(),
            $planner,
            $deliveryQueue,
            new InboxQueue($queuePublisher, $inboxRepository),
            new PortableDatabaseTransaction($pdo),
        );

        $lifecycle->pause(4_100);
        self::assertSame(FederationLifecycleState::PAUSED, $stateRepository->lifecycleState());
        $lifecycle->pause(4_101);
        $lifecycle->resume(4_200);
        self::assertSame(FederationLifecycleState::ACTIVE, $stateRepository->lifecycleState());

        $remotePair = (new RsaCrypto())->generateKeyPair();
        $remoteDocument = '{"id":"https://follower.example/users/alice"}';
        $remote = (new RemoteActorRepository($dbLayer))->save(new FetchedRemoteActor(
            'https://follower.example/users/alice',
            'Person',
            'alice',
            'Alice',
            'https://follower.example/users/alice/inbox',
            null,
            'https://follower.example/users/alice#main-key',
            $remotePair->publicKeyPem,
            [],
            $remoteDocument,
            hash('sha256', $remoteDocument),
            4_200,
            7_800,
        ));
        (new FollowRepository($dbLayer))->recordIncoming(
            $actor->id,
            $remote->id,
            'https://follower.example/activities/follow-1',
            true,
            4_210,
        );

        $ordinaryJson = '{"type":"Create"}';
        $ordinary = $federationRepository->insertActivity(new NewStoredActivity(
            (new PublicIdGenerator())->generate(),
            $actor->id,
            null,
            'Create',
            'public',
            ActivityDeliveryIntent::FOLLOWERS,
            'pre-decommission-delivery',
            $ordinaryJson,
            hash('sha256', $ordinaryJson),
            4_220,
            4_220,
        ));
        self::assertSame(1, $planner->plan($ordinary, 4_220));

        self::assertSame(1, $lifecycle->decommission(4_300));
        self::assertSame(FederationLifecycleState::DECOMMISSIONING, $stateRepository->lifecycleState());
        self::assertSame(LocalActorState::TOMBSTONED, $actorRepository->findById($actor->id)?->state);
        $rows = $dbLayer->select('activity.activity_type', 'delivery.state')
            ->from(ActivityPubSchema::DELIVERY_TABLE . ' AS delivery')
            ->innerJoin(ActivityPubSchema::ACTIVITY_TABLE . ' AS activity', 'activity.id = delivery.activity_id')
            ->orderBy('delivery.id')
            ->execute()
            ->fetchAssocAll()
        ;
        self::assertSame([
            ['activity_type' => 'Create', 'state' => 'cancelled'],
            ['activity_type' => 'Delete', 'state' => 'pending'],
        ], $rows);

        $dbLayer->update(ActivityPubSchema::DELIVERY_TABLE)
            ->set('state', ':state')->setParameter('state', 'delivered')
            ->where('state = :pending')->setParameter('pending', 'pending')
            ->execute()
        ;
        self::assertTrue($lifecycle->finishIfReady(4_400));
        self::assertSame(FederationLifecycleState::DECOMMISSIONED, $stateRepository->lifecycleState());
        self::assertSame(4_400, $stateRepository->decommissionedAt());
    }

    public function testIdentityRecoveryDocumentRestoresOnlyMatchingDatabaseKeys(): void
    {
        [, , $stateRepository, $actorRepository, $provisioner, $activation, $secretStore] = $this->services();
        $this->activate($provisioner, $activation, 5_000);
        $recovery = new ActivityPubIdentityRecoveryService(
            $stateRepository,
            $actorRepository,
            $secretStore,
            new ActorKeyVault($secretStore),
            new RsaCrypto(),
            new CanonicalJson(),
        );
        $healthy = $recovery->audit();
        self::assertTrue($healthy->isHealthy());
        self::assertSame(1, $healthy->actorCount);
        self::assertSame(1, $healthy->keyCount);

        $recoveryDocument = $recovery->exportRecoveryDocument();
        self::assertStringContainsString('register-activitypub-identity-recovery', $recoveryDocument);
        self::assertStringNotContainsString('PRIVATE KEY', $recoveryDocument);
        $secretStore->replaceExtensionPrivate(
            ActivityPubSecret::MASTER_KEY,
            sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
        );
        self::assertFalse($recovery->audit()->isHealthy());

        $restored = $recovery->restoreRecoveryDocument($recoveryDocument);
        self::assertTrue($restored->isHealthy());
        self::assertSame($healthy->identityFingerprint, $restored->identityFingerprint);

        $foreignDocument = json_decode($recoveryDocument, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($foreignDocument);
        $foreignDocument['identityFingerprint'] = str_repeat('0', 64);
        $this->expectException(\DomainException::class);
        $recovery->restoreRecoveryDocument(json_encode($foreignDocument, JSON_THROW_ON_ERROR));
    }

    public function testHandleAliasesAndVerifiedActorMovePreserveImmutableIdentity(): void
    {
        [$pdo, $dbLayer, $stateRepository, $actorRepository, $provisioner, $activation] = $this->services();
        $actor = $this->activate($provisioner, $activation, 6_000);
        $urlFactory = new FederationUrlGeneratorFactory($stateRepository);
        $federationRepository = new LocalFederationRepository($dbLayer);
        $deliveryRepository = new DeliveryRepository($dbLayer);
        $planner = new DeliveryPlanner(
            $deliveryRepository,
            new DeliveryQueue(new QueuePublisher($pdo, ''), $deliveryRepository),
        );
        $remoteActorRepository = new RemoteActorRepository($dbLayer);
        $actorDocumentBuilder = new ActorDocumentBuilder($stateRepository, $actorRepository, $urlFactory);
        $migration = new ActorIdentityMigrationService(
            $stateRepository,
            $actorRepository,
            $remoteActorRepository,
            new ModerationRuleRepository($dbLayer),
            $federationRepository,
            $urlFactory,
            new PublicIdGenerator(),
            $actorDocumentBuilder,
            new LocalActivityDocumentBuilder(),
            new CanonicalJson(),
            $planner,
            new PortableDatabaseTransaction($pdo),
        );

        $update = $migration->changeHandle($actor->id, 'gazette', 6_100);
        self::assertNotNull($update);
        self::assertSame('Update', $update->type);
        self::assertSame(['gazette', 'journal'], $actorRepository->handlesForActor($actor->id));
        self::assertSame('gazette', $actorRepository->findByHandle('journal')?->handle);
        self::assertNull($migration->changeHandle($actor->id, 'GAZETTE', 6_101));
        $updateDocument = json_decode($update->serializedBody, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($updateDocument);
        self::assertSame('gazette', $updateDocument['object']['preferredUsername'] ?? null);
        self::assertSame(
            ['acct:journal@journal.example'],
            $updateDocument['object']['alsoKnownAs'] ?? null,
        );

        $localActorUrl = $urlFactory->create()->actor($actor->publicId);
        $remotePair = (new RsaCrypto())->generateKeyPair();
        $targetDocument = '{"id":"https://new.example/users/journal"}';
        $target = $remoteActorRepository->save(new FetchedRemoteActor(
            'https://new.example/users/journal',
            'Person',
            'journal',
            'Journal Elsewhere',
            'https://new.example/users/journal/inbox',
            'https://new.example/inbox',
            'https://new.example/users/journal#main-key',
            $remotePair->publicKeyPem,
            [],
            $targetDocument,
            hash('sha256', $targetDocument),
            6_110,
            9_000,
        ));
        try {
            $migration->move($actor->id, $target->id, 6_120);
            self::fail('A Move without a reciprocal alias must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('alsoKnownAs', $exception->getMessage());
        }

        self::assertSame(LocalActorState::ACTIVE, $actorRepository->findById($actor->id)?->state);

        $targetDocument = '{"id":"https://new.example/users/journal","alsoKnownAs":["' . $localActorUrl . '"]}';
        $target = $remoteActorRepository->save(new FetchedRemoteActor(
            'https://new.example/users/journal',
            'Person',
            'journal',
            'Journal Elsewhere',
            'https://new.example/users/journal/inbox',
            'https://new.example/inbox',
            'https://new.example/users/journal#main-key',
            $remotePair->publicKeyPem,
            [$localActorUrl],
            $targetDocument,
            hash('sha256', $targetDocument),
            6_130,
            9_000,
        ));
        $move = $migration->move($actor->id, $target->id, 6_140);
        self::assertSame('Move', $move->type);
        $moved = $this->requireLocalActor($actorRepository, $actor->id);
        self::assertSame(LocalActorState::MOVED, $moved->state);
        self::assertSame($target->actorUrl, $moved->movedToUrl);
        self::assertSame(6_140, $moved->movedAt);
        self::assertSame($target->actorUrl, $actorDocumentBuilder->build($moved)['movedTo'] ?? null);
        $moveDocument = json_decode($move->serializedBody, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($moveDocument);
        self::assertSame($localActorUrl, $moveDocument['object'] ?? null);
        self::assertSame($target->actorUrl, $moveDocument['target'] ?? null);
    }

    public function testVersionOneDatabaseProfileMigratesIdempotently(): void
    {
        [, $dbLayer] = $this->services();
        foreach ([ActivityPubSchema::ACTOR_TABLE, ActivityPubSchema::REMOTE_ACTOR_TABLE] as $table) {
            $dbLayer->dropField($table, 'moved_to_url');
            $dbLayer->dropField($table, 'moved_at');
        }

        $dbLayer->dropField(ActivityPubSchema::REMOTE_ACTOR_TABLE, 'avatar_url');
        $dbLayer->dropField(ActivityPubSchema::REMOTE_ACTOR_TABLE, 'featured_url');
        $dbLayer->dropField(ActivityPubSchema::REMOTE_OBJECT_TABLE, 'featured_at');
        $dbLayer->dropTable(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE);
        $dbLayer->dropTable(ActivityPubSchema::REMOTE_MEDIA_TABLE);
        $dbLayer->dropTable(ActivityPubSchema::CONTENT_SETTING_TABLE);
        $dbLayer->dropTable(ActivityPubSchema::BACKFILL_ITEM_TABLE);
        $dbLayer->dropTable(ActivityPubSchema::BACKFILL_JOB_TABLE);
        $dbLayer->dropField(ActivityPubSchema::OBJECT_TABLE, 'broadcast_at');
        $dbLayer->dropForeignKey(ActivityPubSchema::INTERACTION_TABLE, 'fk_local_note');
        $dbLayer->dropIndex(ActivityPubSchema::INTERACTION_TABLE, 'public_note_reply_idx');
        $dbLayer->dropField(ActivityPubSchema::INTERACTION_TABLE, 'local_note_id');
        $dbLayer->dropField(ActivityPubSchema::STATE_TABLE, 'last_runner_at');
        $dbLayer->dropField(ActivityPubSchema::STATE_TABLE, 'last_runner_code');
        $dbLayer->dropField(ActivityPubSchema::STATE_TABLE, 'posts_enabled');
        $dbLayer->dropField(ActivityPubSchema::STATE_TABLE, 'default_visibility');
        $dbLayer->update(ActivityPubSchema::STATE_TABLE)
            ->set('profile_version', '1')
            ->where('id = :id')->setParameter('id', 'installation')
            ->execute()
        ;

        ActivityPubSchema::install($dbLayer);
        self::assertSame(
            ActivityPubSchema::PROFILE_VERSION,
            (int)$dbLayer->select('profile_version')->from(ActivityPubSchema::STATE_TABLE)->execute()->result(),
        );
        foreach ([ActivityPubSchema::ACTOR_TABLE, ActivityPubSchema::REMOTE_ACTOR_TABLE] as $table) {
            self::assertTrue($dbLayer->fieldExists($table, 'moved_to_url'));
            self::assertTrue($dbLayer->fieldExists($table, 'moved_at'));
        }

        self::assertTrue($dbLayer->tableExists(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE));
        self::assertTrue($dbLayer->fieldExists(ActivityPubSchema::REMOTE_ACTOR_TABLE, 'avatar_url'));
        self::assertTrue($dbLayer->fieldExists(ActivityPubSchema::REMOTE_ACTOR_TABLE, 'featured_url'));
        self::assertTrue($dbLayer->fieldExists(ActivityPubSchema::REMOTE_OBJECT_TABLE, 'featured_at'));
        self::assertTrue($dbLayer->tableExists(ActivityPubSchema::REMOTE_MEDIA_TABLE));
        self::assertTrue($dbLayer->tableExists(ActivityPubSchema::CONTENT_SETTING_TABLE));
        self::assertTrue($dbLayer->tableExists(ActivityPubSchema::BACKFILL_JOB_TABLE));
        self::assertTrue($dbLayer->tableExists(ActivityPubSchema::BACKFILL_ITEM_TABLE));
        self::assertTrue($dbLayer->fieldExists(ActivityPubSchema::OBJECT_TABLE, 'broadcast_at'));
        self::assertTrue($dbLayer->fieldExists(ActivityPubSchema::INTERACTION_TABLE, 'local_note_id'));
        self::assertTrue($dbLayer->fieldExists(ActivityPubSchema::STATE_TABLE, 'last_runner_at'));
        self::assertTrue($dbLayer->fieldExists(ActivityPubSchema::STATE_TABLE, 'last_runner_code'));
        self::assertTrue($dbLayer->fieldExists(ActivityPubSchema::STATE_TABLE, 'posts_enabled'));
        self::assertTrue($dbLayer->fieldExists(ActivityPubSchema::STATE_TABLE, 'default_visibility'));
        $migratedState = (new FederationStateRepository($dbLayer))->state();
        self::assertTrue($migratedState->postsEnabled);
        self::assertSame('public', $migratedState->defaultVisibility);

        ActivityPubSchema::install($dbLayer);
        self::assertSame(ActivityPubSchema::PROFILE_VERSION, (new FederationStateRepository($dbLayer))->state()->profileVersion);
    }

    /**
     * @return array{
     *     \PDO,
     *     DbLayerSqlite,
     *     FederationStateRepository,
     *     LocalActorRepository,
     *     SiteActorProvisioner,
     *     FederationActivationService,
     *     DynamicSecretStore
     * }
     */
    private function services(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE queue (
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
        $dbLayer = new DbLayerSqlite($pdo);
        ActivityPubSchema::install($dbLayer);

        $registry = new DynamicSecretParameterRegistry(['CORE_TEST_SECRET']);
        $registry->registerExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $secretStore = new DynamicSecretStore(
            $this->temporaryDirectory . '/config.secrets.php',
            $registry,
        );
        $secretStore->getOrCreateExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $stateRepository = new FederationStateRepository($dbLayer);
        $actorRepository = new LocalActorRepository($dbLayer);
        $transaction     = new PortableDatabaseTransaction($pdo);
        $provisioner     = new SiteActorProvisioner(
            $stateRepository,
            $actorRepository,
            new PublicIdGenerator(),
            new RsaCrypto(),
            new ActorKeyVault($secretStore),
            $transaction,
            new PortableHtmlSanitizer(new HttpClient()),
        );

        return [
            $pdo,
            $dbLayer,
            $stateRepository,
            $actorRepository,
            $provisioner,
            new FederationActivationService($dbLayer, $stateRepository, $actorRepository, $transaction),
            $secretStore,
        ];
    }

    private function activate(
        SiteActorProvisioner       $provisioner,
        FederationActivationService $activation,
        int                        $now,
    ): \Register\Extension\activitypub\Domain\LocalActor {
        $actor = $provisioner->provision(new SiteActorDraft(
            ActorType::SERVICE,
            new LocalHandle('journal'),
            'Journal',
            '<p>Federated journal.</p>',
            'https://journal.example/about',
        ), $now);

        return $activation->activate(new ActivationReadinessReport(
            $actor->publicId,
            new CanonicalOrigin('https://journal.example'),
            new CanonicalBasePath(''),
            $now + 10,
            array_map(
                static fn(ActivationReadinessCheck $check): ActivationCheckResult => new ActivationCheckResult(
                    $check,
                    true,
                    'Passed.',
                ),
                ActivationReadinessCheck::cases(),
            ),
        ), $now + 20);
    }

    private function requireLocalActor(LocalActorRepository $repository, int $id): LocalActor
    {
        $actor = $repository->findById($id);
        if (!$actor instanceof LocalActor) {
            throw new \LogicException('The local ActivityPub actor does not exist.');
        }

        return $actor;
    }

}
