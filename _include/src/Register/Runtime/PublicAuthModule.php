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
use Register\Auth\MagicLinkRateLimiter;
use Register\Auth\MagicLinkService;
use Register\Auth\PublicAuthController;
use Register\Auth\PublicAuthFormToken;
use Register\Auth\PublicAuthMailer;
use Register\Auth\PublicAuthRenderer;
use Register\Auth\PublicAuthRepository;
use Register\Auth\PublicAuthSettings;
use Register\Auth\PublicOAuthClient;
use Register\Auth\PublicSessionManager;
use Register\Live\LiveUpdateContext;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Url\ContentUrlGenerator;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Config\DynamicConfigProvider;
use Register\Controller\Comment\CommentStrategyInterface;
use Register\Controller\Comment\PendingEmailCommentServiceInterface;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\HttpClient\HttpClient;
use Register\Core\Mail\ApplicationMailerInterface;
use Register\Core\Mail\MailSettings;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\LoginRateLimiter;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\Viewer;

final readonly class PublicAuthModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(PublicAuthSettings::class, static fn(Container $container): PublicAuthSettings => new PublicAuthSettings(
            $container->get(DynamicConfigProvider::class),
            $container->get(MailSettings::class),
        ));
        $container->set(PublicAuthFormToken::class, static function (Container $container): PublicAuthFormToken {
            $provider = $container->get(DynamicConfigProvider::class);

            return new PublicAuthFormToken($provider->getStringProxy('REGISTER_ANTISPAM_SECRET'));
        });
        $container->set(PublicSessionManager::class, static fn(Container $container): PublicSessionManager => new PublicSessionManager(
            $container->get(DbLayer::class),
            $container->get(LoginRateLimiter::class),
            $container->get(SecurityAuditLogger::class),
            $container->get('translator'),
            $container->getStringParameter('base_path'),
            $container->getStringParameter('base_url'),
            $container->getStringParameter('cookie_name'),
            $container->getBoolParameter('force_admin_https'),
        ));
        $container->set(PublicAuthRepository::class, static fn(Container $container): PublicAuthRepository => new PublicAuthRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(CommentNotificationRepository::class, static fn(Container $container): CommentNotificationRepository => new CommentNotificationRepository(
            $container->get(DbLayer::class),
            $container->get(PublicAuthRepository::class),
        ));
        $container->set(PublicOAuthClient::class, static fn(Container $container): PublicOAuthClient => new PublicOAuthClient(
            $container->get(HttpClient::class),
            $container->get(PublicAuthSettings::class),
            $container->get(PublicAuthRepository::class),
            $container->get(UrlBuilder::class),
        ));
        $container->set(PublicAuthRenderer::class, static fn(Container $container): PublicAuthRenderer => new PublicAuthRenderer(
            $container->get(Viewer::class),
            $container->get(UrlBuilder::class),
            $container->get(AuthProvider::class),
            $container->get(PublicAuthSettings::class),
            $container->get(PublicAuthFormToken::class),
            $container->get(CommentNotificationRepository::class),
            $container->get(LiveUpdateContext::class),
        ));
        $container->set(PublicAuthMailer::class, static function (Container $container): PublicAuthMailer {
            $provider = $container->get(DynamicConfigProvider::class);

            return new PublicAuthMailer(
                $container->get('translator'),
                $provider->getStringProxy('REGISTER_SITE_NAME'),
                $container->get(ApplicationMailerInterface::class),
            );
        });
        $container->set(MagicLinkRateLimiter::class, static fn(Container $container): MagicLinkRateLimiter => new MagicLinkRateLimiter(
            $container->get(DbLayer::class),
            $container->get(SpamIdentityHasher::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(MagicLinkService::class, static fn(Container $container): MagicLinkService => new MagicLinkService(
            $container->get(PublicAuthSettings::class),
            $container->get(PublicAuthRepository::class),
            $container->get(PublicAuthMailer::class),
            $container->get(UrlBuilder::class),
            $container->get(MagicLinkRateLimiter::class),
            $container->get('translator'),
            $container->get(VisitorIdentityManager::class),
            $container->get(\Register\Comment\Antispam\SpamAssessmentRepository::class),
            $container->get(\Register\Comment\CommentMailPublisher::class),
            $container->get(\Register\Core\Model\User\UserProvider::class),
            ...$container->getByTag(CommentStrategyInterface::class),
        ), [PendingEmailCommentServiceInterface::class]);
        $container->set(
            PendingEmailCommentServiceInterface::class,
            static fn(Container $container): PendingEmailCommentServiceInterface => $container->get(MagicLinkService::class),
        );
        $container->set(PublicAuthController::class, static fn(Container $container): PublicAuthController => new PublicAuthController(
            $container->get(AuthProvider::class),
            $container->get(PublicSessionManager::class),
            $container->get(PublicAuthRepository::class),
            $container->get(PublicOAuthClient::class),
            $container->get(MagicLinkService::class),
            $container->get(PublicAuthRenderer::class),
            $container->get(PublicAuthFormToken::class),
            $container->get(CommentNotificationRepository::class),
            $container->get(ContentUrlGenerator::class),
            $container->get(HtmlTemplateProvider::class),
            $container->get(UrlBuilder::class),
            $container->get('translator'),
            $container->get(LoggerInterface::class),
            $container->get(VisitorIdentityManager::class),
        ));
    }
}
