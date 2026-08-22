<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueuePublisher;
use Register\Extension\activitypub\Infrastructure\ActivityPubHousekeepingRepository;
use Register\Extension\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;

/** Advances one bounded retention operation per shutdown queue generation. */
final readonly class ActivityPubMaintenanceQueueHandler implements QueueHandlerInterface
{
    public const string CODE = 'register_activitypub_maintenance';

    public const string JOB_ID = 'activitypub-maintenance';

    private const int LAST_OPERATION = 9;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private ActivityPubHousekeepingRepository $repository,
        private FederationLifecycleService        $lifecycleService,
        private QueuePublisher                    $queuePublisher,
        ?\Closure                                 $clock = null,
        private ?RemoteAvatarMaintenanceService   $remoteAvatarMaintenance = null,
        private ?ActivityPubRunnerTelemetryRepository $telemetry = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [self::CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 0.08;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        $operation = $payload['operation'] ?? null;
        if ($id !== self::JOB_ID
            || $code !== self::CODE
            || !\is_int($operation)
            || $operation < 0
            || $operation > self::LAST_OPERATION
            || array_diff_key($payload, ['operation' => true]) !== []
        ) {
            throw new \InvalidArgumentException('Invalid ActivityPub maintenance job.');
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $now = ($this->clock)();
        $this->telemetry?->record($code, $now);
        $affected = match ($operation) {
            0 => $this->repository->redactExpiredInboxPayloads($now),
            1 => $this->repository->pruneDeliveryAttempts($now),
            2 => $this->repository->pruneDetachedRemoteSnapshots($now),
            3 => $this->repository->pruneRateLimits($now),
            4 => $this->repository->pruneReadNotifications($now),
            5 => $this->repository->pruneTerminalDeliveries($now),
            6 => (int)$this->lifecycleService->finishIfReady($now),
            7 => $this->repository->pruneExpiredActivationAttempts($now),
            8 => $this->remoteAvatarMaintenance?->scheduleDue($now) ?? 0,
            9 => $this->remoteAvatarMaintenance?->detachExpired($now) ?? 0,
        };
        $nextOperation = $affected >= 100 ? $operation : $operation + 1;
        if ($nextOperation <= self::LAST_OPERATION) {
            $this->queuePublisher->publish(
                self::JOB_ID,
                self::CODE,
                ['operation' => $nextOperation],
                $now + 1,
            );
        }
    }
}
