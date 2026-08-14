<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

use Psr\Log\LoggerInterface;

final readonly class BackupScheduler
{
    public function __construct(
        private BackupManager   $backupManager,
        private LoggerInterface $logger,
        private bool            $enabled,
    ) {
    }

    public function run(?int $now = null): ?BackupFile
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            return $this->backupManager->createIfDue($now);
        } catch (\Throwable $throwable) {
            $this->logger->error('Automatic Register backup failed.', ['exception' => $throwable]);

            return null;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
