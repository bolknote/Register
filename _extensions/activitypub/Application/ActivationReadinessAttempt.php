<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use s2_extensions\activitypub\Domain\CanonicalBasePath;
use s2_extensions\activitypub\Domain\CanonicalOrigin;

/** One immutable identity candidate plus the durable results of its activation probes. */
final readonly class ActivationReadinessAttempt
{
    /** @var array<string, ActivationCheckResult> */
    private array $indexedResults;

    /** @param list<ActivationCheckResult> $results */
    public function __construct(
        public string                   $id,
        public int                      $actorId,
        public CanonicalOrigin          $canonicalOrigin,
        public CanonicalBasePath        $basePath,
        public ActivationReadinessState $state,
        public int                      $nextStep,
        array                           $results,
        public ?int                     $signedProbeReceivedAt,
        public int                      $createdAt,
        public int                      $updatedAt,
        public int                      $expiresAt,
        public ?int                     $completedAt,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $id) !== 1
            || $actorId < 1
            || $nextStep < 0
            || $createdAt < 1
            || $updatedAt < $createdAt
            || $expiresAt <= $createdAt
        ) {
            throw new \InvalidArgumentException('The ActivityPub activation attempt is invalid.');
        }

        $indexed = [];
        foreach ($results as $result) {
            if (isset($indexed[$result->check->value])) {
                throw new \InvalidArgumentException('An ActivityPub activation result is duplicated.');
            }

            $indexed[$result->check->value] = $result;
        }

        $this->indexedResults = $indexed;
    }

    /** @return list<ActivationCheckResult> */
    public function results(): array
    {
        $ordered = [];
        foreach (ActivationReadinessCheck::cases() as $check) {
            if (isset($this->indexedResults[$check->value])) {
                $ordered[] = $this->indexedResults[$check->value];
            }
        }

        return $ordered;
    }

    public function result(ActivationReadinessCheck $check): ?ActivationCheckResult
    {
        return $this->indexedResults[$check->value] ?? null;
    }

    public function isExpired(int $now): bool
    {
        return $now > $this->expiresAt;
    }

    public function report(string $actorPublicId): ActivationReadinessReport
    {
        if ($this->state !== ActivationReadinessState::READY || $this->completedAt === null) {
            throw new \DomainException('The ActivityPub activation checks are not ready.');
        }

        return new ActivationReadinessReport(
            $actorPublicId,
            $this->canonicalOrigin,
            $this->basePath,
            $this->completedAt,
            $this->results(),
        );
    }
}
