<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Infrastructure\StoredLocalNoteRepresentation;

/** Identifies a locally-authored standalone Note as an incoming reply target. */
final readonly class LocalNoteReplyTarget
{
    public function __construct(public StoredLocalNoteRepresentation $note)
    {
    }
}
