<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final readonly class ArchiveLookupResult
{
    private function __construct(
        public ArchiveStatus $status,
        public ?string       $url,
        public ?string       $timestamp,
    ) {
    }

    public static function available(string $url, string $timestamp): self
    {
        if ($url === '' || preg_match('/^[0-9]{14}$/D', $timestamp) !== 1) {
            throw new \InvalidArgumentException('An available archive snapshot requires a URL and timestamp.');
        }

        return new self(ArchiveStatus::AVAILABLE, $url, $timestamp);
    }

    public static function missing(): self
    {
        return new self(ArchiveStatus::MISSING, null, null);
    }
}
