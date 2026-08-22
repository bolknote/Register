<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

/** Generates the frozen 128-bit, unpadded base64url identifier format. */
final class PublicIdGenerator
{
    public function generate(): string
    {
        $id = sodium_bin2base64(random_bytes(16), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        if (\strlen($id) !== 22) {
            throw new \LogicException('The platform returned an invalid ActivityPub identifier.');
        }

        return $id;
    }
}
