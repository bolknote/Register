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
use Register\Author\AuthorProfileRepository;
use Register\Content\ContentDetailsRepository;
use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentSourceInterface;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlAliasRepository;
use Register\Url\ContentUrlAliasSchema;
use Register\Url\ContentUrlGenerator;
use Register\Url\PortableAsciiTransliterator;
use Register\Url\ReservedRouteRegistry;
use Register\Url\SlugGenerator;
use Register\Url\UniqueSlugGenerator;
use S2\Cms\Config\DynamicSecretParameterRegistry;
use S2\Cms\Config\DynamicSecretStore;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayerSqlite;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Queue\QueueExecutionBudget;
use s2_extensions\activitypub\Application\ActivationCheckResult;
use s2_extensions\activitypub\Application\ActivationReadinessCheck;
use s2_extensions\activitypub\Application\ActivationReadinessReport;
use s2_extensions\activitypub\Application\AuthorActorDraft;
use s2_extensions\activitypub\Application\AuthorActorService;
use s2_extensions\activitypub\Application\ContentProjectionService;
use s2_extensions\activitypub\Application\ContentActorResolver;
use s2_extensions\activitypub\Application\ContentProjectionStaging;
use s2_extensions\activitypub\Application\ContentFederationPreviewService;
use s2_extensions\activitypub\Application\ContentBackfillQueueHandler;
use s2_extensions\activitypub\Application\ContentBackfillStarter;
use s2_extensions\activitypub\Admin\ContentFederationSettingsFormParser;
use s2_extensions\activitypub\Admin\ContentSettingsEditor;
use s2_extensions\activitypub\Application\FederationActivationService;
use s2_extensions\activitypub\Application\SiteActorDraft;
use s2_extensions\activitypub\Application\SiteActorProvisioner;
use s2_extensions\activitypub\Content\PortableHtmlSanitizer;
use s2_extensions\activitypub\Content\ContentAttachmentExtractor;
use s2_extensions\activitypub\Domain\ActivityDeliveryIntent;
use s2_extensions\activitypub\Domain\ActorKind;
use s2_extensions\activitypub\Domain\ActorType;
use s2_extensions\activitypub\Domain\CanonicalBasePath;
use s2_extensions\activitypub\Domain\CanonicalOrigin;
use s2_extensions\activitypub\Domain\ContentProjectionAction;
use s2_extensions\activitypub\Domain\ContentProjectionMode;
use s2_extensions\activitypub\Domain\ContentDeliveryMode;
use s2_extensions\activitypub\Domain\ContentFederationSettings;
use s2_extensions\activitypub\Domain\ContentPublicationMode;
use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalHandle;
use s2_extensions\activitypub\Domain\ModerationAction;
use s2_extensions\activitypub\Domain\PublicIdGenerator;
use s2_extensions\activitypub\Domain\PostObjectType;
use s2_extensions\activitypub\Delivery\DeliveryPlanner;
use s2_extensions\activitypub\Delivery\DeliveryQueue;
use s2_extensions\activitypub\Delivery\MentionDeliveryPlanner;
use s2_extensions\activitypub\Delivery\MentionDeliveryQueue;
use s2_extensions\activitypub\Infrastructure\ActivityPubSchema;
use s2_extensions\activitypub\Infrastructure\DeliveryRepository;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\FetchedRemoteActor;
use s2_extensions\activitypub\Infrastructure\ContentFederationSettingsRepository;
use s2_extensions\activitypub\Infrastructure\ContentBackfillRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Infrastructure\ModerationRuleRepository;
use s2_extensions\activitypub\Infrastructure\PortableDatabaseTransaction;
use s2_extensions\activitypub\Infrastructure\RemoteActorRepository;
use s2_extensions\activitypub\Presentation\CanonicalJson;
use s2_extensions\activitypub\Presentation\ActorDocumentBuilder;
use s2_extensions\activitypub\Presentation\ContentObjectDocumentBuilder;
use s2_extensions\activitypub\Presentation\LocalActivityDocumentBuilder;
use s2_extensions\activitypub\Security\ActivityPubSecret;
use s2_extensions\activitypub\Security\ActorKeyVault;
use s2_extensions\activitypub\Security\RsaCrypto;
use Symfony\Component\Filesystem\Filesystem;

