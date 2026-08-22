<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub;

use Psr\Log\LoggerInterface;
use Register\Author\AuthorProfileRepository;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\Form\FormFactory;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Translator;
use S2\Cms\Admin\AdminConfigExtenderInterface;
use S2\Cms\Admin\AdminConfigProvider;
use S2\Cms\Admin\Event\AdminAjaxControllerMapEvent;
use S2\Cms\Admin\TranslationProviderInterface;
use S2\Cms\AdminYard\CustomMenuGeneratorEvent;
use S2\Cms\AdminYard\CustomTemplateRendererEvent;
use S2\Cms\AdminYard\Signal;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Queue\QueueMonitor;
use S2\Cms\Security\Http\AdminMutationGuard;
use s2_extensions\activitypub\Admin\ActivityPubActionController;
use s2_extensions\activitypub\Admin\ActivityPubAdminAccess;
use s2_extensions\activitypub\Admin\ActivityPubAdminPage;
use s2_extensions\activitypub\Admin\ActivityPubAdminRepository;
use s2_extensions\activitypub\Admin\ActivityPubContentEditorControllerFactory;
use s2_extensions\activitypub\Admin\ActivityPubContentPreviewController;
use s2_extensions\activitypub\Admin\ActivityPubToken;
use s2_extensions\activitypub\Admin\AdminConfigExtender;
use s2_extensions\activitypub\Admin\ContentSettingsEditor;
use s2_extensions\activitypub\Admin\ContentFederationSettingsFormParser;
use s2_extensions\activitypub\Admin\TranslationProvider;
use s2_extensions\activitypub\Application\OutgoingFollowService;
use s2_extensions\activitypub\Application\AuthorActorService;
use s2_extensions\activitypub\Application\ActorKeyRotationService;
use s2_extensions\activitypub\Application\ActorIdentityMigrationService;
use s2_extensions\activitypub\Application\ActivityPubIdentityRecoveryService;
use s2_extensions\activitypub\Application\FederationLifecycleService;
use s2_extensions\activitypub\Application\FederationPolicyService;
use s2_extensions\activitypub\Application\ActivationReadinessStarter;
use s2_extensions\activitypub\Application\FederationActivationService;
use s2_extensions\activitypub\Application\OutgoingInteractionService;
use s2_extensions\activitypub\Application\OutgoingReplyService;
use s2_extensions\activitypub\Application\ContentProjectionService;
use s2_extensions\activitypub\Application\ContentProjectionStaging;
use s2_extensions\activitypub\Application\ContentFederationPreviewService;
use s2_extensions\activitypub\Application\ContentBackfillStarter;
use s2_extensions\activitypub\Delivery\DeliveryQueue;
use s2_extensions\activitypub\Discovery\RemoteActorDiscovery;
use s2_extensions\activitypub\Inbox\InboxQueue;
use s2_extensions\activitypub\Media\RemoteAvatarQueue;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\ActivationReadinessRepository;
use s2_extensions\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use s2_extensions\activitypub\Infrastructure\ContentFederationSettingsRepository;
use s2_extensions\activitypub\Infrastructure\ContentBackfillRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Infrastructure\ModerationRuleRepository;
use s2_extensions\activitypub\Infrastructure\ReaderRepository;
use s2_extensions\activitypub\Infrastructure\RemoteActorRepository;
use s2_extensions\activitypub\Infrastructure\PortableDatabaseTransaction;
use s2_extensions\activitypub\Security\CollectionCursorCodec;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AdminExtension implements ContainerModuleInterface, ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(TranslationProvider::class, new TranslationProvider(), [TranslationProviderInterface::class]);
        $container->set(ActivityPubAdminRepository::class, static fn(Container $container): ActivityPubAdminRepository => new ActivityPubAdminRepository(
            $container->get(DbLayer::class),
            $container->get(\PDO::class),
        ));
        $container->set(ActivityPubAdminAccess::class, static fn(Container $container): ActivityPubAdminAccess => new ActivityPubAdminAccess(
            $container->get(PermissionChecker::class),
            $container->get(LocalActorRepository::class),
        ));
        $container->set(ActivityPubToken::class, static fn(Container $container): ActivityPubToken => new ActivityPubToken(
            $container->get(SettingStorageInterface::class),
        ));
        $container->set(ContentFederationSettingsFormParser::class, new ContentFederationSettingsFormParser());
        $container->set(ContentSettingsEditor::class, static fn(Container $container): ContentSettingsEditor => new ContentSettingsEditor(
            $container->get(ContentFederationSettingsRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(ContentProjectionService::class),
            $container->get(ContentProjectionStaging::class),
            $container->get(ContentFederationSettingsFormParser::class),
        ));
        $container->set(ActivityPubContentEditorControllerFactory::class, static fn(Container $container): ActivityPubContentEditorControllerFactory => new ActivityPubContentEditorControllerFactory(
            $container->get(PortableDatabaseTransaction::class),
        ));
        $container->set(ActivityPubContentPreviewController::class, static fn(Container $container): ActivityPubContentPreviewController => new ActivityPubContentPreviewController(
            $container->get(AdminConfigProvider::class),
            $container->get(FormFactory::class),
            $container->get(SettingStorageInterface::class),
            $container->get(AdminMutationGuard::class),
            $container->get(ContentFederationSettingsFormParser::class),
            $container->get(ContentFederationPreviewService::class),
            $container->get(Translator::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(FederationPolicyService::class, static fn(Container $container): FederationPolicyService => new FederationPolicyService(
            $container->get(FederationStateRepository::class),
        ));
        $container->set(ActivityPubAdminPage::class, static fn(Container $container): ActivityPubAdminPage => new ActivityPubAdminPage(
            $container->get(ActivityPubAdminRepository::class),
            $container->get(ActivityPubIdentityRecoveryService::class),
            $container->get(FederationStateRepository::class),
            $container->get(ActivationReadinessRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(LocalFederationRepository::class),
            $container->get(ContentBackfillRepository::class),
            $container->get(AuthorProfileRepository::class),
            $container->get(ReaderRepository::class),
            $container->get(CollectionCursorCodec::class),
            $container->get(ActivityPubToken::class),
            $container->get(QueueMonitor::class),
            $container->get(ActivityPubRunnerTelemetryRepository::class),
            $container->get(TemplateRenderer::class),
            $container->get(Translator::class),
            $container->get(RequestStack::class),
            $container->get(ActivityPubAdminAccess::class),
            $container->getStringParameter('base_path'),
            $container->getStringParameter('base_url'),
        ));
        $container->set(AdminConfigExtender::class, static fn(Container $container): AdminConfigExtender => new AdminConfigExtender(
            $container->get(ActivityPubAdminAccess::class),
            $container->get(ActivityPubAdminPage::class),
            $container->get(ContentSettingsEditor::class),
            $container->get(ActivityPubContentEditorControllerFactory::class),
            $container->get(Translator::class),
            $container->getStringParameter('db_prefix'),
        ), [AdminConfigExtenderInterface::class]);
        $container->set(ActivityPubActionController::class, static fn(Container $container): ActivityPubActionController => new ActivityPubActionController(
            $container->get(PermissionChecker::class),
            $container->get(ActivityPubAdminAccess::class),
            $container->get(ActivityPubToken::class),
            $container->get(AdminMutationGuard::class),
            $container->get(RemoteActorDiscovery::class),
            $container->get(RemoteActorRepository::class),
            $container->get(LocalActorRepository::class),
            $container->get(OutgoingFollowService::class),
            $container->get(OutgoingReplyService::class),
            $container->get(OutgoingInteractionService::class),
            $container->get(ActorKeyRotationService::class),
            $container->get(ActorIdentityMigrationService::class),
            $container->get(FederationLifecycleService::class),
            $container->get(FederationPolicyService::class),
            $container->get(ActivationReadinessStarter::class),
            $container->get(FederationActivationService::class),
            $container->get(AuthorActorService::class),
            $container->get(ModerationRuleRepository::class),
            $container->get(DeliveryQueue::class),
            $container->get(InboxQueue::class),
            $container->get(RemoteAvatarQueue::class),
            $container->get(ContentBackfillStarter::class),
            $container->get(Translator::class),
            $container->get(LoggerInterface::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(
            AdminAjaxControllerMapEvent::class,
            static function (AdminAjaxControllerMapEvent $event) use ($container): void {
                $event->controllerMap['register_activitypub_action'] = static function (
                    PermissionChecker $permissionChecker,
                    Request           $request,
                ) use ($container): \Symfony\Component\HttpFoundation\JsonResponse {
                    unset($permissionChecker);

                    return $container->get(ActivityPubActionController::class)->handle($request);
                };
                $event->controllerMap['register_activitypub_content_preview'] = (static fn(PermissionChecker $permissionChecker, Request           $request): \Symfony\Component\HttpFoundation\JsonResponse => $container->get(ActivityPubContentPreviewController::class)->preview(
                    $permissionChecker,
                    $request,
                ));
            },
        );
        $eventDispatcher->addListener(
            CustomTemplateRendererEvent::class,
            static function (CustomTemplateRendererEvent $event): void {
                $event->extraStyles[]  = $event->basePath . '/_assets/register/activitypub/admin.css';
                $event->extraScripts[] = $event->basePath . '/_assets/register/activitypub/admin.js';
            },
        );
        $eventDispatcher->addListener(
            CustomMenuGeneratorEvent::class,
            static function (CustomMenuGeneratorEvent $event) use ($container): void {
                if (!$container->get(PermissionChecker::class)->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)) {
                    return;
                }

                $summary = $container->get(ActivityPubAdminRepository::class)->summary();
                $failures = $summary['inbox_failed'] + $summary['deliveries_failed'] + $summary['avatars_failed'];
                if ($failures > 0) {
                    $event->addSignal('ActivityPub', new Signal(
                        (string)$failures,
                        $container->get(Translator::class)->trans('ActivityPub queue failures'),
                        '?entity=ActivityPub#activitypub-diagnostics',
                    ));
                }
            },
        );
    }
}
