<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Domain;

/** Security and interoperability limits frozen by the ActivityPub protocol profile. */
final class ProtocolLimits
{
    public const int JSON_DEPTH = 32;

    public const int INBOX_BODY_BYTES = 1_048_576;

    public const int ACTOR_DOCUMENT_BYTES = 1_048_576;

    public const int OBJECT_DOCUMENT_BYTES = 1_048_576;

    public const int COLLECTION_DOCUMENT_BYTES = 2_097_152;

    public const int SIGNATURE_HEADER_BYTES = 16_384;

    private function __construct()
    {
        throw new \LogicException('ActivityPub protocol limits are static.');
    }
}
