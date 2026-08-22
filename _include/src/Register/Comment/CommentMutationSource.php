<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

enum CommentMutationSource: string
{
    case LOCAL = 'local';

    case IMPORTED = 'imported';
}
