<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

final readonly class UniqueSlugGenerator
{
    public function __construct(private SlugGenerator $slugGenerator)
    {
    }

    /**
     * @param callable(string): bool $isAvailable
     */
    public function generate(string $title, callable $isAvailable): string
    {
        $base = $this->slugGenerator->generate($title);
        if ($base === '') {
            $base = 'post';
        }

        $slug   = $base;
        $suffix = 2;
        while (!$isAvailable($slug)) {
            $suffixPart = '-' . $suffix;
            $slug       = rtrim(
                substr($base, 0, SlugGenerator::MAX_LENGTH - strlen($suffixPart)),
                '-',
            ) . $suffixPart;
            ++$suffix;
        }

        return $slug;
    }
}
