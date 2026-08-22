<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Security;

final class ActivityPubSecret
{
    public const string MASTER_KEY = 'REGISTER_EXTENSION_ACTIVITYPUB_MASTER_KEY';

    /** @suppress PhanEmptyPrivateMethod Prevent instantiation of this constants-only namespace. */
    private function __construct()
    {
    }
}
