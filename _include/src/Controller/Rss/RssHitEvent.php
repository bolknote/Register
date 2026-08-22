<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller\Rss;

use Symfony\Component\HttpFoundation\Request;

readonly class RssHitEvent
{
    public function __construct(public Request $request, public RssStrategyInterface $rssStrategy)
    {
    }
}
