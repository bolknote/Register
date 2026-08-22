<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Dashboard;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;

readonly class DashboardDatabaseProvider
{
    public function __construct(
        private DbLayer $dbLayer,
        private string  $dbType,
        private string  $dbName,
        private string  $dbPrefix,
    ) {
    }

    /**
     * @return array{size: int|null, records: int|null, type: string, version: string}
     * @throws DbLayerException
     */
    public function getInfo(): array
    {
        $totalSize = null;
        $totalRecords = null;
        // Collect some additional info about MySQL
        if ($this->dbType === 'mysql') {
            // Calculate total db size/row count
            // TODO get rid of hardcoded 'register_search_idx_' prefix
            $result = $this->dbLayer->query('SHOW TABLE STATUS FROM `' . $this->dbName . "` WHERE NAME LIKE '" . $this->dbPrefix . "%' AND NAME NOT LIKE '" . $this->dbPrefix . "register_search_idx_%'");
            $totalRecords = 0;
            $totalSize = 0;
            while ($status = $result->fetchAssoc()) {
                $totalRecords += (int)$status['Rows'];
                $totalSize    += (int)$status['Data_length'] + (int)$status['Index_length'];
            }
        }

        $versionInfo = $this->dbLayer->getVersion();

        return [
            'size'    => $totalSize,
            'records' => $totalRecords,
            'type'    => $versionInfo['name'],
            'version' => $versionInfo['version'],
        ];
    }
}
