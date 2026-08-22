<?php
/**
 * Database layer exception
 *
 * @copyright 2014-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Pdo;

class DbLayerException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, protected string $failedQuery = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getQuery(): string
    {
        return $this->failedQuery;
    }
}
