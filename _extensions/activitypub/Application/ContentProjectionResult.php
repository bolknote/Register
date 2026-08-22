<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use s2_extensions\activitypub\Domain\ContentProjectionAction;
use s2_extensions\activitypub\Infrastructure\StoredActivityRepresentation;
use s2_extensions\activitypub\Infrastructure\StoredObjectRepresentation;

final readonly class ContentProjectionResult
{
    /**
     * @param list<StoredActivityRepresentation> $activities
     */
    public function __construct(public ContentProjectionAction     $action, public ?StoredObjectRepresentation $object = null, public array                              $activities = [])
    {
    }
}
