<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Framework\Exception;

class ConfigurationException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $title = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
