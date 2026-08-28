<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Register\Content\ContentPublicationScheduler;
use Register\Maintenance\ScheduledMaintenance;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Queue\ScheduledMaintenanceTaskInterface;
use Register\Core\Queue\ScheduledWorkCoordinatorInterface;

final readonly class MaintenanceModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(ScheduledMaintenance::class, static fn(Container $container): ScheduledMaintenance => new ScheduledMaintenance(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
            $container->get(QueuePublisher::class),
            $container->get(ContentPublicationScheduler::class),
            $container->getBoolParameter('backup_enabled'),
            ...$container->getByTag(ScheduledMaintenanceTaskInterface::class),
        ));
        $container->set(
            ScheduledWorkCoordinatorInterface::class,
            static fn(Container $container): ScheduledWorkCoordinatorInterface => $container->get(ScheduledMaintenance::class),
        );
    }
}
