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
use Register\Import\Telegram\TelegramManagedMediaStorage;
use Register\Import\Telegram\TelegramLiveImportConfig;
use Register\Import\Telegram\TelegramLiveImportController;
use Register\Import\Telegram\TelegramSettings;
use Register\Import\Telegram\TelegramBotConfigController;
use Register\Import\Telegram\TelegramMediaUploadStorage;
use Register\Import\Telegram\TelegramLiveMediaController;
use Register\Import\Telegram\TelegramFileClientInterface;
use Register\Import\Telegram\TelegramLiveReactionService;
use Register\Core\Config\DynamicConfigProvider;
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
        $container->set(TelegramManagedMediaStorage::class, static fn(Container $container): TelegramManagedMediaStorage => new TelegramManagedMediaStorage(
            $container->getStringParameter('public_root_dir'),
            $container->getStringParameter('base_path'),
        ));
        $container->set(TelegramImportService::class, static fn(Container $container): TelegramImportService => new TelegramImportService(
            $container->get(DbLayer::class),
            $container->get(\PDO::class),
            $container->get(CommentImportService::class),
            $container->get(CommentRepository::class),
            $container->get(ReactionAggregateRepository::class),
            $container->get(ExternalImportMapRepository::class),
            $container->get(TelegramManagedMediaStorage::class),
            $container->getStringParameter('base_url'),
        ));
        $container->set(TelegramSettings::class, static fn(Container $container): TelegramSettings => new TelegramSettings(
            $container->get(DynamicConfigProvider::class),
        ));
        $container->set(TelegramBotConfigController::class, static fn(Container $container): TelegramBotConfigController => new TelegramBotConfigController(
            $container->get(TelegramSettings::class),
        ));
        $container->set(TelegramLiveImportConfig::class, static fn(Container $container): TelegramLiveImportConfig => $container->get(TelegramSettings::class)->liveConfig(), ['dynamic_config_dependent']);
        $container->set(TelegramMediaUploadStorage::class, static fn(Container $container): TelegramMediaUploadStorage => new TelegramMediaUploadStorage(
            $container->getStringParameter('cache_dir') . 'telegram-media',
            $container->get(TelegramLiveImportConfig::class)->discussionExportId(),
            $container->get(ExternalImportMapRepository::class), $container->get(TelegramManagedMediaStorage::class),
        ), ['dynamic_config_dependent']);
        $container->set(TelegramFileClientInterface::class, static fn(Container $container): TelegramMediaUploadStorage => $container->get(TelegramMediaUploadStorage::class), ['dynamic_config_dependent']);
        $container->set(TelegramLiveMediaController::class, static fn(Container $container): TelegramLiveMediaController => new TelegramLiveMediaController(
            $container->get(TelegramLiveImportConfig::class), $container->get(TelegramMediaUploadStorage::class),
        ), ['dynamic_config_dependent']);
        $container->set(TelegramLiveReactionService::class, static fn(Container $container): TelegramLiveReactionService => new TelegramLiveReactionService(
            $container->get(TelegramImportService::class), $container->get(ExternalImportMapRepository::class), $container->get(\PDO::class),
        ));
        $container->set(TelegramLiveImportController::class, static fn(Container $container): TelegramLiveImportController => new TelegramLiveImportController(
            $container->get(TelegramLiveImportConfig::class),
            $container->get(TelegramImportService::class),
            $container->get(DynamicConfigProvider::class)->getBoolProxy('REGISTER_PREMODERATION'),
            $container->get(LoggerInterface::class),
            $container->getStringParameter('base_url'),
            $container->getStringParameter('cache_dir') . 'telegram-import.lock',
            $container->get(TelegramLiveReactionService::class),
            $container->get(TelegramFileClientInterface::class),
        ), ['dynamic_config_dependent']);
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
            $container->get(TelegramSettings::class),
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
