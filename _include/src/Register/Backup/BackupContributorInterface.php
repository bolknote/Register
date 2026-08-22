<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

/** Supplies extension-owned recovery material to the authenticated encrypted archive. */
interface BackupContributorInterface
{
    /** @return list<BackupEntry> */
    public function backupEntries(): array;
}
