<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

/** A submitted attachment URL no longer matches its registered file. */
final class PostMediaConflictException extends \RuntimeException
{
    public const string UNAVAILABLE = 'Post media is unavailable';
}
