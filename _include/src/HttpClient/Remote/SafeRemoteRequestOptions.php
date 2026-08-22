<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\HttpClient\Remote;

/** Limits for one DNS-pinned HTTP hop. Redirects are always returned to the caller. */
final readonly class SafeRemoteRequestOptions
{
    public function __construct(
        public int   $connectTimeout = 2,
        public int   $readTimeout = 3,
        public int   $maxResponseBytes = 1_048_576,
        public bool  $requireHttps = true,
        public float $deadlineSafetyMargin = 0.25,
    ) {
        if ($connectTimeout < 1 || $readTimeout < 1 || $maxResponseBytes < 1) {
            throw new \InvalidArgumentException('Safe remote request limits must be positive.');
        }

        if (!is_finite($deadlineSafetyMargin) || $deadlineSafetyMargin < 0.0) {
            throw new \InvalidArgumentException('The remote request deadline margin must be finite and non-negative.');
        }
    }
}
