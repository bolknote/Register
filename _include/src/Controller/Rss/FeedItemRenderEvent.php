<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller\Rss;

readonly class FeedItemRenderEvent
{
    public function __construct(public FeedItemDto $feedItemDto)
    {
    }
}
