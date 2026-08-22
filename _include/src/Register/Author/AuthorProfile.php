<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Author;

/** Public product identity for an author, without login or contact credentials. */
final readonly class AuthorProfile
{
    public function __construct(
        public int    $id,
        public string $displayName,
        public bool   $canPublish,
    ) {
        if ($id <= 0) {
            throw new \InvalidArgumentException('An author identifier must be positive.');
        }
    }
}
