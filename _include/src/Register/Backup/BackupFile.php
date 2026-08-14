<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

final readonly class BackupFile
{
    public function __construct(
        public string $path,
        public string $name,
        public int    $createdAt,
        public int    $size,
    ) {
    }
}
