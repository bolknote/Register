<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

final readonly class DatabaseSnapshot
{
    public function __construct(
        public string $path,
        public string $archiveName,
        public string $driver,
    ) {
    }
}
