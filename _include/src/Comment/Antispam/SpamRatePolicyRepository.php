<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;

final readonly class SpamRatePolicyRepository
{
    /** @var array<string, array{limit: int, window: int}> */
    public const array DEFAULT_POLICIES = [
        'ip'      => ['limit' => 5, 'window' => 10 * 60],
        'email'   => ['limit' => 4, 'window' => 10 * 60],
        'visitor' => ['limit' => 5, 'window' => 10 * 60],
        'text'    => ['limit' => 3, 'window' => 24 * 60 * 60],
    ];

    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * @return array<string, SpamRatePolicy>
     * @throws DbLayerException
     */
    public function getPolicies(): array
    {
        $policies = [];
        foreach (self::DEFAULT_POLICIES as $bucketType => $policy) {
            $policies[$bucketType] = new SpamRatePolicy($bucketType, $policy['limit'], $policy['window']);
        }

        $rows = $this->dbLayer
            ->select('bucket_type', 'request_limit', 'window_seconds', 'enabled')
            ->from('spam_rate_policies')
            ->execute()
            ->fetchAssocAll()
        ;

        foreach ($rows as $row) {
            $bucketType = (string)$row['bucket_type'];
            if (!\array_key_exists($bucketType, self::DEFAULT_POLICIES)) {
                continue;
            }

            if (!(bool)$row['enabled']) {
                unset($policies[$bucketType]);
                continue;
            }

            $policies[$bucketType] = new SpamRatePolicy(
                $bucketType,
                max(1, min(1_000, (int)$row['request_limit'])),
                max(10, min(30 * 24 * 60 * 60, (int)$row['window_seconds'])),
            );
        }

        return $policies;
    }
}
