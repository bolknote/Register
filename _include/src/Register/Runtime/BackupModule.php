<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Psr\Log\LoggerInterface;
use Register\Backup\BackupEncryptionKeyProvider;
use Register\Backup\BackupContributorInterface;
use Register\Backup\BackupEncryptor;
use Register\Backup\BackupManager;
use Register\Backup\BackupQueueHandler;
use Register\Backup\BackupScheduler;
use Register\Backup\DatabaseSnapshotter;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Security\Audit\SecurityAuditLogger;

final readonly class BackupModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(DatabaseSnapshotter::class, static fn(Container $container): DatabaseSnapshotter => new DatabaseSnapshotter(
            $container->get(\PDO::class),
            $container->getStringParameter('db_type'),
            $container->getStringParameter('db_host'),
            $container->getStringParameter('db_name'),
            $container->getStringParameter('db_username'),
            $container->getStringParameter('db_password'),
        ));
        $container->set(BackupEncryptionKeyProvider::class, static function (Container $container): BackupEncryptionKeyProvider {
            $configuredSecret = $container->getNullableStringParameter('backup_encryption_key');
            if (!\is_string($configuredSecret) || \strlen($configuredSecret) < BackupEncryptionKeyProvider::KEY_BYTES) {
                $staticSecret = $container->getNullableStringParameter('antispam_secret');
                $configuredSecret = \is_string($staticSecret)
                    && \strlen($staticSecret) >= BackupEncryptionKeyProvider::KEY_BYTES
                    ? $staticSecret
                    : '';
            }

            return new BackupEncryptionKeyProvider(
                $configuredSecret,
                $container->getNullableStringParameter('backup_recipient_public_key'),
            );
        });
        $container->set(BackupEncryptor::class, static fn(Container $container): BackupEncryptor => new BackupEncryptor(
            $container->get(BackupEncryptionKeyProvider::class),
        ));
        $container->set(BackupManager::class, static fn(Container $container): BackupManager => new BackupManager(
            $container->get(DatabaseSnapshotter::class),
            $container->get(BackupEncryptor::class),
            $container->get(LoggerInterface::class),
            $container->get(SecurityAuditLogger::class),
            $container->getStringParameter('backup_dir'),
            $container->getStringParameter('image_dir'),
            $container->getIntParameter('backup_retention'),
            $container->getStringParameter('version'),
            ...$container->getByTag(BackupContributorInterface::class),
        ));
        $container->set(BackupScheduler::class, static fn(Container $container): BackupScheduler => new BackupScheduler(
            $container->get(BackupManager::class),
            $container->get(LoggerInterface::class),
            $container->getBoolParameter('backup_enabled'),
        ));
        $container->set(BackupQueueHandler::class, static fn(Container $container): BackupQueueHandler => new BackupQueueHandler(
            $container->get(BackupManager::class),
            $container->get(QueuePublisher::class),
            $container->getBoolParameter('backup_enabled'),
        ), [QueueHandlerInterface::class]);
    }
}
