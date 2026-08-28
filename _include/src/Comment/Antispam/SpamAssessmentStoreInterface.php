<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

/**
 * Persistence boundary used by the reusable spam engine.
 *
 * Product-specific attachment and moderation operations remain on the concrete repository.
 */
interface SpamAssessmentStoreInterface
{
    public function save(SpamAssessment $assessment, string $status): int;

    public function setShadowStatus(int $assessmentId, string $status): void;

    public function deleteUnattachedOlderThan(int $timestamp, ?int $limit = null): int;

    public function deleteUnlabelledOlderThan(int $timestamp, ?int $limit = null): int;

    public function deleteOrphans(?int $limit = null): int;
}
