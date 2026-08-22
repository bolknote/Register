<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\CanonicalBasePath;
use Register\Extension\activitypub\Domain\CanonicalOrigin;

final readonly class ActivationReadinessReport
{
    /** @var array<string, ActivationCheckResult> */
    private array $results;

    /** @param list<ActivationCheckResult> $results */
    public function __construct(
        public string            $actorPublicId,
        public CanonicalOrigin   $canonicalOrigin,
        public CanonicalBasePath $basePath,
        public int               $checkedAt,
        array                    $results,
    ) {
        $indexed = [];
        foreach ($results as $result) {
            if (isset($indexed[$result->check->value])) {
                throw new \InvalidArgumentException('An ActivityPub activation readiness check is duplicated.');
            }

            $indexed[$result->check->value] = $result;
        }

        $this->results = $indexed;
    }

    /** @return list<ActivationCheckResult> */
    public function failures(): array
    {
        $failures = [];
        foreach (ActivationReadinessCheck::cases() as $check) {
            $result = $this->results[$check->value] ?? null;
            if (!$result instanceof ActivationCheckResult) {
                $failures[] = new ActivationCheckResult($check, false, 'The check was not run.');
                continue;
            }

            if (!$result->passed) {
                $failures[] = $result;
            }
        }

        return $failures;
    }

    /** @return list<ActivationCheckResult> */
    public function results(): array
    {
        $results = [];
        foreach (ActivationReadinessCheck::cases() as $check) {
            if (isset($this->results[$check->value])) {
                $results[] = $this->results[$check->value];
            }
        }

        return $results;
    }
}
