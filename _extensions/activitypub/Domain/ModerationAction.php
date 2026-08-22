<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Domain;

enum ModerationAction: string
{
    case MODERATE = 'moderate';

    case TRUST = 'trust';

    case SILENCE = 'silence';

    case BLOCK = 'block';
}
