<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub;

use Psr\Log\LoggerInterface;
use Register\Backup\BackupContributorInterface;
use Register\Author\AuthorProfileRepository;
use Register\Comment\CommentImportService;
use Register\Comment\CommentPresentationEnricherInterface;
use Register\Comment\CommentChangeKind;
use Register\Comment\CommentChangedEvent;
use Register\Content\ContentChangedEvent;
use Register\Content\ContentDetailsRepository;
use Register\Content\ContentRepository;
use Register\Content\ContentRenderedEvent;
use Register\Module\Reactions\ReactionAggregateRepository;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlGenerator;
use S2\Cms\Config\DynamicSecretParameterRegistry;
use S2\Cms\Config\DynamicSecretStore;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ExtensionInterface;
use S2\Cms\Framework\StatefulServiceInterface;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\HttpClient\Remote\SafeRemoteHttpClient;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Queue\ScheduledMaintenanceTaskInterface;
use s2_extensions\activitypub\Application\FederationActivationService;
use s2_extensions\activitypub\Application\AuthorActorService;
use s2_extensions\activitypub\Application\ActivationProbeService;
use s2_extensions\activitypub\Application\ActivationReadinessQueueHandler;
use s2_extensions\activitypub\Application\ActivationReadinessStarter;
use s2_extensions\activitypub\Application\BundledReleaseInteroperabilityGate;
use s2_extensions\activitypub\Application\ReleaseInteroperabilityGateInterface;
use s2_extensions\activitypub\Application\FederationLifecycleService;
use s2_extensions\activitypub\Application\ActorKeyRotationService;
use s2_extensions\activitypub\Application\ActorIdentityMigrationService;
use s2_extensions\activitypub\Application\ActivityPubIdentityRecoveryService;
use s2_extensions\activitypub\Application\ActivityPubMaintenanceQueueHandler;
use s2_extensions\activitypub\Application\ActivityPubMaintenanceTask;
use s2_extensions\activitypub\Application\ContentProjectionService;
use s2_extensions\activitypub\Application\ContentProjectionStaging;
use s2_extensions\activitypub\Application\ContentActorResolver;
use s2_extensions\activitypub\Application\ContentFederationPreviewService;
use s2_extensions\activitypub\Application\ContentBackfillQueueHandler;
use s2_extensions\activitypub\Application\ContentBackfillStarter;
use s2_extensions\activitypub\Application\InboxActivityProcessor;
use s2_extensions\activitypub\Application\InboxInteractionProcessor;
use s2_extensions\activitypub\Application\InboxRateLimiter;
use s2_extensions\activitypub\Application\InboxRequestValidator;
use s2_extensions\activitypub\Application\OutgoingFollowService;
use s2_extensions\activitypub\Application\OutgoingInteractionService;
use s2_extensions\activitypub\Application\OutgoingReplyService;
use s2_extensions\activitypub\Application\PublicFederationAccess;
use s2_extensions\activitypub\Application\RemoteAvatarScheduler;
use s2_extensions\activitypub\Application\RemoteAvatarMaintenanceService;
use s2_extensions\activitypub\Application\SiteActorProvisioner;
use s2_extensions\activitypub\Content\PortableHtmlSanitizer;
use s2_extensions\activitypub\Content\ContentAttachmentExtractor;
use s2_extensions\activitypub\Controller\ActivityController;
use s2_extensions\activitypub\Controller\ActorCollectionController;
use s2_extensions\activitypub\Controller\ActorController;
use s2_extensions\activitypub\Controller\ActorKeyController;
use s2_extensions\activitypub\Controller\InboxController;
use s2_extensions\activitypub\Controller\NodeInfoController;
use s2_extensions\activitypub\Controller\NodeInfoDiscoveryController;
use s2_extensions\activitypub\Controller\ObjectController;
use s2_extensions\activitypub\Controller\ObjectRepliesController;
use s2_extensions\activitypub\Controller\RemoteAvatarController;
use s2_extensions\activitypub\Controller\WebFingerController;
use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;
use s2_extensions\activitypub\Domain\PublicIdGenerator;
use s2_extensions\activitypub\Delivery\ActivityDeliveryClient;
use s2_extensions\activitypub\Delivery\DeliveryPlanner;
use s2_extensions\activitypub\Delivery\DeliveryQueue;
use s2_extensions\activitypub\Delivery\DeliveryQueueHandler;
use s2_extensions\activitypub\Delivery\MentionDeliveryPlanner;
use s2_extensions\activitypub\Delivery\MentionDeliveryQueue;
use s2_extensions\activitypub\Delivery\MentionDeliveryQueueHandler;
use s2_extensions\activitypub\Delivery\OriginDeliveryThrottle;
use s2_extensions\activitypub\Discovery\RemoteActorDiscovery;
use s2_extensions\activitypub\Discovery\WebFingerClient;
use s2_extensions\activitypub\Infrastructure\DeliveryRepository;
use s2_extensions\activitypub\Infrastructure\ActivationReadinessRepository;
use s2_extensions\activitypub\Infrastructure\ActivityPubBackupContributor;
use s2_extensions\activitypub\Infrastructure\ActivityPubHousekeepingRepository;
use s2_extensions\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use s2_extensions\activitypub\Infrastructure\ContentFederationSettingsRepository;
use s2_extensions\activitypub\Infrastructure\ContentBackfillRepository;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\FollowRepository;
use s2_extensions\activitypub\Infrastructure\InboxRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Infrastructure\LocalInteractionRepository;
use s2_extensions\activitypub\Infrastructure\InteractionRepository;
use s2_extensions\activitypub\Infrastructure\ModerationRuleRepository;
use s2_extensions\activitypub\Infrastructure\NotificationRepository;
use s2_extensions\activitypub\Infrastructure\PortableDatabaseTransaction;
use s2_extensions\activitypub\Infrastructure\RemoteActorRepository;
use s2_extensions\activitypub\Infrastructure\RemoteAvatarRepository;
use s2_extensions\activitypub\Infrastructure\RemoteObjectRepository;
use s2_extensions\activitypub\Infrastructure\ReaderRepository;
use s2_extensions\activitypub\Inbox\InboxQueue;
use s2_extensions\activitypub\Inbox\InboxQueueHandler;
use s2_extensions\activitypub\Inbox\IncomingSignatureVerifier;
use s2_extensions\activitypub\Inbox\RemoteActorDocumentValidator;
use s2_extensions\activitypub\Inbox\RemoteActorFetchClient;
use s2_extensions\activitypub\Inbox\RemoteObjectDocumentValidator;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Presentation\ActorDocumentBuilder;
use s2_extensions\activitypub\Presentation\ActivityPubCommentPresentationEnricher;
use s2_extensions\activitypub\Presentation\ActivationProbeDocumentBuilder;
use s2_extensions\activitypub\Presentation\ActorKeyDocumentBuilder;
use s2_extensions\activitypub\Presentation\CanonicalJson;
use s2_extensions\activitypub\Presentation\ContentObjectDocumentBuilder;
use s2_extensions\activitypub\Presentation\HtmlFederationLinker;
use s2_extensions\activitypub\Presentation\LocalActivityDocumentBuilder;
use s2_extensions\activitypub\Presentation\LocalNoteDocumentBuilder;
use s2_extensions\activitypub\Presentation\RemoteCommentTextFormatter;
use s2_extensions\activitypub\Media\RemoteAvatarFetchClient;
use s2_extensions\activitypub\Media\RemoteAvatarImageInspector;
use s2_extensions\activitypub\Media\RemoteAvatarQueue;
use s2_extensions\activitypub\Media\RemoteAvatarQueueHandler;
use s2_extensions\activitypub\Media\RemoteAvatarStorage;
use s2_extensions\activitypub\Security\ActivityPubSecret;
use s2_extensions\activitypub\Security\ActorKeyVault;
use s2_extensions\activitypub\Security\CollectionCursorCodec;
use s2_extensions\activitypub\Security\LegacyHttpSignature;
use s2_extensions\activitypub\Security\LocalActorSigningService;
use s2_extensions\activitypub\Security\Rfc9421HttpSignature;
use s2_extensions\activitypub\Security\RsaCrypto;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Route;

