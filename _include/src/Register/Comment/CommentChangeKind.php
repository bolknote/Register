<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

enum CommentChangeKind: string
{
    case CREATED = 'created';

    case PUBLISHED = 'published';

    case HIDDEN = 'hidden';

    case EDITED = 'edited';

    case TOMBSTONED = 'tombstoned';

    case REMOVED = 'removed';
}
