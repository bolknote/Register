<?php

declare(strict_types = 1);

/**
 * Describes the data provider for building the search index
 *
 * @copyright 2010-2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

namespace Register\Module\Search\Service;


interface BulkIndexingProviderInterface
{
    /**
     * Walks through all pages and gets info about them
     */
    public function getIndexables(): \Generator;
}
