<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final class LinkQueue
{
    public const string INVENTORY_CODE = 'register_link_inventory';

    public const string CHECK_CODE = 'register_link_check';

    public const string ARCHIVE_CODE = 'register_link_archive';

    public const string REPAIR_CODE = 'register_link_repair';

    public static function targetJobId(int $targetId): string
    {
        if ($targetId < 1) {
            throw new \InvalidArgumentException('A link target identifier must be positive.');
        }

        return 'target-' . $targetId;
    }
}
