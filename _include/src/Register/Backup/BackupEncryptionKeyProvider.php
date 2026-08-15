<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Backup;

use S2\Cms\Config\StringProxy;

final readonly class BackupEncryptionKeyProvider
{
    public const int KEY_BYTES = 32;

    public function __construct(private StringProxy|string $secret)
    {
    }

    public function key(): string
    {
        $secret = $this->secret instanceof StringProxy ? $this->secret->get() : $this->secret;
        if (\strlen($secret) < self::KEY_BYTES) {
            throw new \RuntimeException(
                'Backup encryption requires a stable secret containing at least 32 bytes.',
            );
        }

        return hash_hkdf(
            'sha256',
            $secret,
            self::KEY_BYTES,
            'Register backup encryption key v1',
        );
    }
}
