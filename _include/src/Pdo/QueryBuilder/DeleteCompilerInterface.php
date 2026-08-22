<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo\QueryBuilder;
use Register\Core\Pdo\DbLayerException;


interface DeleteCompilerInterface
{
    /**
     * @throws DbLayerException
     */
    public function getSql(DeleteBuilder $builder): string;
}
