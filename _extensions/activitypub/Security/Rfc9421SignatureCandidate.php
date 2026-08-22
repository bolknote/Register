<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Security;

final readonly class Rfc9421SignatureCandidate
{
    /** @param list<string> $coveredComponents */
    public function __construct(
        public string $label,
        public string $keyId,
        public array  $coveredComponents,
        public int    $createdAt,
        public ?int   $expiresAt,
        public string $signatureBase,
        public string $signature,
    ) {
    }
}
