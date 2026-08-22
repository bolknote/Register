<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment;

class SpamDetectorComment
{
    public function __construct(
        public string  $name,
        public string  $email,
        public string  $text,
        public ?string $userAgent = null,
        public ?string $referrer = null,
        public ?string $permalink = null,
        public ?int    $formAgeSeconds = null,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
