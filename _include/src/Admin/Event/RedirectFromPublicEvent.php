<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

class RedirectFromPublicEvent extends Event
{
    public function __construct(
        public readonly Request $request,
        public readonly string  $path,
    ) {
    }
}
