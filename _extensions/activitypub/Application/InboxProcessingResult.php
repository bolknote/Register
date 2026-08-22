<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\InboxState;

final readonly class InboxProcessingResult
{
    public function __construct(public InboxState $state, public string $detail)
    {
        if (!\in_array($state, [InboxState::PROCESSED, InboxState::IGNORED], true) || $detail === '') {
            throw new \InvalidArgumentException('An ActivityPub inbox processing result is invalid.');
        }
    }
}
