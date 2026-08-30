<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Psr\Log\LoggerInterface;
use Register\Auth\CommentNotificationRepository;
use Register\Comment\Antispam\SpamAssessmentRepository;
use Register\Comment\Antispam\SpamFeedbackService;
use Register\Comment\CommentMailDelivery;
use Register\Comment\CommentMailPublisher;
use Register\Comment\CommentMailQueueHandler;
use Register\Comment\CommentPublicationTrustPolicy;
use Register\Comment\CommentPresentationEnricherInterface;
use Register\Comment\CommentRepository;
use Register\Comment\CommentSubscriptionService;
use Register\Comment\ContentCommentNotifier;
use Register\Comment\ContentCommentRenderer;
use Register\Comment\ContentCommentStrategy;
use Register\Comment\ContentCommentTargetResolver;
use Register\Content\ContentRepository;
use Register\Content\ContentType;
use Register\Controller\Comment\CommentStrategyInterface;
use Register\Controller\Comment\PendingEmailCommentServiceInterface;
use Register\Controller\CommentController;
use Register\Controller\CommentModerationController;
use Register\Controller\CommentSentController;
use Register\Controller\CommentUnsubscribeController;
use Register\Live\LiveUpdateRepository;
use Register\Model\ArticleProvider;
use Register\Model\Comment\CommentModerationTokenManager;
use Register\Model\Comment\CommentThreadRenderer;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Url\ContentUrlGenerator;
use Register\Core\Comment\Antispam\CommentFormTokenManager;
use Register\Core\Comment\Antispam\SpamAssessmentStoreInterface;
use Register\Core\Comment\Antispam\SpamFeatureExtractor;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Comment\Antispam\SpamRateLimiter;
use Register\Core\Comment\Antispam\SpamReputationRepository;
use Register\Core\Comment\SpamDecisionProviderInterface;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Mail\CommentMailer;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\Comment\CommentThreadBuilder;
use Register\Core\Model\User\UserProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class CommentModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(SpamAssessmentRepository::class, static fn(Container $container): SpamAssessmentRepository => new SpamAssessmentRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(
            SpamAssessmentStoreInterface::class,
            static fn(Container $container): SpamAssessmentStoreInterface => $container->get(SpamAssessmentRepository::class),
        );
        $container->set(CommentRepository::class, static fn(Container $container): CommentRepository => new CommentRepository(
            $container->get(DbLayer::class),
            $container->get(LiveUpdateRepository::class),
            $container->get(EventDispatcherInterface::class),
        ));
        $container->set(CommentPublicationTrustPolicy::class, static fn(Container $container): CommentPublicationTrustPolicy => new CommentPublicationTrustPolicy(
            $container->get(DbLayer::class),
        ));
        $container->set(CommentSubscriptionService::class, static fn(Container $container): CommentSubscriptionService => new CommentSubscriptionService(
            $container->get(CommentRepository::class),
            $container->get(SpamIdentityHasher::class),
        ));
        $container->set(CommentModerationTokenManager::class, static fn(Container $container): CommentModerationTokenManager => new CommentModerationTokenManager(
            $container->get(SpamIdentityHasher::class),
        ));
        $container->set(CommentThreadRenderer::class, static fn(Container $container): CommentThreadRenderer => new CommentThreadRenderer(
            $container->get(Viewer::class),
            $container->get(CommentThreadBuilder::class),
            $container->get(CommentModerationTokenManager::class),
            $container->get(UrlBuilder::class),
            $container->getStringParameter('image_path'),
        ));
        $container->set(ContentCommentRenderer::class, static fn(Container $container): ContentCommentRenderer => new ContentCommentRenderer(
            $container->get(DbLayer::class),
            $container->get(CommentThreadRenderer::class),
            $container->get(AuthProvider::class),
            $container->get(CommentNotificationRepository::class),
            ...$container->getByTag(CommentPresentationEnricherInterface::class),
        ));
        $container->set(ContentCommentTargetResolver::class, static fn(Container $container): ContentCommentTargetResolver => new ContentCommentTargetResolver(
            $container->get(DbLayer::class),
            $container->get(ArticleProvider::class),
        ));
        $container->set(CommentMailDelivery::class, static fn(Container $container): CommentMailDelivery => new CommentMailDelivery(
            $container->get(CommentRepository::class),
            $container->get(CommentSubscriptionService::class),
            $container->get(ContentRepository::class),
            $container->get(ContentUrlGenerator::class),
            $container->get(UserProvider::class),
            $container->get(CommentMailer::class),
        ));
        $container->set(CommentMailPublisher::class, static fn(Container $container): CommentMailPublisher => new CommentMailPublisher(
            $container->get(QueuePublisher::class),
            $container->get(CommentMailDelivery::class),
        ));
        $container->set(CommentMailQueueHandler::class, static fn(Container $container): CommentMailQueueHandler => new CommentMailQueueHandler(
            $container->get(CommentMailDelivery::class),
        ), [QueueHandlerInterface::class]);
        $container->set(ContentCommentNotifier::class, static fn(Container $container): ContentCommentNotifier => new ContentCommentNotifier(
            $container->get(CommentRepository::class),
            $container->get(CommentSubscriptionService::class),
            $container->get(ContentRepository::class),
            $container->get(CommentMailPublisher::class),
        ));
        $container->set(ContentCommentStrategy::PAGE_SERVICE_ID, static fn(Container $container): ContentCommentStrategy => new ContentCommentStrategy(
            ContentType::PAGE,
            $container->get(CommentRepository::class),
            $container->get(ContentCommentTargetResolver::class),
            $container->get(ContentCommentNotifier::class),
        ), [CommentStrategyInterface::class]);
        $container->set(ContentCommentStrategy::POST_SERVICE_ID, static fn(Container $container): ContentCommentStrategy => new ContentCommentStrategy(
            ContentType::POST,
            $container->get(CommentRepository::class),
            $container->get(ContentCommentTargetResolver::class),
            $container->get(ContentCommentNotifier::class),
        ), [CommentStrategyInterface::class]);
        $container->set(SpamFeedbackService::class, static fn(Container $container): SpamFeedbackService => new SpamFeedbackService(
            $container->get(CommentRepository::class),
            $container->get(SpamIdentityHasher::class),
            $container->get(SpamFeatureExtractor::class),
            $container->get(SpamAssessmentRepository::class),
            $container->get(SpamReputationRepository::class),
            $container->get(ContentCommentNotifier::class),
        ));
        $container->set(CommentModerationController::class, static fn(Container $container): CommentModerationController => new CommentModerationController(
            $container->get(CommentRepository::class),
            $container->get(AuthProvider::class),
            $container->get(CommentModerationTokenManager::class),
            $container->get(SpamFeedbackService::class),
            $container->get(ContentCommentNotifier::class),
            $container->get(LoggerInterface::class),
            $container->get(UrlBuilder::class),
            $container->get('comments_translator'),
        ));
        $container->set(CommentController::class, static function (Container $container): CommentController {
            $provider = $container->get(DynamicConfigProvider::class);

            return new CommentController(
                $container->get(AuthProvider::class),
                $container->get(UserProvider::class),
                $container->get(ContentCommentStrategy::PAGE_SERVICE_ID),
                $container->get('comments_translator'),
                $container->get(UrlBuilder::class),
                $container->get(HtmlTemplateProvider::class),
                $container->get(Viewer::class),
                $container->get(LoggerInterface::class),
                $container->get(CommentMailPublisher::class),
                $container->get(SpamDecisionProviderInterface::class),
                $container->get(CommentFormTokenManager::class),
                $container->get(SpamRateLimiter::class),
                $container->get(SpamAssessmentRepository::class),
                $container->get(CommentPublicationTrustPolicy::class),
                $container->get(VisitorIdentityManager::class),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $provider->getBoolProxy('REGISTER_PREMODERATION'),
                $container->get(PendingEmailCommentServiceInterface::class),
            );
        }, ['dynamic_config_dependent']);
        $container->set(CommentSentController::class, static fn(Container $container): CommentSentController => new CommentSentController(
            $container->get(AuthProvider::class),
            $container->get(UserProvider::class),
            $container->get('comments_translator'),
            $container->get(UrlBuilder::class),
            $container->get(HtmlTemplateProvider::class),
            $container->get(CommentMailPublisher::class),
            ...$container->getByTag(CommentStrategyInterface::class),
        ), ['dynamic_config_dependent']);
        $container->set(CommentUnsubscribeController::class, static fn(Container $container): CommentUnsubscribeController => new CommentUnsubscribeController(
            $container->get('comments_translator'),
            $container->get(HtmlTemplateProvider::class),
            ...$container->getByTag(CommentStrategyInterface::class),
        ));
    }
}
