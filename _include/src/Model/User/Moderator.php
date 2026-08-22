<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Model\User;

readonly class Moderator
{
    public function __construct(
        public string $login,
        public string $email,
    ) {
    }
}
