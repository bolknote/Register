<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment;

class SpamDetectorReport
{
    public const string STATUS_FAILED = 'failed';

     // API call to a spam detection service failed
    public const string STATUS_DISABLED = 'disabled';

     // Spam detection service is disabled in the config
    public const string STATUS_HAM = 'ham';

     // The comment is not spam
    public const string STATUS_SPAM = 'spam';

     // The comment is spam
    public const string STATUS_BLATANT = 'blatant'; // The comment has a very high spam score; hardReject decides whether it can be dropped

    /** @param array<string, int> $reasons */
    private function __construct(
        public string $status,
        private readonly ?int $assessmentId = null,
        private readonly ?int $score = null,
        private readonly array $reasons = [],
        private readonly bool $hardReject = false,
    ) {
        if (!\in_array($this->status, [
            self::STATUS_FAILED,
            self::STATUS_DISABLED,
            self::STATUS_HAM,
            self::STATUS_SPAM,
            self::STATUS_BLATANT,
        ],
        true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown status "%s"', $this->status));
        }
    }

    public static function failed(?int $assessmentId = null): self
    {
        return new self(self::STATUS_FAILED, $assessmentId);
    }

    /** @param array<string, int> $reasons */
    public static function ham(?int $assessmentId = null, ?int $score = null, array $reasons = []): self
    {
        return new self(self::STATUS_HAM, $assessmentId, $score, $reasons);

    }

    public static function disabled(): self
    {
        return new self(self::STATUS_DISABLED);
    }

    /** @param array<string, int> $reasons */
    public static function spam(?int $assessmentId = null, ?int $score = null, array $reasons = []): self
    {
        return new self(self::STATUS_SPAM, $assessmentId, $score, $reasons);
    }

    /** @param array<string, int> $reasons */
    public static function blatant(
        ?int  $assessmentId = null,
        ?int  $score = null,
        array $reasons = [],
        bool  $hardReject = true,
    ): self
    {
        return new self(self::STATUS_BLATANT, $assessmentId, $score, $reasons, $hardReject);
    }

    public function isBlatant(): bool
    {
        return $this->status === self::STATUS_BLATANT;
    }

    public function isHam(): bool
    {
        return $this->status === self::STATUS_HAM;
    }

    public function isSpam(): bool
    {
        return $this->status === self::STATUS_SPAM;
    }

    public function getAssessmentId(): ?int
    {
        return $this->assessmentId;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    /** @return array<string, int> */
    public function getReasons(): array
    {
        return $this->reasons;
    }

    public function shouldReject(): bool
    {
        return $this->hardReject;
    }

    public function withAssessmentFrom(self $assessmentReport): self
    {
        return new self(
            $this->status,
            $assessmentReport->assessmentId,
            $assessmentReport->score,
            $assessmentReport->reasons,
            $this->hardReject,
        );
    }
}
