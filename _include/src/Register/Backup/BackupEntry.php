<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

/** A bounded supplemental entry included only inside the encrypted backup envelope. */
final readonly class BackupEntry
{
    private const int MAX_BYTES = 4_194_304;

    public function __construct(public string $name, public string $contents)
    {
        if (preg_match('~^extensions/[a-z0-9][a-z0-9_-]{0,63}/[a-zA-Z0-9][a-zA-Z0-9._/-]{0,190}$~D', $name) !== 1
            || str_contains($name, '//')
            || str_contains($name, '/./')
            || str_contains($name, '..')
            || \strlen($contents) > self::MAX_BYTES
        ) {
            throw new \InvalidArgumentException('A supplemental Register backup entry is invalid.');
        }
    }
}
