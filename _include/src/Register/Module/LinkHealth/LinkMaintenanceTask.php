<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use S2\Cms\Config\BoolProxy;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Queue\ScheduledMaintenanceTaskInterface;

final readonly class LinkMaintenanceTask implements ScheduledMaintenanceTaskInterface
{
    private const int DUE_BATCH_SIZE = 50;

    public function __construct(
        private DbLayer                 $dbLayer,
        private LinkInventoryRepository $repository,
        private LinkHealthRepository    $healthRepository,
        private HostRequestThrottle     $hostRequestThrottle,
        private QueuePublisher          $queuePublisher,
        private BoolProxy               $autoRepair,
    ) {
    }

    #[\Override]
    public function schedule(int $now, QueueExecutionBudget $budget): void
    {
        $this->hostRequestThrottle->prune($now);
        $this->healthRepository->pruneCheckHistory($now);

        $generation = $this->dbLayer
            ->select('value')
            ->from('config')
            ->where('name = :name')->setParameter('name', Manifest::INVENTORY_GENERATION_CONFIG_KEY)
            ->execute()
            ->result()
        ;
        if (!\is_string($generation) || !ctype_digit($generation)) {
            throw new \UnexpectedValueException('The link-inventory generation is missing or invalid.');
        }

        if ((int)$generation < Manifest::INVENTORY_GENERATION) {
            $this->queuePublisher->publishIfAbsent(
                LinkInventoryQueueHandler::JOB_ID,
                LinkQueue::INVENTORY_CODE,
                ['cursor' => 0],
                $now + 1,
            );
        }

        foreach ($this->repository->dueTargetIds($now, self::DUE_BATCH_SIZE) as $targetId) {
            $budget->checkpoint(0.01);
            $this->queuePublisher->publishIfAbsent(
                LinkQueue::targetJobId($targetId),
                LinkQueue::CHECK_CODE,
                LinkQueue::checkPayload($targetId),
                $now + 1,
            );
        }

        if ($this->autoRepair->get()) {
            foreach ($this->repository->repairableTargetIds(self::DUE_BATCH_SIZE) as $targetId) {
                $budget->checkpoint(0.01);
                $this->queuePublisher->publishIfAbsent(
                    LinkQueue::targetJobId($targetId),
                    LinkQueue::REPAIR_CODE,
                    ['target_id' => $targetId],
                    $now + 1,
                );
            }
        }
    }
}