final class Extension implements ExtensionInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->get(DynamicSecretParameterRegistry::class)
            ->registerExtensionPrivate(ActivityPubSecret::MASTER_KEY)
        ;

        $container->set(PublicIdGenerator::class, new PublicIdGenerator());
        $container->set(ContentProjectionStaging::class, new ContentProjectionStaging(), [StatefulServiceInterface::class]);
        $container->set(PortableHtmlSanitizer::class, static fn(Container $container): PortableHtmlSanitizer => new PortableHtmlSanitizer(
            $container->get(HttpClient::class),
        ));
        $container->set(ContentAttachmentExtractor::class, static fn(Container $container): ContentAttachmentExtractor => new ContentAttachmentExtractor(
            $container->get(HttpClient::class),
            $container->getStringParameter('image_dir'),
            $container->getStringParameter('image_path'),
        ));
        $container->set(RsaCrypto::class, new RsaCrypto());
        $container->set(LegacyHttpSignature::class, static fn(Container $container): LegacyHttpSignature => new LegacyHttpSignature(
            $container->get(RsaCrypto::class),
        ));
        $container->set(Rfc9421HttpSignature::class, static fn(Container $container): Rfc9421HttpSignature => new Rfc9421HttpSignature(
            $container->get(RsaCrypto::class),
        ));
        $container->set(ActorKeyVault::class, static fn(Container $container): ActorKeyVault => new ActorKeyVault(
            $container->get(DynamicSecretStore::class),
        ));
        $container->set(FederationStateRepository::class, static fn(Container $container): FederationStateRepository => new FederationStateRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ContentFederationSettingsRepository::class, static fn(Container $container): ContentFederationSettingsRepository => new ContentFederationSettingsRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ContentBackfillRepository::class, static fn(Container $container): ContentBackfillRepository => new ContentBackfillRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(LocalActorRepository::class, static fn(Container $container): LocalActorRepository => new LocalActorRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ContentActorResolver::class, static fn(Container $container): ContentActorResolver => new ContentActorResolver(
            $container->get(LocalActorRepository::class),
        ));
        $container->set(RemoteAvatarRepository::class, static fn(Container $container): RemoteAvatarRepository => new RemoteAvatarRepository(
            $container->get(DbLayer::class),
            $container->get(PublicIdGenerator::class),
        ));
        $container->set(RemoteAvatarQueue::class, static fn(Container $container): RemoteAvatarQueue => new RemoteAvatarQueue(
            $container->get(QueuePublisher::class),
            $container->get(RemoteAvatarRepository::class),
        ));
        $container->set(RemoteAvatarScheduler::class, static fn(Container $container): RemoteAvatarScheduler => new RemoteAvatarScheduler(
            $container->get(RemoteAvatarRepository::class),
            $container->get(RemoteAvatarQueue::class),
        ));
        $container->set(RemoteAvatarStorage::class, static fn(Container $container): RemoteAvatarStorage => new RemoteAvatarStorage(
            $container->getStringParameter('cache_dir'),
        ));
        $container->set(RemoteAvatarImageInspector::class, new RemoteAvatarImageInspector());
        $container->set(RemoteAvatarFetchClient::class, static fn(Container $container): RemoteAvatarFetchClient => new RemoteAvatarFetchClient(
            $container->get(SafeRemoteHttpClient::class),
        ));
        $container->set(RemoteAvatarMaintenanceService::class, static fn(Container $container): RemoteAvatarMaintenanceService => new RemoteAvatarMaintenanceService(
            $container->get(RemoteAvatarRepository::class),
            $container->get(RemoteAvatarQueue::class),
            $container->get(RemoteAvatarStorage::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(ActivationReadinessRepository::class, static fn(Container $container): ActivationReadinessRepository => new ActivationReadinessRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ActivityPubIdentityRecoveryService::class, static fn(Container $container): ActivityPubIdentityRecoveryService => new ActivityPubIdentityRecoveryService(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(DynamicSecretStore::class),
            $container->get(ActorKeyVault::class),
            $container->get(RsaCrypto::class),
            $container->get(CanonicalJson::class),
        ));
        $container->set(ActivityPubBackupContributor::class, static fn(Container $container): ActivityPubBackupContributor => new ActivityPubBackupContributor(
            $container->get(ActivityPubIdentityRecoveryService::class),
        ), [BackupContributorInterface::class]);
        $container->set(ActivityPubHousekeepingRepository::class, static fn(Container $container): ActivityPubHousekeepingRepository => new ActivityPubHousekeepingRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ActivityPubRunnerTelemetryRepository::class, static fn(Container $container): ActivityPubRunnerTelemetryRepository => new ActivityPubRunnerTelemetryRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ActivityPubMaintenanceTask::class, static fn(Container $container): ActivityPubMaintenanceTask => new ActivityPubMaintenanceTask(
            $container->get(QueuePublisher::class),
        ), [ScheduledMaintenanceTaskInterface::class]);
        $container->set(LocalFederationRepository::class, static fn(Container $container): LocalFederationRepository => new LocalFederationRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(InboxRepository::class, static fn(Container $container): InboxRepository => new InboxRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(RemoteActorRepository::class, static fn(Container $container): RemoteActorRepository => new RemoteActorRepository(
            $container->get(DbLayer::class),
            $container->get(RemoteAvatarScheduler::class),
        ));
        $container->set(FollowRepository::class, static fn(Container $container): FollowRepository => new FollowRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(InboxQueue::class, static fn(Container $container): InboxQueue => new InboxQueue(
            $container->get(QueuePublisher::class),
            $container->get(InboxRepository::class),
        ));
        $container->set(DeliveryRepository::class, static fn(Container $container): DeliveryRepository => new DeliveryRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(DeliveryQueue::class, static fn(Container $container): DeliveryQueue => new DeliveryQueue(
            $container->get(QueuePublisher::class),
            $container->get(DeliveryRepository::class),
        ));
        $container->set(DeliveryPlanner::class, static fn(Container $container): DeliveryPlanner => new DeliveryPlanner(
            $container->get(DeliveryRepository::class),
            $container->get(DeliveryQueue::class),
        ));
        $container->set(MentionDeliveryQueue::class, static fn(Container $container): MentionDeliveryQueue => new MentionDeliveryQueue(
            $container->get(QueuePublisher::class),
        ));
        $container->set(PortableDatabaseTransaction::class, static fn(Container $container): PortableDatabaseTransaction => new PortableDatabaseTransaction(
            $container->get(\PDO::class),
        ));
        $container->set(FederationUrlGeneratorFactory::class, static fn(Container $container): FederationUrlGeneratorFactory => new FederationUrlGeneratorFactory(
            $container->get(FederationStateRepository::class),
        ));
        $container->set(CollectionCursorCodec::class, static fn(Container $container): CollectionCursorCodec => new CollectionCursorCodec(
            $container->get(DynamicSecretStore::class),
        ));
        $container->set(PublicFederationAccess::class, static fn(Container $container): PublicFederationAccess => new PublicFederationAccess(
            $container->get(FederationStateRepository::class),
        ));
        $container->set(ActivityPubResponseFactory::class, new ActivityPubResponseFactory());
        $container->set(CanonicalJson::class, new CanonicalJson());
        $container->set(RemoteObjectRepository::class, static fn(Container $container): RemoteObjectRepository => new RemoteObjectRepository(
            $container->get(DbLayer::class),
            $container->get(CanonicalJson::class),
        ));
        $container->set(ReaderRepository::class, static fn(Container $container): ReaderRepository => new ReaderRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(InteractionRepository::class, static fn(Container $container): InteractionRepository => new InteractionRepository(
            $container->get(DbLayer::class),
            $container->get(CanonicalJson::class),
        ));
        $container->set(LocalInteractionRepository::class, static fn(Container $container): LocalInteractionRepository => new LocalInteractionRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ModerationRuleRepository::class, static fn(Container $container): ModerationRuleRepository => new ModerationRuleRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(MentionDeliveryPlanner::class, static fn(Container $container): MentionDeliveryPlanner => new MentionDeliveryPlanner(
            $container->get(RemoteActorRepository::class),
            $container->get(ModerationRuleRepository::class),
            $container->get(DeliveryPlanner::class),
            $container->get(MentionDeliveryQueue::class),
        ));
        $container->set(NotificationRepository::class, static fn(Container $container): NotificationRepository => new NotificationRepository(
            $container->get(DbLayer::class),
            $container->get(CanonicalJson::class),
        ));
        $container->set(RemoteCommentTextFormatter::class, new RemoteCommentTextFormatter());
        $container->set(ActivityPubCommentPresentationEnricher::class, static fn(Container $container): ActivityPubCommentPresentationEnricher => new ActivityPubCommentPresentationEnricher(
            $container->get(DbLayer::class),
        ), [CommentPresentationEnricherInterface::class]);
        $container->set(InboxRequestValidator::class, static fn(Container $container): InboxRequestValidator => new InboxRequestValidator(
            $container->get(LegacyHttpSignature::class),
            $container->get(Rfc9421HttpSignature::class),
        ));
        $container->set(InboxRateLimiter::class, static fn(Container $container): InboxRateLimiter => new InboxRateLimiter(
            $container->get(DbLayer::class),
        ));
        $container->set(RemoteActorDocumentValidator::class, static fn(Container $container): RemoteActorDocumentValidator => new RemoteActorDocumentValidator(
            $container->get(RsaCrypto::class),
            $container->get(CanonicalJson::class),
        ));
        $container->set(RemoteObjectDocumentValidator::class, static fn(Container $container): RemoteObjectDocumentValidator => new RemoteObjectDocumentValidator(
            $container->get(PortableHtmlSanitizer::class),
        ));
        $container->set(RemoteActorFetchClient::class, static fn(Container $container): RemoteActorFetchClient => new RemoteActorFetchClient(
            $container->get(SafeRemoteHttpClient::class),
            $container->get(LocalActorSigningService::class),
        ));
        $container->set(WebFingerClient::class, static fn(Container $container): WebFingerClient => new WebFingerClient(
            $container->get(SafeRemoteHttpClient::class),
        ));
        $container->set(RemoteActorDiscovery::class, static fn(Container $container): RemoteActorDiscovery => new RemoteActorDiscovery(
            $container->get(WebFingerClient::class),
            $container->get(RemoteActorFetchClient::class),
            $container->get(RemoteActorDocumentValidator::class),
            $container->get(RemoteActorRepository::class),
        ));
        $container->set(IncomingSignatureVerifier::class, static fn(Container $container): IncomingSignatureVerifier => new IncomingSignatureVerifier(
            $container->get(LegacyHttpSignature::class),
            $container->get(Rfc9421HttpSignature::class),
        ));
        $container->set(ContentObjectDocumentBuilder::class, static fn(Container $container): ContentObjectDocumentBuilder => new ContentObjectDocumentBuilder(
            $container->get(PortableHtmlSanitizer::class),
            $container->get(DynamicConfigProvider::class)->getStringProxy('S2_LANGUAGE')->get(),
            $container->get(ContentAttachmentExtractor::class),
        ));
        $container->set(ContentFederationPreviewService::class, static fn(Container $container): ContentFederationPreviewService => new ContentFederationPreviewService(
            $container->get(DbLayer::class),
            $container->get(ContentUrlGenerator::class),
            $container->get(ContentSlugService::class),
            $container->get(AuthorProfileRepository::class),
            $container->get(FederationStateRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(ContentActorResolver::class),
            $container->get(ContentObjectDocumentBuilder::class),
            $container->get(CanonicalJson::class),
        ));
        $container->set(LocalActivityDocumentBuilder::class, new LocalActivityDocumentBuilder());
        $container->set(LocalNoteDocumentBuilder::class, new LocalNoteDocumentBuilder());
        $container->set(ActorDocumentBuilder::class, static fn(Container $container): ActorDocumentBuilder => new ActorDocumentBuilder(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
        ));
        $container->set(ActivationProbeDocumentBuilder::class, static fn(Container $container): ActivationProbeDocumentBuilder => new ActivationProbeDocumentBuilder(
            $container->get(LocalActorRepository::class),
            $container->get(FederationStateRepository::class),
        ));
        $container->set(ActorKeyDocumentBuilder::class, static fn(Container $container): ActorKeyDocumentBuilder => new ActorKeyDocumentBuilder(
            $container->get(LocalActorRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
        ));
        $container->set(SiteActorProvisioner::class, static fn(Container $container): SiteActorProvisioner => new SiteActorProvisioner(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(PublicIdGenerator::class),
            $container->get(RsaCrypto::class),
            $container->get(ActorKeyVault::class),
            $container->get(PortableDatabaseTransaction::class),
            $container->get(PortableHtmlSanitizer::class),
        ));
        $container->set(AuthorActorService::class, static fn(Container $container): AuthorActorService => new AuthorActorService(
            $container->get(AuthorProfileRepository::class),
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(PublicIdGenerator::class),
            $container->get(RsaCrypto::class),
            $container->get(ActorKeyVault::class),
            $container->get(PortableHtmlSanitizer::class),
            $container->get(ActorDocumentBuilder::class),
            $container->get(LocalActivityDocumentBuilder::class),
            $container->get(CanonicalJson::class),
            $container->get(DeliveryPlanner::class),
            $container->get(PortableDatabaseTransaction::class),
        ));
        $container->set(FederationActivationService::class, static fn(Container $container): FederationActivationService => new FederationActivationService(
            $container->get(DbLayer::class),
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(PortableDatabaseTransaction::class),
            $container->get(ActivationReadinessRepository::class),
        ));
        $container->set(ReleaseInteroperabilityGateInterface::class, new BundledReleaseInteroperabilityGate());
        $container->set(ActivationReadinessStarter::class, static fn(Container $container): ActivationReadinessStarter => new ActivationReadinessStarter(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(ActivationReadinessRepository::class),
            $container->get(SiteActorProvisioner::class),
            $container->get(PublicIdGenerator::class),
            $container->get(ActorKeyVault::class),
            $container->get(RsaCrypto::class),
            $container->get(DbLayer::class),
            $container->get(ReleaseInteroperabilityGateInterface::class),
            $container->get(QueuePublisher::class),
            $container->getStringParameter('base_url'),
            $container->getStringParameter('base_path'),
        ));
        $container->set(ActivationProbeService::class, static fn(Container $container): ActivationProbeService => new ActivationProbeService(
            $container->get(ActivationReadinessRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(ActivationProbeDocumentBuilder::class),
            $container->get(LegacyHttpSignature::class),
        ));
        $container->set(ActivationReadinessQueueHandler::class, static fn(Container $container): ActivationReadinessQueueHandler => new ActivationReadinessQueueHandler(
            $container->get(ActivationReadinessRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(ActivationProbeDocumentBuilder::class),
            $container->get(SafeRemoteHttpClient::class),
            $container->get(LegacyHttpSignature::class),
            $container->get(ActorKeyVault::class),
            $container->get(CanonicalJson::class),
            $container->get(QueuePublisher::class),
            telemetry: $container->get(ActivityPubRunnerTelemetryRepository::class),
        ), [QueueHandlerInterface::class]);
        $container->set(ActorKeyRotationService::class, static fn(Container $container): ActorKeyRotationService => new ActorKeyRotationService(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(PublicIdGenerator::class),
            $container->get(RsaCrypto::class),
            $container->get(ActorKeyVault::class),
            $container->get(PortableDatabaseTransaction::class),
        ));
        $container->set(ActorIdentityMigrationService::class, static fn(Container $container): ActorIdentityMigrationService => new ActorIdentityMigrationService(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(RemoteActorRepository::class),
            $container->get(ModerationRuleRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(PublicIdGenerator::class),
            $container->get(ActorDocumentBuilder::class),
            $container->get(LocalActivityDocumentBuilder::class),
            $container->get(CanonicalJson::class),
            $container->get(DeliveryPlanner::class),
            $container->get(PortableDatabaseTransaction::class),
        ));
        $container->set(LocalActorSigningService::class, static fn(Container $container): LocalActorSigningService => new LocalActorSigningService(
            $container->get(LocalActorRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(ActorKeyVault::class),
            $container->get(LegacyHttpSignature::class),
            $container->get(Rfc9421HttpSignature::class),
        ));
        $container->set(ContentProjectionService::class, static fn(Container $container): ContentProjectionService => new ContentProjectionService(
            $container->get(ContentDetailsRepository::class),
            $container->get(FederationStateRepository::class),
            $container->get(ContentFederationSettingsRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(ContentActorResolver::class),
            $container->get(LocalFederationRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(PublicIdGenerator::class),
            $container->get(PortableDatabaseTransaction::class),
            $container->get(ContentObjectDocumentBuilder::class),
            $container->get(LocalActivityDocumentBuilder::class),
            $container->get(CanonicalJson::class),
            $container->get(DeliveryPlanner::class),
            $container->get(MentionDeliveryPlanner::class),
        ));
        $container->set(ContentBackfillStarter::class, static fn(Container $container): ContentBackfillStarter => new ContentBackfillStarter(
            $container->get(ContentRepository::class),
            $container->get(FederationStateRepository::class),
            $container->get(ContentBackfillRepository::class),
            $container->get(PublicIdGenerator::class),
            $container->get(QueuePublisher::class),
            $container->get(PortableDatabaseTransaction::class),
        ));
        $container->set(ContentBackfillQueueHandler::class, static fn(Container $container): ContentBackfillQueueHandler => new ContentBackfillQueueHandler(
            $container->get(ContentBackfillRepository::class),
            $container->get(ContentProjectionService::class),
            $container->get(PortableDatabaseTransaction::class),
            $container->get(QueuePublisher::class),
            $container->get(LoggerInterface::class),
            telemetry: $container->get(ActivityPubRunnerTelemetryRepository::class),
        ), [QueueHandlerInterface::class]);
        $container->set(OutgoingFollowService::class, static fn(Container $container): OutgoingFollowService => new OutgoingFollowService(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(RemoteActorRepository::class),
            $container->get(FollowRepository::class),
            $container->get(ModerationRuleRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(PublicIdGenerator::class),
            $container->get(LocalActivityDocumentBuilder::class),
            $container->get(CanonicalJson::class),
            $container->get(DeliveryPlanner::class),
            $container->get(PortableDatabaseTransaction::class),
        ));
        $container->set(OutgoingReplyService::class, static fn(Container $container): OutgoingReplyService => new OutgoingReplyService(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(RemoteActorRepository::class),
            $container->get(RemoteObjectRepository::class),
            $container->get(ModerationRuleRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(PublicIdGenerator::class),
            $container->get(LocalNoteDocumentBuilder::class),
            $container->get(LocalActivityDocumentBuilder::class),
            $container->get(CanonicalJson::class),
            $container->get(DeliveryPlanner::class),
            $container->get(PortableDatabaseTransaction::class),
        ));
        $container->set(OutgoingInteractionService::class, static fn(Container $container): OutgoingInteractionService => new OutgoingInteractionService(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(RemoteActorRepository::class),
            $container->get(RemoteObjectRepository::class),
            $container->get(LocalInteractionRepository::class),
            $container->get(ModerationRuleRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(PublicIdGenerator::class),
            $container->get(LocalActivityDocumentBuilder::class),
            $container->get(CanonicalJson::class),
            $container->get(DeliveryPlanner::class),
            $container->get(PortableDatabaseTransaction::class),
        ));
        $container->set(FederationLifecycleService::class, static fn(Container $container): FederationLifecycleService => new FederationLifecycleService(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(DeliveryRepository::class),
            $container->get(InboxRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(PublicIdGenerator::class),
            $container->get(LocalActivityDocumentBuilder::class),
            $container->get(CanonicalJson::class),
            $container->get(DeliveryPlanner::class),
            $container->get(DeliveryQueue::class),
            $container->get(InboxQueue::class),
            $container->get(PortableDatabaseTransaction::class),
        ));
        $container->set(ActivityPubMaintenanceQueueHandler::class, static fn(Container $container): ActivityPubMaintenanceQueueHandler => new ActivityPubMaintenanceQueueHandler(
            $container->get(ActivityPubHousekeepingRepository::class),
            $container->get(FederationLifecycleService::class),
            $container->get(QueuePublisher::class),
            remoteAvatarMaintenance: $container->get(RemoteAvatarMaintenanceService::class),
            telemetry: $container->get(ActivityPubRunnerTelemetryRepository::class),
        ), [QueueHandlerInterface::class]);
        $container->set(OriginDeliveryThrottle::class, static fn(Container $container): OriginDeliveryThrottle => new OriginDeliveryThrottle(
            $container->get(DbLayer::class),
        ));
        $container->set(ActivityDeliveryClient::class, static fn(Container $container): ActivityDeliveryClient => new ActivityDeliveryClient(
            $container->get(SafeRemoteHttpClient::class),
            $container->get(LocalActorSigningService::class),
        ));
        $container->set(DeliveryQueueHandler::class, static fn(Container $container): DeliveryQueueHandler => new DeliveryQueueHandler(
            $container->get(DeliveryRepository::class),
            $container->get(ActivityDeliveryClient::class),
            $container->get(OriginDeliveryThrottle::class),
            $container->get(FederationStateRepository::class),
            $container->get(DeliveryQueue::class),
            lifecycleService: $container->get(FederationLifecycleService::class),
            telemetry: $container->get(ActivityPubRunnerTelemetryRepository::class),
        ), [QueueHandlerInterface::class]);
        $container->set(MentionDeliveryQueueHandler::class, static fn(Container $container): MentionDeliveryQueueHandler => new MentionDeliveryQueueHandler(
            $container->get(LocalFederationRepository::class),
            $container->get(RemoteActorRepository::class),
            $container->get(RemoteActorFetchClient::class),
            $container->get(RemoteActorDocumentValidator::class),
            $container->get(FederationStateRepository::class),
            $container->get(MentionDeliveryPlanner::class),
            $container->get(MentionDeliveryQueue::class),
            $container->get(LoggerInterface::class),
            telemetry: $container->get(ActivityPubRunnerTelemetryRepository::class),
        ), [QueueHandlerInterface::class]);
        $container->set(InboxInteractionProcessor::class, static fn(Container $container): InboxInteractionProcessor => new InboxInteractionProcessor(
            $container->get(RemoteObjectDocumentValidator::class),
            $container->get(RemoteObjectRepository::class),
            $container->get(InteractionRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(FollowRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(CommentImportService::class),
            $container->get(RemoteCommentTextFormatter::class),
            $container->get(ReactionAggregateRepository::class),
            $container->get(ModerationRuleRepository::class),
            $container->get(NotificationRepository::class),
        ));
        $container->set(InboxActivityProcessor::class, static fn(Container $container): InboxActivityProcessor => new InboxActivityProcessor(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(FollowRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(PublicIdGenerator::class),
            $container->get(LocalActivityDocumentBuilder::class),
            $container->get(CanonicalJson::class),
            $container->get(DeliveryPlanner::class),
            $container->get(InboxInteractionProcessor::class),
            $container->get(ModerationRuleRepository::class),
            $container->get(NotificationRepository::class),
            $container->get(RemoteActorRepository::class),
            $container->get(OutgoingFollowService::class),
        ));
        $container->set(InboxQueueHandler::class, static fn(Container $container): InboxQueueHandler => new InboxQueueHandler(
            $container->get(InboxRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(RemoteActorRepository::class),
            $container->get(RemoteActorFetchClient::class),
            $container->get(RemoteActorDocumentValidator::class),
            $container->get(IncomingSignatureVerifier::class),
            $container->get(InboxActivityProcessor::class),
            $container->get(FederationStateRepository::class),
            $container->get(PortableDatabaseTransaction::class),
            $container->get(InboxQueue::class),
            telemetry: $container->get(ActivityPubRunnerTelemetryRepository::class),
        ), [QueueHandlerInterface::class]);
        $container->set(RemoteAvatarQueueHandler::class, static fn(Container $container): RemoteAvatarQueueHandler => new RemoteAvatarQueueHandler(
            $container->get(RemoteAvatarRepository::class),
            $container->get(RemoteAvatarFetchClient::class),
            $container->get(RemoteAvatarImageInspector::class),
            $container->get(RemoteAvatarStorage::class),
            $container->get(FederationStateRepository::class),
            $container->get(RemoteAvatarQueue::class),
            $container->get(LoggerInterface::class),
            telemetry: $container->get(ActivityPubRunnerTelemetryRepository::class),
        ), [QueueHandlerInterface::class]);
        $container->set(HtmlFederationLinker::class, static fn(Container $container): HtmlFederationLinker => new HtmlFederationLinker(
            $container->get(PublicFederationAccess::class),
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
        ));
        $container->set(WebFingerController::class, static fn(Container $container): WebFingerController => new WebFingerController(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(PublicFederationAccess::class),
            $container->get(ActivityPubResponseFactory::class),
            $container->get(ActivationProbeService::class),
        ));
        $container->set(InboxController::class, static fn(Container $container): InboxController => new InboxController(
            $container->get(FederationStateRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(PublicFederationAccess::class),
            $container->get(InboxRequestValidator::class),
            $container->get(InboxRateLimiter::class),
            $container->get(InboxRepository::class),
            $container->get(InboxQueue::class),
            $container->get(ActivityPubResponseFactory::class),
            $container->get(LoggerInterface::class),
            activationProbeService: $container->get(ActivationProbeService::class),
        ));
        $container->set(NodeInfoDiscoveryController::class, static fn(Container $container): NodeInfoDiscoveryController => new NodeInfoDiscoveryController(
            $container->get(PublicFederationAccess::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(ActivityPubResponseFactory::class),
        ));
        $container->set(NodeInfoController::class, static fn(Container $container): NodeInfoController => new NodeInfoController(
            $container->get(PublicFederationAccess::class),
            $container->get(LocalActorRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(ActivityPubResponseFactory::class),
            $container->get(DynamicConfigProvider::class)->getStringProxy('S2_SITE_NAME'),
            $container->getStringParameter('version'),
        ));
        $container->set(ActorController::class, static fn(Container $container): ActorController => new ActorController(
            $container->get(LocalActorRepository::class),
            $container->get(PublicFederationAccess::class),
            $container->get(ActorDocumentBuilder::class),
            $container->get(ActivityPubResponseFactory::class),
            $container->get(ActivationProbeService::class),
        ));
        $container->set(ActorKeyController::class, static fn(Container $container): ActorKeyController => new ActorKeyController(
            $container->get(LocalActorRepository::class),
            $container->get(PublicFederationAccess::class),
            $container->get(ActorKeyDocumentBuilder::class),
            $container->get(ActivityPubResponseFactory::class),
        ));
        $container->set(ObjectController::class, static fn(Container $container): ObjectController => new ObjectController(
            $container->get(LocalFederationRepository::class),
            $container->get(PublicFederationAccess::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(ActivityPubResponseFactory::class),
        ));
        $container->set(ObjectRepliesController::class, static fn(Container $container): ObjectRepliesController => new ObjectRepliesController(
            $container->get(LocalFederationRepository::class),
            $container->get(InteractionRepository::class),
            $container->get(RemoteObjectRepository::class),
            $container->get(PublicFederationAccess::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(CollectionCursorCodec::class),
            $container->get(ActivityPubResponseFactory::class),
        ));
        $container->set(RemoteAvatarController::class, static fn(Container $container): RemoteAvatarController => new RemoteAvatarController(
            $container->get(RemoteAvatarRepository::class),
            $container->get(RemoteAvatarStorage::class),
        ));
        $container->set(ActivityController::class, static fn(Container $container): ActivityController => new ActivityController(
            $container->get(LocalFederationRepository::class),
            $container->get(PublicFederationAccess::class),
            $container->get(ActivityPubResponseFactory::class),
        ));
        $container->set(ActorCollectionController::class, static fn(Container $container): ActorCollectionController => new ActorCollectionController(
            $container->get(LocalActorRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(FederationUrlGeneratorFactory::class),
            $container->get(CollectionCursorCodec::class),
            $container->get(PublicFederationAccess::class),
            $container->get(ActivityPubResponseFactory::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(
            ContentChangedEvent::class,
            static function (ContentChangedEvent $event) use ($container): void {
                if ($container->get(ContentProjectionStaging::class)->isDeferred($event->contentId)) {
                    return;
                }

                $container->get(ContentProjectionService::class)->synchronize($event->contentId);
            },
        );
        $eventDispatcher->addListener(
            ContentRenderedEvent::class,
            static function (ContentRenderedEvent $event) use ($container): void {
                $container->get(HtmlFederationLinker::class)->enrich($event);
            },
        );
        $eventDispatcher->addListener(
            CommentChangedEvent::class,
            static function (CommentChangedEvent $event) use ($container): void {
                if ($event->kind === CommentChangeKind::PUBLISHED) {
                    $container->get(InteractionRepository::class)->setReplyPublicByComment(
                        $event->commentId,
                        true,
                        time(),
                    );
                } elseif (\in_array($event->kind, [
                    CommentChangeKind::HIDDEN,
                    CommentChangeKind::TOMBSTONED,
                    CommentChangeKind::REMOVED,
                ], true)) {
                    $container->get(InteractionRepository::class)->setReplyPublicByComment(
                        $event->commentId,
                        false,
                        time(),
                    );
                }
            },
        );
    }

    /** @suppress PhanUnusedPublicFinalMethodParameter Required by the routing contract. */
    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
        unset($container);

        $publicIdRequirement = '[A-Za-z0-9_-]{22}';
        $readMethods         = ['GET', 'HEAD'];

        $routes->add('activitypub_webfinger', new Route(
            '/.well-known/webfinger',
            ['_controller' => WebFingerController::class],
            methods: $readMethods,
        ));
        $routes->add('activitypub_nodeinfo_discovery', new Route(
            '/.well-known/nodeinfo',
            ['_controller' => NodeInfoDiscoveryController::class],
            methods: $readMethods,
        ));
        $routes->add('activitypub_nodeinfo', new Route(
            '/nodeinfo/2.1',
            ['_controller' => NodeInfoController::class],
            methods: $readMethods,
        ));
        $routes->add('activitypub_shared_inbox', new Route(
            '/activitypub/inbox',
            ['_controller' => InboxController::class],
            methods: ['POST'],
        ));
        $routes->add('activitypub_actor_inbox', new Route(
            '/activitypub/actors/{publicId}/inbox',
            ['_controller' => InboxController::class],
            requirements: ['publicId' => $publicIdRequirement],
            methods: ['POST'],
        ));
        $routes->add('activitypub_actor_collection', new Route(
            '/activitypub/actors/{publicId}/{collection}',
            ['_controller' => ActorCollectionController::class],
            requirements: [
                'publicId'  => $publicIdRequirement,
                'collection' => 'outbox|followers|following|featured',
            ],
            methods: $readMethods,
        ));
        $routes->add('activitypub_actor', new Route(
            '/activitypub/actors/{publicId}',
            ['_controller' => ActorController::class],
            requirements: ['publicId' => $publicIdRequirement],
            methods: $readMethods,
        ));
        $routes->add('activitypub_object_replies', new Route(
            '/activitypub/objects/{publicId}/replies',
            ['_controller' => ObjectRepliesController::class],
            requirements: ['publicId' => $publicIdRequirement],
            methods: $readMethods,
        ));
        $routes->add('activitypub_object', new Route(
            '/activitypub/objects/{publicId}',
            ['_controller' => ObjectController::class],
            requirements: ['publicId' => $publicIdRequirement],
            methods: $readMethods,
        ));
        $routes->add('activitypub_remote_avatar', new Route(
            '/activitypub/media/{publicId}',
            ['_controller' => RemoteAvatarController::class],
            requirements: ['publicId' => $publicIdRequirement],
            methods: $readMethods,
        ));
        $routes->add('activitypub_activity', new Route(
            '/activitypub/activities/{publicId}',
            ['_controller' => ActivityController::class],
            requirements: ['publicId' => $publicIdRequirement],
            methods: $readMethods,
        ));
        $routes->add('activitypub_key', new Route(
            '/activitypub/keys/{publicId}',
            ['_controller' => ActorKeyController::class],
            requirements: ['publicId' => $publicIdRequirement],
            methods: $readMethods,
        ));
    }
}
