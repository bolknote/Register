<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final readonly class HtmlLinkRewriteResult
{
    public function __construct(
        public string $html,
        public int    $replacementCount,
    ) {
        if ($replacementCount < 0) {
            throw new \InvalidArgumentException('An HTML link replacement count cannot be negative.');
        }
    }
}
