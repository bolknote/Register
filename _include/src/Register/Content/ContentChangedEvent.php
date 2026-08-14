<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

/**
 * Announces that a canonical content item must be refreshed by derived subsystems.
 *
 * Consumers must look up the item again: it may have changed, become private, or been deleted.
 */
final readonly class ContentChangedEvent
{
    public function __construct(public ContentId $contentId)
    {
    }
}
