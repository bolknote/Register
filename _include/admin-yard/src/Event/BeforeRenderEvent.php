<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   AdminYard
 */

declare(strict_types=1);

namespace Register\AdminYard\Event;

class BeforeRenderEvent
{
    public function __construct(public ?array $data)
    {
    }
}
