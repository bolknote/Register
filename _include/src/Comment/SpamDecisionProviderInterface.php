<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment;

interface SpamDecisionProviderInterface
{
    public function getVerdict(SpamDetectorComment $comment, string $clientIp): SpamDecision;
}
