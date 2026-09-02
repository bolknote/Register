<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Psr\Log\LoggerInterface;

/** Makes a public request perform one short file append instead of a contended SQL upsert. */
final readonly class ContentViewSpoolRecorder implements ContentViewRecorderInterface
{
    public function __construct(
        private ContentViewSpool      $spool,
        private ContentViewRepository $fallback,
        private LoggerInterface       $logger,
    ) {
    }

    #[\Override]
    public function record(ContentId $contentId, ?string $day = null): void
    {
        $increment = new ContentViewIncrement($contentId, $day ?? gmdate('Y-m-d'));
        try {
            $this->spool->append($increment);
        } catch (\Throwable $throwable) {
            // Losing a view is worse than a slower request; a writable database remains the
            // last-resort path when a host temporarily makes the cache directory unavailable.
            $this->logger->warning('Content-view spool is unavailable; using a direct database write.', [
                'exception' => $throwable,
            ]);
            $this->fallback->recordBatch($increment);
        }
    }
}
