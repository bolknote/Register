<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Model;

final readonly class AuthenticatedPublicUser
{
    public function __construct(
        public int    $id,
        public string $login,
        public string $email,
        public string $name,
        public bool   $canHideComments,
        public bool   $canEditComments,
        public bool   $canCreateArticles,
        public bool   $canEditSite,
        public bool   $isAdministrator,
        public string $sessionHash,
    ) {
    }

    public function displayName(): string
    {
        $name = trim($this->name);

        return $name !== '' ? $name : $this->login;
    }

    public function commentName(): string
    {
        return mb_substr($this->displayName(), 0, 50);
    }
}
