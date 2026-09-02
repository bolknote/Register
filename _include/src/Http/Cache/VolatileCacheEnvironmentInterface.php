<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

interface VolatileCacheEnvironmentInterface
{
    public function apcuAvailable(): bool;

    public function tmpfsDirectory(string $applicationRoot): ?SecureVolatileCacheDirectory;
}
