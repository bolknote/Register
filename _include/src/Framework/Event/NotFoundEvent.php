<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Framework\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class NotFoundEvent
{
    public function __construct(
        public readonly Request $request,
        public ?Response        $response = null
    ) {
    }
}
