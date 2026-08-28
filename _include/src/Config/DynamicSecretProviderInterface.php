<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Config;

/** Contributes product or extension secret names without reversing the Core dependency. */
interface DynamicSecretProviderInterface
{
    /** @return list<string> */
    public function managedNames(): array;
}
