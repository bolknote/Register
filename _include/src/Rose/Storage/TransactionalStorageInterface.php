<?php

declare(strict_types = 1);

/**
 * @copyright 2016 Roman Parpalak
 * @license   MIT
 */

namespace Register\Rose\Storage;

/**
 * Interface TransactionalStorageInterface
 */
interface TransactionalStorageInterface
{
    /**
     * Starts a transaction
     */
    public function startTransaction(): void;

    /**
     * Commits a transaction
     */
    public function commitTransaction(): void;

    /**
     * Rollbacks a transaction
     */
    public function rollbackTransaction(): void;
}
