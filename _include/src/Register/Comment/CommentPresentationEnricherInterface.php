<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

/** Extension boundary for batch-enriching comment presentation without coupling core storage. */
interface CommentPresentationEnricherInterface
{
    /**
     * @param non-empty-list<int> $commentIds
     * @return list<CommentPresentationEnrichment>
     */
    public function enrich(array $commentIds): array;
}
