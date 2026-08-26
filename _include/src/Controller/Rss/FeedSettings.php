<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller\Rss;

use Register\Core\Config\DynamicConfigProvider;

/** Keeps feed sizes configurable without allowing accidental unbounded responses. */
final readonly class FeedSettings
{
    public const string ITEM_LIMIT_CONFIG_KEY = 'REGISTER_FEED_ITEMS';

    public const int DEFAULT_ITEM_LIMIT = 20;

    public const int MIN_ITEM_LIMIT = 1;

    public const int MAX_ITEM_LIMIT = 100;

    public function __construct(private DynamicConfigProvider $configProvider)
    {
    }

    public function itemLimit(): int
    {
        try {
            $itemLimit = (int)$this->configProvider->get(self::ITEM_LIMIT_CONFIG_KEY);
        } catch (\LogicException) {
            $itemLimit = self::DEFAULT_ITEM_LIMIT;
        }

        return max(self::MIN_ITEM_LIMIT, min(self::MAX_ITEM_LIMIT, $itemLimit));
    }
}
