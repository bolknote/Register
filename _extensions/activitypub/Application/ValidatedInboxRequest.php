<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

final readonly class ValidatedInboxRequest
{
    public function __construct(
        public IncomingActivity $activity,
        public string           $keyId,
        public string           $signatureType,
        public string           $effectiveOrigin,
        public string           $rawBody,
        public string           $transportJson,
    ) {
    }
}
