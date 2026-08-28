<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Psr\Log\LoggerInterface;
use Register\Comment\CommentImportService;
use Register\Comment\CommentRepository;
use Register\Import\ExternalImportMapRepository;
use Register\Import\Telegram\Admin\TelegramImportAdminConfigExtender;
use Register\Import\Telegram\Admin\TelegramImportAdminController;
use Register\Import\Telegram\Admin\TelegramImportAdminPage;
use Register\Import\Telegram\Admin\TelegramImportToken;
use Register\Import\Telegram\Admin\TelegramImportTranslationProvider;
use Register\Import\Telegram\TelegramImportService;
use Register\Module\Reactions\ReactionAggregateRepository;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Admin\AdminConfigExtenderInterface;
use Register\Admin\TranslationProviderInterface;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Model\PermissionChecker;
use Register\Core\Pdo\DbLayer;
use Register\Core\Security\Http\AdminMutationGuard;

final readonly class ImportModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(CommentImportService::class, static fn(Container $container): CommentImportService => new CommentImportService(
            $container->get(CommentRepository::class),
        ));
        $container->set(ExternalImportMapRepository::class, static fn(Container $container): ExternalImportMapRepository => new ExternalImportMapRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(TelegramImportService::class, static fn(Container $container): TelegramImportService => new TelegramImportService(
            $container->get(DbLayer::class),
            $container->get(\PDO::class),
            $container->get(CommentImportService::class),
            $container->get(CommentRepository::class),
            $container->get(ReactionAggregateRepository::class),
            $container->get(ExternalImportMapRepository::class),
            $container->getStringParameter('base_url'),
        ));
        $container->set(
            TelegramImportTranslationProvider::class,
            new TelegramImportTranslationProvider(),
            [TranslationProviderInterface::class],
        );
        $container->set(TelegramImportToken::class, static fn(Container $container): TelegramImportToken => new TelegramImportToken(
            $container->get(SettingStorageInterface::class),
        ));
        $container->set(TelegramImportAdminPage::class, static fn(Container $container): TelegramImportAdminPage => new TelegramImportAdminPage(
            $container->get(ExternalImportMapRepository::class),
            $container->get(TelegramImportToken::class),
            $container->get(TemplateRenderer::class),
            $container->get(Translator::class),
            $container->getStringParameter('base_path'),
        ));
        $container->set(
            TelegramImportAdminConfigExtender::class,
            static fn(Container $container): TelegramImportAdminConfigExtender => new TelegramImportAdminConfigExtender(
                $container->get(PermissionChecker::class),
                $container->get(TelegramImportAdminPage::class),
            ),
            [AdminConfigExtenderInterface::class],
        );
        $container->set(TelegramImportAdminController::class, static fn(Container $container): TelegramImportAdminController => new TelegramImportAdminController(
            $container->get(PermissionChecker::class),
            $container->get(TelegramImportToken::class),
            $container->get(TelegramImportService::class),
            $container->get(AdminMutationGuard::class),
            $container->get(Translator::class),
            $container->get(LoggerInterface::class),
        ));
    }
}
