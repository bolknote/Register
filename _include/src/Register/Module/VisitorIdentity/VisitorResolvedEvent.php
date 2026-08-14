<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use Symfony\Component\HttpFoundation\Request;

final readonly class VisitorResolvedEvent
{
    public function __construct(
        public Request $request,
        public string  $visitorId,
        public bool    $trackPageView,
    ) {
    }
}
