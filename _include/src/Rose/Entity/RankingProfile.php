<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

namespace Register\Rose\Entity;

enum RankingProfile: string
{
    case Coverage = 'coverage';
    case Rarity = 'rarity';
}
