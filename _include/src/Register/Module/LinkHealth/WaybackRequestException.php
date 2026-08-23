<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final class WaybackRequestException extends \RuntimeException
{
    public function __construct(public readonly int $statusCode)
    {
        parent::__construct('The Wayback Availability API request failed with HTTP ' . $statusCode . '.');
    }
}
