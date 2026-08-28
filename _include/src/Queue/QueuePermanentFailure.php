<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Queue;

/** Marks malformed work or a permanent external rejection that must not be retried. */
final class QueuePermanentFailure extends \RuntimeException
{
}
