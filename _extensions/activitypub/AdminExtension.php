<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub;

use Psr\Log\LoggerInterface;
use Register\Author\AuthorProfileRepository;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Core\Admin\AdminConfigExtenderInterface;
use Register\Core\Admin\AdminConfigProvider;
use Register\Core\Admin\Event\AdminAjaxControllerMapEvent;
use Register\Core\Admin\TranslationProviderInterface;
use Register\Core\AdminYard\CustomMenuGeneratorEvent;
use Register\Core\AdminYard\CustomTemplateRendererEvent;
use Register\Core\AdminYard\Signal;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Model\PermissionChecker;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueueMonitor;
use Register\Core\Security\Http\AdminMutationGuard;
use Register\Extension\activitypub\Admin\ActivityPubActionController;
use Register\Extension\activitypub\Admin\ActivityPubAdminAccess;
use Register\Extension\activitypub\Admin\ActivityPubAdminPage;
use Register\Extension\activitypub\Admin\ActivityPubAdminRepository;
use Register\Extension\activitypub\Admin\ActivityPubContentEditorControllerFactory;
use Register\Extension\activitypub\Admin\ActivityPubContentPreviewController;
use Register\Extension\activitypub\Admin\ActivityPubToken;
use Register\Extension\activitypub\Admin\AdminConfigExtender;
use Register\Extension\activitypub\Admin\ContentSettingsEditor;
use Register\Extension\activitypub\Admin\ContentFederationSettingsFormParser;
use Register\Extension\activitypub\Admin\TranslationProvider;
use Register\Extension\activitypub\Application\OutgoingFollowService;
use Register\Extension\activitypub\Application\AuthorActorService;
use Register\Extension\activitypub\Application\ActorKeyRotationService;
use Register\Extension\activitypub\Application\ActorIdentityMigrationService;
use Register\Extension\activitypub\Application\ActivityPubIdentityRecoveryService;
use Register\Extension\activitypub\Application\FederationLifecycleService;
use Register\Extension\activitypub\Application\FederationPolicyService;
use Register\Extension\activitypub\Application\ActivationReadinessStarter;
use Register\Extension\activitypub\Application\FederationActivationService;
use Register\Extension\activitypub\Application\OutgoingInteractionService;
use Register\Extension\activitypub\Application\OutgoingReplyService;
use Register\Extension\activitypub\Application\ContentProjectionService;
use Register\Extension\activitypub\Application\ContentProjectionStaging;
use Register\Extension\activitypub\Application\ContentFederationPreviewService;
use Register\Extension\activitypub\Application\ContentBackfillStarter;
use Register\Extension\activitypub\Delivery\DeliveryQueue;
use Register\Extension\activitypub\Discovery\RemoteActorDiscovery;
use Register\Extension\activitypub\Inbox\InboxQueue;
use Register\Extension\activitypub\Media\RemoteAvatarQueue;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\ActivationReadinessRepository;
use Register\Extension\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use Register\Extension\activitypub\Infrastructure\ContentFederationSettingsRepository;
use Register\Extension\activitypub\Infrastructure\ContentBackfillRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\ModerationRuleRepository;
use Register\Extension\activitypub\Infrastructure\ReaderRepository;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Security\CollectionCursorCodec;
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
