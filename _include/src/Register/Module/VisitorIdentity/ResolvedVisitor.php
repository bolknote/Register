<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

final readonly class ResolvedVisitor
{
    public function __construct(
        public string $visitorId,
        public string $token,
        public string $source,
    ) {
    }
}
