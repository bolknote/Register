<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Register\Schema\AnalyticsEventSchemaMigration;
use Register\Schema\AnalyticsBlogSchemaMigration;
use Register\Module\BaseModuleInstaller;
use Register\Module\BaseModuleRegistry;
use Register\Schema\CommentPrivacySchemaMigration;
use Register\Schema\ContentAuthorIndexSchemaMigration;
use Register\Schema\ContentMediaSchemaMigration;
use Register\Schema\PendingCommentSpamSchemaMigration;
use Register\Schema\ExternalImportSchemaMigration;
use Register\Schema\PublicAuthSchemaMigration;
use Register\Schema\QueueLeaseSchemaMigration;
use Register\Schema\SchemaManager;
use Register\Schema\SchemaMigrationInterface;
use Register\Schema\SchemaMigrator;
use Register\Schema\SessionAudienceSchemaMigration;
use Register\Schema\SocialEngagementSchemaMigration;
use Register\Schema\VisitorUserSchemaMigration;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Pdo\DbLayer;

final readonly class SchemaModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(
            BaseModuleInstaller::class,
            fn(Container $container): BaseModuleInstaller => new BaseModuleInstaller(
                $container->get(BaseModuleRegistry::class),
            ),
        );
        $container->set(
            ContentMediaSchemaMigration::class,
            new ContentMediaSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            PublicAuthSchemaMigration::class,
            new PublicAuthSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            VisitorUserSchemaMigration::class,
            new VisitorUserSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            SocialEngagementSchemaMigration::class,
            new SocialEngagementSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            CommentPrivacySchemaMigration::class,
            new CommentPrivacySchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            ExternalImportSchemaMigration::class,
            new ExternalImportSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            QueueLeaseSchemaMigration::class,
            new QueueLeaseSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            ContentAuthorIndexSchemaMigration::class,
            new ContentAuthorIndexSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            PendingCommentSpamSchemaMigration::class,
            new PendingCommentSpamSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            AnalyticsEventSchemaMigration::class,
            new AnalyticsEventSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            SessionAudienceSchemaMigration::class,
            new SessionAudienceSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(
            AnalyticsBlogSchemaMigration::class,
            new AnalyticsBlogSchemaMigration(),
            [SchemaMigrationInterface::class],
        );
        $container->set(SchemaMigrator::class, fn(Container $container): SchemaMigrator => new SchemaMigrator(
            $container->get(DbLayer::class),
            $container->getByTag(SchemaMigrationInterface::class),
        ));
        $container->set(SchemaManager::class, fn(Container $container): SchemaManager => new SchemaManager(
            $container->get(DbLayer::class),
            $container,
            $container->get(BaseModuleInstaller::class),
            $container->get(SchemaMigrator::class),
        ));
    }
}