final class ContentProjectionTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_activitypub_projection_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testProjectsStableLifecycleAndNeverBroadcastsHistoricalBackfill(): void
    {
        $services = $this->services();
        $source   = $services['source'];
        $postId   = ContentId::post(7);
        $source->replace($this->post('First body', 1_000));

        self::assertSame(
            ContentProjectionAction::SKIPPED,
            $services['projection']->synchronize($postId, ContentProjectionMode::HISTORY_ONLY, 1_100)->action,
        );

        $actor = $this->activate($services);
        $created = $services['projection']->synchronize($postId, ContentProjectionMode::HISTORY_ONLY, 1_200);
        self::assertSame(ContentProjectionAction::CREATED, $created->action);
        self::assertNotNull($created->object);
        self::assertSame(1, $created->object->incarnation);
        self::assertSame($actor->id, $created->object->ownerActorId);
        self::assertNull($created->object->broadcastAt);
        self::assertSame(ActivityDeliveryIntent::NONE, $created->activities[0]->deliveryIntent);

        $objectDocument = $this->jsonObject($created->object->snapshotJson);
        self::assertSame('Article', $objectDocument['type']);
        self::assertSame('https://journal.example/register/hello', $objectDocument['url']);
        self::assertSame('https://journal.example/register/activitypub/actors/' . $actor->publicId, $objectDocument['attributedTo']);
        self::assertStringNotContainsString('<script', (string)$objectDocument['content']);
        self::assertStringContainsString('href="https://journal.example/inside"', (string)$objectDocument['content']);
        self::assertSame(['ru' => $objectDocument['content']], $objectDocument['contentMap']);
        self::assertSame('#ActivityPub', $objectDocument['tag'][0]['name']);

        $broadcast = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 1_300);
        self::assertSame(ContentProjectionAction::UPDATED, $broadcast->action);
        self::assertNotNull($broadcast->object);
        self::assertSame(1_300, $broadcast->object->broadcastAt);
        self::assertSame('Create', $broadcast->activities[0]->type);
        self::assertSame(ActivityDeliveryIntent::FOLLOWERS, $broadcast->activities[0]->deliveryIntent);
        self::assertSame(2, $services['repository']->outboxCount($actor->id));

        $source->replace($this->post('Edited body', 2_000));
        $updated = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 2_100);
        self::assertSame(ContentProjectionAction::UPDATED, $updated->action);
        self::assertNotNull($updated->object);
        self::assertSame($created->object->publicId, $updated->object->publicId);
        self::assertSame(ActivityDeliveryIntent::FOLLOWERS, $updated->activities[0]->deliveryIntent);
        self::assertSame('Update', $updated->activities[0]->type);

        $source->replace(null);
        $deleted = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 2_200);
        self::assertSame(ContentProjectionAction::TOMBSTONED, $deleted->action);
        self::assertNotNull($deleted->object);
        self::assertSame('tombstoned', $deleted->object->state);
        self::assertSame('Delete', $deleted->activities[0]->type);
        self::assertSame(ActivityDeliveryIntent::FOLLOWERS, $deleted->activities[0]->deliveryIntent);

        $source->replace($this->post('Republished body', 2_300));
        $republished = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 2_300);
        self::assertSame(ContentProjectionAction::CREATED, $republished->action);
        self::assertNotNull($republished->object);
        self::assertSame(2, $republished->object->incarnation);
        self::assertNotSame($created->object->publicId, $republished->object->publicId);
        self::assertSame(5, $services['repository']->outboxCount($actor->id));
    }

    public function testProjectionComposesWithAndRollsBackInsideEditorialTransaction(): void
    {
        $services = $this->services();
        $services['source']->replace($this->post('Transactional body', 3_000));
        $actor = $this->activate($services);

        $services['pdo']->beginTransaction();
        $result = $services['projection']->synchronize(
            ContentId::post(7),
            ContentProjectionMode::LIVE_CHANGE,
            3_100,
        );
        self::assertSame(ContentProjectionAction::CREATED, $result->action);
        self::assertSame(1, $services['repository']->outboxCount($actor->id));
        $services['pdo']->rollBack();

        self::assertSame(0, $services['repository']->outboxCount($actor->id));
        self::assertNull($services['repository']->findLiveObject(ContentId::post(7)));
    }

    public function testProjectionFreezesWhileFederationIdentityIsDecommissioning(): void
    {
        $services = $this->services();
        $postId   = ContentId::post(7);
        $services['source']->replace($this->post('Before decommission.', 3_000));
        $actor = $this->activate($services);
        $created = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 3_100);
        self::assertNotNull($created->object);
        self::assertTrue($services['stateRepository']->beginDecommission(3_200));

        $services['source']->replace($this->post('Must remain local only.', 3_300));
        $skipped = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 3_400);
        self::assertSame(ContentProjectionAction::SKIPPED, $skipped->action);
        self::assertSame(1, $services['repository']->outboxCount($actor->id));
        self::assertSame(
            $created->object->snapshotHash,
            $services['repository']->findLiveObject($postId)?->snapshotHash,
        );
    }

    public function testPerContentSettingsControlProjectionWithoutChangingAFrozenObjectType(): void
    {
        $services = $this->services();
        $postId = ContentId::post(7);
        $services['source']->replace($this->post('Long body for settings.', 3_000));
        $this->activate($services);

        $services['settingsRepository']->save(new ContentFederationSettings(
            $postId,
            ContentPublicationMode::DISABLED,
        ), 3_010);
        self::assertSame(
            ContentProjectionAction::UNCHANGED,
            $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 3_020)->action,
        );

        $services['settingsRepository']->save(new ContentFederationSettings(
            $postId,
            ContentPublicationMode::ENABLED,
            ContentDeliveryMode::EXCERPT,
            PostObjectType::NOTE,
            'unlisted',
            'Sensitive protocol details',
            'en-GB',
        ), 3_030);
        $created = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 3_040);
        self::assertSame(ContentProjectionAction::CREATED, $created->action);
        self::assertNotNull($created->object);
        self::assertSame('Note', $created->object->objectType);
        self::assertSame('unlisted', $created->object->visibility);
        $createdDocument = $this->jsonObject($created->object->snapshotJson);
        self::assertSame('Sensitive protocol details', $createdDocument['summary']);
        self::assertSame(
            ['en-gb' => 'Sensitive protocol details'],
            $createdDocument['summaryMap'],
        );
        self::assertSame(['en-gb' => $createdDocument['content']], $createdDocument['contentMap']);
        self::assertStringContainsString('Short version.', (string)$createdDocument['content']);
        self::assertStringNotContainsString('Long body for settings.', (string)$createdDocument['content']);

        $services['settingsRepository']->save(new ContentFederationSettings(
            $postId,
            ContentPublicationMode::ENABLED,
            ContentDeliveryMode::FULL,
            PostObjectType::ARTICLE,
            'public',
            '',
            'ru',
        ), 3_050);
        $services['source']->replace($this->post('Updated full body.', 3_060));
        $updated = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 3_070);
        self::assertSame(ContentProjectionAction::UPDATED, $updated->action);
        self::assertNotNull($updated->object);
        self::assertSame('Note', $updated->object->objectType);
        self::assertSame('public', $updated->object->visibility);
        $updatedDocument = $this->jsonObject($updated->object->snapshotJson);
        self::assertSame('Note', $updatedDocument['type']);
        self::assertArrayNotHasKey('summary', $updatedDocument);
        self::assertStringContainsString('Updated full body.', (string)$updatedDocument['content']);

        $services['settingsRepository']->save(new ContentFederationSettings(
            $postId,
            ContentPublicationMode::DISABLED,
        ), 3_080);
        $deleted = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 3_090);
        self::assertSame(ContentProjectionAction::TOMBSTONED, $deleted->action);
        self::assertSame('Delete', $deleted->activities[0]->type);
    }

    public function testEditorStagesOneProjectionAndPreservesSettingsCreationTime(): void
    {
        $services = $this->services();
        $postId = ContentId::post(7);
        $services['source']->replace($this->post('Editor body.', 4_000));
        $this->activate($services);
        $staging = new ContentProjectionStaging();
        $editor = new ContentSettingsEditor(
            $services['settingsRepository'],
            $services['repository'],
            $services['projection'],
            $staging,
            new ContentFederationSettingsFormParser(),
        );

        $data = [
            ContentSettingsEditor::PUBLICATION_FIELD => 'enabled',
            ContentSettingsEditor::DELIVERY_FIELD    => 'excerpt',
            ContentSettingsEditor::OBJECT_TYPE_FIELD => 'Note',
            ContentSettingsEditor::VISIBILITY_FIELD  => 'unlisted',
            ContentSettingsEditor::SUMMARY_FIELD     => 'Editor warning',
            ContentSettingsEditor::LANGUAGE_FIELD    => 'en-GB',
            'title'                                   => 'Untouched core value',
        ];
        $context = [];
        $editor->stageUpdate($postId, $data, $context);
        self::assertTrue($staging->isDeferred($postId));
        self::assertSame(['title' => 'Untouched core value'], $data);
        $editor->complete($postId, $context, 4_100);
        self::assertFalse($staging->isDeferred($postId));

        $object = $services['repository']->findLiveObject($postId);
        self::assertNotNull($object);
        self::assertSame('Note', $object->objectType);
        self::assertSame(1, $services['repository']->outboxCount($object->ownerActorId));
        $stored = $services['settingsRepository']->find($postId);
        self::assertSame(ContentPublicationMode::ENABLED, $stored->publicationMode);
        self::assertSame('Editor warning', $stored->summary);

        $secondData = [
            ContentSettingsEditor::PUBLICATION_FIELD => 'enabled',
            ContentSettingsEditor::DELIVERY_FIELD    => 'full',
            ContentSettingsEditor::OBJECT_TYPE_FIELD => 'Note',
            ContentSettingsEditor::VISIBILITY_FIELD  => 'public',
            ContentSettingsEditor::SUMMARY_FIELD     => '',
            ContentSettingsEditor::LANGUAGE_FIELD    => 'ru',
        ];
        $secondContext = [];
        $editor->stageUpdate($postId, $secondData, $secondContext);
        $editor->complete($postId, $secondContext, 4_200);

        $row = $services['dbLayer']->select('created_at', 'updated_at')
            ->from(ActivityPubSchema::CONTENT_SETTING_TABLE)
            ->where('local_type = :type')->setParameter('type', 'post')
            ->andWhere('local_id = :id')->setParameter('id', 7)
            ->execute()
            ->fetchAssoc()
        ;
        self::assertIsArray($row);
        self::assertSame(4_100, (int)$row['created_at']);
        self::assertSame(4_200, (int)$row['updated_at']);

        $invalidData = [
            ContentSettingsEditor::PUBLICATION_FIELD => 'enabled',
            ContentSettingsEditor::OBJECT_TYPE_FIELD => 'Article',
        ];
        $invalidContext = [];
        $this->expectException(\DomainException::class);
        $editor->stageUpdate($postId, $invalidData, $invalidContext);
    }

    public function testExactEditorPreviewMatchesTheSubsequentStoredUpdateSnapshot(): void
    {
        $services = $this->services();
        $postId = ContentId::post(7);
        $services['source']->replace($this->post('Initial body.', 5_000, 1));
        $actor = $this->activate($services);
        $created = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 5_100);
        self::assertSame(ContentProjectionAction::CREATED, $created->action);
        self::assertSame(1, $services['repository']->outboxCount($actor->id));

        $body = '<script>bad()</script><p>Previewed update. <a href="/inside" onclick="bad()">inside</a></p>';
        $draft = new \s2_extensions\activitypub\Domain\ContentFederationSettingsDraft(
            ContentType::POST,
            ContentPublicationMode::ENABLED,
            ContentDeliveryMode::FULL,
            PostObjectType::ARTICLE,
            'unlisted',
            'Preview warning',
            'en-GB',
        );
        $preview = $services['preview']->preview(
            ContentType::POST,
            7,
            [
                'title'            => 'Hello federation',
                'body'             => $body,
                'excerpt'          => '<p>Short version.</p>',
                'meta_description' => '',
                'published'        => true,
                'published_at'     => (new \DateTimeImmutable())->setTimestamp(1_000),
                'updated_at'       => (new \DateTimeImmutable())->setTimestamp(5_200),
                'slug'             => 'hello',
                'author_id'        => '1',
                'tags'             => 'Activity Pub',
            ],
            $draft,
            1,
            false,
            5_200,
        );
        self::assertSame(ContentProjectionAction::UPDATED, $preview->action);
        self::assertSame([], $preview->provisionalFields);
        self::assertSame(1, $services['repository']->outboxCount($actor->id));
        self::assertStringNotContainsString('<script', $preview->canonicalJson);
        self::assertStringContainsString('Preview warning', $preview->canonicalJson);

        $services['settingsRepository']->save($draft->bind($postId), 5_200);
        $services['source']->replace(new ContentItem(
            $postId,
            'Hello federation',
            $body,
            '/hello',
            1_000,
            updatedAt: 5_200,
            excerpt: '<p>Short version.</p>',
            authorId: 1,
        ));
        $updated = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 5_200);
        self::assertSame(ContentProjectionAction::UPDATED, $updated->action);
        self::assertNotNull($updated->object);
        self::assertSame($preview->canonicalJson, $updated->object->snapshotJson);
        self::assertSame(2, $services['repository']->outboxCount($actor->id));
    }

    public function testHistoricalBackfillIsDurableBoundedAndNeverCreatesDeliveries(): void
    {
        $services = $this->services();
        $postId = ContentId::post(7);
        $services['source']->replace($this->post('Historical body.', 6_000, 1));
        $actor = $this->activate($services);
        $repository = new ContentBackfillRepository($services['dbLayer']);
        $publisher = new QueuePublisher($services['pdo'], '');
        $transaction = new PortableDatabaseTransaction($services['pdo']);
        $starter = new ContentBackfillStarter(
            new ContentRepository($services['source']),
            $services['stateRepository'],
            $repository,
            new PublicIdGenerator(),
            $publisher,
            $transaction,
        );
        $job = $starter->selected([$postId], 1, 6_100);
        self::assertSame(1, $job->totalCount);
        self::assertSame('pending', $job->state->value);
        self::assertSame(1, (int)$services['dbLayer']->select('COUNT(*)')
            ->from('queue')
            ->where('code = :code')->setParameter('code', ContentBackfillQueueHandler::CODE)
            ->execute()
            ->result());

        $handler = new ContentBackfillQueueHandler(
            $repository,
            $services['projection'],
            $transaction,
            $publisher,
            new NullLogger(),
            static fn(): int => 6_200,
        );
        $handler->handle(
            $job->id,
            ContentBackfillQueueHandler::CODE,
            ['job_id' => $job->id],
            new QueueExecutionBudget(1.0),
        );

        $completed = $repository->find($job->id);
        self::assertNotNull($completed);
        self::assertSame('completed', $completed->state->value);
        self::assertSame(1, $completed->processedCount);
        self::assertSame(1, $completed->projectedCount);
        self::assertSame(0, $completed->failedCount);
        $object = $services['repository']->findLiveObject($postId);
        self::assertNotNull($object);
        self::assertSame(0, (int)$services['dbLayer']->select('COUNT(*)')
            ->from(ActivityPubSchema::DELIVERY_TABLE)
            ->execute()
            ->result());
        $activity = $services['repository']->outboxPage($actor->id, null, 1)[0];
        self::assertSame(ActivityDeliveryIntent::NONE, $activity->deliveryIntent);
    }

    public function testFeaturedCollectionEmitsOwnedAddAndRemoveWithoutSyntheticObjectUpdates(): void
    {
        $services = $this->services();
        $postId = ContentId::post(7);
        $services['source']->replace($this->post('Pinned body.', 7_000, featured: true));
        $actor = $this->activate($services);

        $created = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 7_100);
        self::assertNotNull($created->object);
        self::assertSame(7_100, $created->object->featuredAt);
        self::assertSame(['Create', 'Add'], array_map(
            static fn(\s2_extensions\activitypub\Infrastructure\StoredActivityRepresentation $activity): string => $activity->type,
            $created->activities,
        ));
        self::assertSame(1, $services['repository']->featuredCount($actor->id));
        $add = $this->jsonObject($created->activities[1]->serializedBody);
        $urls = (new FederationUrlGeneratorFactory($services['stateRepository']))->create();
        self::assertSame($urls->object($created->object->publicId), $add['object']);
        self::assertSame($urls->actorFeatured($actor->publicId), $add['target']);

        $services['source']->replace($this->post('Pinned body.', 7_000));
        $removed = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 7_200);
        self::assertNotNull($removed->object);
        self::assertNull($removed->object->featuredAt);
        self::assertSame($created->object->snapshotHash, $removed->object->snapshotHash);
        self::assertCount(1, $removed->activities);
        self::assertSame('Remove', $removed->activities[0]->type);
        self::assertSame(ActivityDeliveryIntent::FOLLOWERS, $removed->activities[0]->deliveryIntent);
        self::assertSame(0, $services['repository']->featuredCount($actor->id));

        $services['source']->replace($this->post('Pinned body.', 7_000, featured: true));
        $addedAgain = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 7_300);
        self::assertSame('Add', $addedAgain->activities[0]->type);
        self::assertSame(1, $services['repository']->featuredCount($actor->id));
        self::assertSame(4, $services['repository']->outboxCount($actor->id));
    }

    public function testProjectionPublishesLocalAttachmentMetadataAudienceAndMentionsWithoutHotlinking(): void
    {
        $services = $this->services();
        $postId = ContentId::post(7);
        $mentionUrl = 'https://social.example/users/bob';
        $services['source']->replace(new ContentItem(
            $postId,
            'Post with media',
            '<p>Hello <a class="mention" href="' . $mentionUrl . '">@bob@social.example</a>.</p>'
                . '<img src="/register/_pictures/pixel.png" alt="Tiny pixel">'
                . '<img src="https://tracker.example/pixel.gif" alt="Must stay remote">',
            '/hello',
            1_000,
            updatedAt: 8_000,
        ));
        $actor = $this->activate($services);

        $created = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 8_100);
        self::assertNotNull($created->object);
        $document = $this->jsonObject($created->object->snapshotJson);
        self::assertStringNotContainsString('<img', (string)$document['content']);
        self::assertSame($mentionUrl, $document['tag'][1]['href']);
        self::assertSame('Mention', $document['tag'][1]['type']);
        self::assertContains($mentionUrl, $document['to']);
        $urls = (new FederationUrlGeneratorFactory($services['stateRepository']))->create();
        self::assertSame([$urls->actorFollowers($actor->publicId)], $document['audience']);
        self::assertCount(1, $document['attachment']);
        self::assertSame([
            'height'    => 1,
            'mediaType' => 'image/png',
            'name'      => 'Tiny pixel',
            'size'      => 68,
            'type'      => 'Document',
            'url'       => 'https://journal.example/register/_pictures/pixel.png',
            'width'     => 1,
        ], $document['attachment'][0]);
        self::assertSame(1, (int)$services['dbLayer']->select('COUNT(*)')
            ->from('queue')
            ->where('code = :code')->setParameter('code', MentionDeliveryQueue::CODE)
            ->execute()
            ->result());
    }

    public function testMentionDeliveryUsesCachedSharedInboxAndPreservesLifecycleRecipients(): void
    {
        $services = $this->services();
        $postId = ContentId::post(7);
        $mentionUrl = 'https://social.example/users/bob';
        $sharedInbox = 'https://social.example/inbox';
        $remoteActorRepository = $services['remoteActorRepository'];
        $remoteActor = $remoteActorRepository->save(new FetchedRemoteActor(
            $mentionUrl,
            'Person',
            'bob',
            'Bob',
            $mentionUrl . '/inbox',
            $sharedInbox,
            $mentionUrl . '#main-key',
            "-----BEGIN PUBLIC KEY-----\ntest",
            [],
            '{}',
            hash('sha256', '{}'),
            1_000,
            10_000,
        ));
        $this->activate($services);

        $services['source']->replace($this->mentionedPost($mentionUrl, 'Hello Bob.', 8_100));
        $created = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 8_200);
        self::assertCount(1, $created->activities);
        $createDocument = $this->jsonObject($created->activities[0]->serializedBody);
        self::assertContains($mentionUrl, $createDocument['to']);
        self::assertSame([
            [$created->activities[0]->id, $sharedInbox, [$mentionUrl]],
        ], $this->mentionDeliveryRows($services['pdo']));

        $services['source']->replace($this->post('Mention removed.', 8_300));
        $removed = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 8_400);
        self::assertCount(1, $removed->activities);
        $removedDocument = $this->jsonObject($removed->activities[0]->serializedBody);
        self::assertContains($mentionUrl, $removedDocument['to']);
        self::assertCount(2, $this->mentionDeliveryRows($services['pdo']));

        $services['source']->replace($this->post('Still no mention.', 8_500));
        $withoutMention = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 8_600);
        self::assertCount(1, $withoutMention->activities);
        self::assertNotContains(
            $withoutMention->activities[0]->id,
            array_column($this->mentionDeliveryRows($services['pdo']), 0),
        );

        $services['source']->replace($this->mentionedPost($mentionUrl, 'Bob is back.', 8_700));
        $restored = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 8_800);
        self::assertCount(1, $restored->activities);
        self::assertCount(3, $this->mentionDeliveryRows($services['pdo']));

        $services['source']->replace(null);
        $deleted = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 8_900);
        self::assertCount(1, $deleted->activities);
        self::assertContains($mentionUrl, $this->jsonObject($deleted->activities[0]->serializedBody)['to']);
        self::assertCount(4, $this->mentionDeliveryRows($services['pdo']));

        $moderationRepository = $services['moderationRepository'];
        $moderationRepository->store('actor', $remoteActor->actorUrl, ModerationAction::BLOCK, 100, [], 9_000);
        $services['source']->replace($this->mentionedPost($mentionUrl, 'Blocked recipient.', 9_100));
        $blocked = $services['projection']->synchronize($postId, ContentProjectionMode::LIVE_CHANGE, 9_200);
        self::assertCount(1, $blocked->activities);
        self::assertCount(4, $this->mentionDeliveryRows($services['pdo']));
    }

    public function testAuthorActorProfileUpdatesWithoutIdentityOrKeyChurn(): void
    {
        $services = $this->services();
        $this->activate($services);
        $authorService = $services['authorService'];

        $actor = $authorService->save(new AuthorActorDraft(
            1,
            new LocalHandle('alice'),
            'Alice',
            '<p>Writes about protocols.</p>',
            'https://journal.example/register/about-alice',
        ), 1_100);
        self::assertSame(ActorKind::AUTHOR, $actor->kind);
        self::assertSame(ActorType::PERSON, $actor->type);
        self::assertSame(1, $actor->authorId);
        $key = $services['actorRepository']->currentKey($actor->id);
        self::assertNotNull($key);

        $updated = $authorService->save(new AuthorActorDraft(
            1,
            new LocalHandle('alice-writes'),
            'Alice Example',
            '<p>Writes about open protocols.</p>',
            'https://journal.example/register/about-alice',
        ), 1_200);
        self::assertSame($actor->id, $updated->id);
        self::assertSame($actor->publicId, $updated->publicId);
        self::assertSame($key->publicId, $services['actorRepository']->currentKey($updated->id)?->publicId);
        self::assertSame(['alice-writes', 'alice'], $services['actorRepository']->handlesForActor($updated->id));
        self::assertSame(1, $services['repository']->outboxCount($updated->id));
        $profileUpdate = $services['repository']->outboxPage($updated->id, null, 1)[0];
        self::assertSame('Update', $profileUpdate->type);

        $unchanged = $authorService->save(new AuthorActorDraft(
            1,
            new LocalHandle('alice-writes'),
            'Alice Example',
            '<p>Writes about open protocols.</p>',
            'https://journal.example/register/about-alice',
        ), 1_300);
        self::assertSame($updated->updatedAt, $unchanged->updatedAt);
        self::assertSame(1, $services['repository']->outboxCount($updated->id));
    }

    public function testAuthorPostIsCollectivelyAnnouncedAndUpdatesReachBothFollowerSets(): void
    {
        $services = $this->services();
        $siteActor = $this->activate($services);
        $authorService = $services['authorService'];
        $authorActor = $authorService->save(new AuthorActorDraft(
            1,
            new LocalHandle('alice'),
            'Alice',
            '<p>Protocol notes.</p>',
            'https://journal.example/register/about-alice',
        ), 1_100);

        $services['source']->replace($this->post('By Alice', 1_200, 1));
        $created = $services['projection']->synchronize(ContentId::post(7), ContentProjectionMode::LIVE_CHANGE, 1_300);
        self::assertNotNull($created->object);
        self::assertSame($authorActor->id, $created->object->ownerActorId);
        self::assertCount(2, $created->activities);
        self::assertSame(['Create', 'Announce'], array_map(
            static fn(\s2_extensions\activitypub\Infrastructure\StoredActivityRepresentation $activity): string => $activity->type,
            $created->activities,
        ));
        self::assertSame($siteActor->id, $created->activities[1]->actorId);

        $urls = (new FederationUrlGeneratorFactory($services['stateRepository']))->create();
        $objectDocument = $this->jsonObject($created->object->snapshotJson);
        self::assertSame($urls->actor($authorActor->publicId), $objectDocument['attributedTo']);
        self::assertSame([
            $urls->actorFollowers($authorActor->publicId),
            $urls->actorFollowers($siteActor->publicId),
        ], $objectDocument['cc']);
        $announce = $this->jsonObject($created->activities[1]->serializedBody);
        self::assertSame($urls->actor($siteActor->publicId), $announce['actor']);
        self::assertSame($urls->object($created->object->publicId), $announce['object']);

        $services['source']->replace($this->post('Alice edited this', 1_400, 1));
        $updated = $services['projection']->synchronize(ContentId::post(7), ContentProjectionMode::LIVE_CHANGE, 1_500);
        self::assertCount(1, $updated->activities);
        $update = $this->jsonObject($updated->activities[0]->serializedBody);
        self::assertSame([
            $urls->actorFollowers($authorActor->publicId),
            $urls->actorFollowers($siteActor->publicId),
        ], $update['cc']);
        self::assertSame(2, $services['repository']->outboxCount($authorActor->id));
        self::assertSame(1, $services['repository']->outboxCount($siteActor->id));
    }

    /**
     * @return array{
     *     pdo: \PDO,
     *     dbLayer: DbLayerSqlite,
     *     source: MutableActivityPubContentSource,
     *     projection: ContentProjectionService,
     *     repository: LocalFederationRepository,
     *     stateRepository: FederationStateRepository,
     *     settingsRepository: ContentFederationSettingsRepository,
     *     actorRepository: LocalActorRepository,
     *     remoteActorRepository: RemoteActorRepository,
     *     moderationRepository: ModerationRuleRepository,
     *     provisioner: SiteActorProvisioner,
     *     activation: FederationActivationService,
     *     authorService: AuthorActorService,
     *     preview: ContentFederationPreviewService
     * }
     */
    private function services(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, create_articles INTEGER NOT NULL, edit_site INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO users (id, name, create_articles, edit_site) VALUES (1, 'Alice', 1, 0)");
        $pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT NOT NULL, url TEXT NOT NULL, description TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE content_tag (id INTEGER PRIMARY KEY, content_type TEXT NOT NULL, content_id INTEGER NOT NULL, tag_id INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO tags (id, name, url, description) VALUES (1, 'Activity Pub', 'activity-pub', '')");
        $pdo->exec("INSERT INTO content_tag (id, content_type, content_id, tag_id) VALUES (1, 'post', 7, 1)");
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
        ContentSchema::create($dbLayer);
        ContentUrlAliasSchema::create($dbLayer);
        $dbLayer->insert(ContentSchema::TABLE_NAME)
            ->values([
                'id'               => '7',
                'content_type'     => "'post'",
                'parent_id'        => 'NULL',
                'slug_scope'       => "'root'",
                'slug'             => "'hello'",
                'title'            => "'Hello federation'",
                'excerpt'          => "'<p>Short version.</p>'",
                'body'             => "'<p>Initial body.</p>'",
                'meta_keywords'    => "''",
                'meta_description' => "''",
                'created_at'       => '1000',
                'published_at'     => '1000',
                'scheduled_at'     => '0',
                'updated_at'       => '5000',
                'revision'         => '1',
                'sort_order'       => '0',
                'published'        => '1',
                'featured'         => '0',
                'comments_enabled' => '1',
                'date_label'       => "''",
                'series'           => "''",
                'template'         => "'blog.php'",
                'author_id'        => '1',
            ])
            ->execute()
        ;
        ActivityPubSchema::install($dbLayer);

        $registry = new DynamicSecretParameterRegistry(['CORE_TEST_SECRET']);
        $registry->registerExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $secrets = new DynamicSecretStore($this->temporaryDirectory . '/config.secrets.php', $registry);
        $secrets->getOrCreateExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $source               = new MutableActivityPubContentSource();
        $stateRepository      = new FederationStateRepository($dbLayer);
        $settingsRepository   = new ContentFederationSettingsRepository($dbLayer);
        $actorRepository      = new LocalActorRepository($dbLayer);
        $federationRepository = new LocalFederationRepository($dbLayer);
        $deliveryRepository   = new DeliveryRepository($dbLayer);
        $queuePublisher       = new QueuePublisher($pdo, '');
        $deliveryQueue        = new DeliveryQueue($queuePublisher, $deliveryRepository);
        $transaction          = new PortableDatabaseTransaction($pdo);
        $urlFactory           = new FederationUrlGeneratorFactory($stateRepository);
        $htmlSanitizer        = new PortableHtmlSanitizer(new HttpClient());
        $publicIdGenerator    = new PublicIdGenerator();
        $rsaCrypto            = new RsaCrypto();
        $keyVault             = new ActorKeyVault($secrets);
        $canonicalJson        = new CanonicalJson();
        $activityBuilder      = new LocalActivityDocumentBuilder();
        $deliveryPlanner      = new DeliveryPlanner($deliveryRepository, $deliveryQueue);
        $remoteActorRepository = new RemoteActorRepository($dbLayer);
        $moderationRepository = new ModerationRuleRepository($dbLayer);
        $mentionDeliveryPlanner = new MentionDeliveryPlanner(
            $remoteActorRepository,
            $moderationRepository,
            $deliveryPlanner,
            new MentionDeliveryQueue($queuePublisher),
        );
        $provisioner          = new SiteActorProvisioner(
            $stateRepository,
            $actorRepository,
            $publicIdGenerator,
            $rsaCrypto,
            $keyVault,
            $transaction,
            $htmlSanitizer,
        );
        $detailsRepository = new ContentDetailsRepository(
            new ContentRepository($source),
            new AuthorProfileRepository($dbLayer),
            new TagRepository($dbLayer),
        );
        $actorDocumentBuilder = new ActorDocumentBuilder($stateRepository, $actorRepository, $urlFactory);
        $contentUrlGenerator = new ContentUrlGenerator(
            $dbLayer,
            new UrlBuilder('/register', 'https://journal.example/register', ''),
        );
        $contentSlugService = new ContentSlugService(
            $dbLayer,
            new UniqueSlugGenerator(new SlugGenerator(new PortableAsciiTransliterator())),
            new ReservedRouteRegistry('tags', 'favorite'),
            new ContentUrlAliasRepository($dbLayer),
        );
        $pictureDirectory = $this->temporaryDirectory . '/pictures';
        mkdir($pictureDirectory, 0700, true);
        $pixel = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        if (!\is_string($pixel) || file_put_contents($pictureDirectory . '/pixel.png', $pixel) !== 68) {
            throw new \RuntimeException('Unable to create the ActivityPub attachment fixture.');
        }

        $objectBuilder = new ContentObjectDocumentBuilder(
            $htmlSanitizer,
            'Russian',
            new ContentAttachmentExtractor(new HttpClient(), $pictureDirectory, '/register/_pictures'),
        );
        $authorService = new AuthorActorService(
            new AuthorProfileRepository($dbLayer),
            $stateRepository,
            $actorRepository,
            $federationRepository,
            $urlFactory,
            $publicIdGenerator,
            $rsaCrypto,
            $keyVault,
            $htmlSanitizer,
            $actorDocumentBuilder,
            $activityBuilder,
            $canonicalJson,
            $deliveryPlanner,
            $transaction,
        );

        return [
            'pdo'         => $pdo,
            'dbLayer'     => $dbLayer,
            'source'      => $source,
            'projection'  => new ContentProjectionService(
                $detailsRepository,
                $stateRepository,
                $settingsRepository,
                $actorRepository,
                new ContentActorResolver($actorRepository),
                $federationRepository,
                $urlFactory,
                $publicIdGenerator,
                $transaction,
                $objectBuilder,
                $activityBuilder,
                $canonicalJson,
                $deliveryPlanner,
                $mentionDeliveryPlanner,
            ),
            'repository'  => $federationRepository,
            'stateRepository' => $stateRepository,
            'settingsRepository' => $settingsRepository,
            'actorRepository' => $actorRepository,
            'remoteActorRepository' => $remoteActorRepository,
            'moderationRepository' => $moderationRepository,
            'provisioner' => $provisioner,
            'activation'  => new FederationActivationService($dbLayer, $stateRepository, $actorRepository, $transaction),
            'authorService' => $authorService,
            'preview'     => new ContentFederationPreviewService(
                $dbLayer,
                $contentUrlGenerator,
                $contentSlugService,
                new AuthorProfileRepository($dbLayer),
                $stateRepository,
                $federationRepository,
                $urlFactory,
                new ContentActorResolver($actorRepository),
                $objectBuilder,
                $canonicalJson,
            ),
        ];
    }

    /** @param array<string, object> $services */
    private function activate(array $services): LocalActor
    {
        $provisioner = $services['provisioner'];
        if (!$provisioner instanceof SiteActorProvisioner) {
            throw new \LogicException('The ActivityPub test provisioner is missing.');
        }

        $actor = $provisioner->provision(new SiteActorDraft(
            ActorType::SERVICE,
            new LocalHandle('journal'),
            'Journal',
            '<p>A journal.</p>',
            'https://journal.example/register/about',
        ), 900);

        $activation = $services['activation'];
        if (!$activation instanceof FederationActivationService) {
            throw new \LogicException('The ActivityPub test activation service is missing.');
        }

        $activation->activate(new ActivationReadinessReport(
            $actor->publicId,
            new CanonicalOrigin('https://journal.example'),
            new CanonicalBasePath('/register'),
            950,
            array_map(
                static fn(ActivationReadinessCheck $check): ActivationCheckResult => new ActivationCheckResult($check, true, 'Passed.'),
                ActivationReadinessCheck::cases(),
            ),
        ), 975);

        return $actor;
    }

    private function post(
        string $text,
        int    $updatedAt,
        ?int   $authorId = null,
        bool   $featured = false,
    ): ContentItem
    {
        return new ContentItem(
            ContentId::post(7),
            'Hello federation',
            '<script>bad()</script><p>' . $text . ' <a href="/inside" onclick="bad()">inside</a></p>',
            '/hello',
            1_000,
            updatedAt: $updatedAt,
            excerpt: '<p>Short version.</p>',
            authorId: $authorId,
            featured: $featured,
        );
    }

    private function mentionedPost(string $actorUrl, string $text, int $updatedAt): ContentItem
    {
        return new ContentItem(
            ContentId::post(7),
            'Hello federation',
            '<p><a class="mention" href="' . $actorUrl . '">@bob@social.example</a> ' . $text . '</p>',
            '/hello',
            1_000,
            updatedAt: $updatedAt,
        );
    }

    /** @return list<array{int, string, list<string>}> */
    private function mentionDeliveryRows(object $pdo): array
    {
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('The ActivityPub projection PDO fixture is missing.');
        }

        $statement = $pdo->query(
            'SELECT activity_id, inbox_url, recipient_json FROM ' . ActivityPubSchema::DELIVERY_TABLE . ' ORDER BY activity_id',
        );
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to query ActivityPub Mention delivery fixtures.');
        }

        $result = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $recipients = json_decode((string)$row['recipient_json'], true, 8, JSON_THROW_ON_ERROR);
            if (!\is_array($recipients) || !array_is_list($recipients)) {
                throw new \RuntimeException('An ActivityPub Mention delivery fixture is invalid.');
            }

            $result[] = [(int)$row['activity_id'], (string)$row['inbox_url'], $recipients];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($value) || array_is_list($value)) {
            throw new \RuntimeException('Expected a JSON object in ActivityPub projection test.');
        }

        return $value;
    }
}

final class MutableActivityPubContentSource implements ContentSourceInterface
{
    private ?ContentItem $content = null;

    public function replace(?ContentItem $content): void
    {
        $this->content = $content;
    }

    #[\Override]
    public function type(): ContentType
    {
        return ContentType::POST;
    }

    #[\Override]
    public function find(ContentId $id): ?ContentItem
    {
        return $this->content instanceof ContentItem && $this->content->id->equals($id)
            ? $this->content
            : null;
    }

    /** @return iterable<ContentItem> */
    #[\Override]
    public function published(): iterable
    {
        if ($this->content instanceof ContentItem) {
            yield $this->content;
        }
    }
}
