<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\ContentProjectionAction;
use Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation;
use Register\Extension\activitypub\Infrastructure\StoredObjectRepresentation;

final readonly class ContentProjectionResult
{
    /**
     * @param list<StoredActivityRepresentation> $activities
     */
    public function __construct(public ContentProjectionAction     $action, public ?StoredObjectRepresentation $object = null, public array                              $activities = [])
    {
    }
}
