<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

/** Content-derived fragments that may change without changing the displayed post itself. */
final readonly class PostPageContext
{
    /**
     * @param list<array{title: string, link: string}> $seeAlso
     * @param array{title: string, link: string}|null  $back
     * @param array{title: string, link: string}|null  $forward
     * @param array<int, list<string>>                 $dayUrls
     */
    public function __construct(
        public string $author,
        public array  $seeAlso,
        public ?array $back,
        public ?array $forward,
        public int    $year,
        public int    $month,
        public int    $day,
        public string $slug,
        public array  $dayUrls,
    ) {
    }
}
