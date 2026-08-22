<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Security;

final readonly class VerifiedHttpSignature
{
    /** @param list<string> $coveredComponents */
    public function __construct(
        public HttpSignatureKind $kind,
        public string            $keyId,
        public array             $coveredComponents,
        public int               $createdAt,
        public ?int              $expiresAt = null,
        public ?string           $label = null,
    ) {
    }
}
