<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

final readonly class CommentFormTokenValidation
{
    private function __construct(
        public bool    $valid,
        public ?string $error,
        public ?int    $ageSeconds,
        public ?string $visitorId,
    ) {
    }

    public static function valid(int $ageSeconds, string $visitorId): self
    {
        return new self(true, null, $ageSeconds, $visitorId);
    }

    public static function invalid(string $error): self
    {
        return new self(false, $error, null, null);
    }
}
