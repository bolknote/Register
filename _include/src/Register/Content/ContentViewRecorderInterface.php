<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

/** Latency-safe entry point used by public page rendering to record a view. */
interface ContentViewRecorderInterface
{
    public function record(ContentId $contentId, ?string $day = null): void;
}
