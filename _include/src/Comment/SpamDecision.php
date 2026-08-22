<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment;

readonly class SpamDecision
{
    public static function empty(): self
    {
        return new self(SpamDetectorReport::disabled(), false, false, false);
    }

    public function __construct(
        private SpamDetectorReport $report,
        private bool               $rejectLinks,
        private bool               $rejectSpam,
        private bool               $forceModeration,
    ) {
    }

    public function shouldRejectLinks(): bool
    {
        return $this->rejectLinks;
    }

    public function shouldRejectAsSpam(): bool
    {
        return $this->rejectSpam;
    }

    public function shouldModerate(bool $premoderationEnabled): bool
    {
        if ($this->forceModeration) {
            return true;
        }

        return match ($this->report->status) {
            SpamDetectorReport::STATUS_FAILED => true,
            SpamDetectorReport::STATUS_DISABLED,
            SpamDetectorReport::STATUS_HAM => $premoderationEnabled,
            default => true,
        };
    }

    public function getReport(): SpamDetectorReport
    {
        return $this->report;
    }

    public function getStatus(): string
    {
        return $this->report->status;
    }
}
