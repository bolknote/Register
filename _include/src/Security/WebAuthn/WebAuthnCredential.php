<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Security\WebAuthn;

use Webauthn\CredentialRecord;

final readonly class WebAuthnCredential
{
    public function __construct(
        public string           $hash,
        public int              $userId,
        public string           $name,
        public int              $createdAt,
        public ?int             $lastUsedAt,
        public CredentialRecord $record,
    ) {
    }
}
