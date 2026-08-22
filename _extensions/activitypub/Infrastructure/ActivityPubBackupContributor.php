<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Backup\BackupContributorInterface;
use Register\Backup\BackupEntry;
use Register\Extension\activitypub\Application\ActivityPubIdentityRecoveryService;

final readonly class ActivityPubBackupContributor implements BackupContributorInterface
{
    public function __construct(private ActivityPubIdentityRecoveryService $recoveryService)
    {
    }

    /** @return list<BackupEntry> */
    #[\Override]
    public function backupEntries(): array
    {
        return [new BackupEntry(
            'extensions/activitypub/identity-recovery.json',
            $this->recoveryService->exportRecoveryDocument() . "\n",
        )];
    }
}
