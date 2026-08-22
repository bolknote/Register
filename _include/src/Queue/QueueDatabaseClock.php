<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

use Register\Rose\Storage\Exception\InvalidEnvironmentException;

final class QueueDatabaseClock
{
    public static function timestampExpression(\PDO $pdo): string
    {
        $driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!\is_string($driverName)) {
            throw new InvalidEnvironmentException('PDO returned an invalid driver name.');
        }

        return match ($driverName) {
            'mysql' => 'UNIX_TIMESTAMP()',
            'pgsql' => 'CAST(EXTRACT(EPOCH FROM CURRENT_TIMESTAMP) AS BIGINT)',
            'sqlite' => "CAST(strftime('%s', 'now') AS INTEGER)",
            default => throw new InvalidEnvironmentException(sprintf('Driver "%s" is not supported.', $driverName)),
        };
    }
}
