<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Register\Ai\AiSettings;
use Register\Auth\PublicAuthSettings;
use Register\Module\VisitorIdentity\Manifest as VisitorIdentityManifest;
use Register\Core\Config\DynamicSecretProviderInterface;

final readonly class ProductDynamicSecretProvider implements DynamicSecretProviderInterface
{
    /** @return list<string> */
    #[\Override]
    public function managedNames(): array
    {
        return [
            AiSettings::API_KEY_CONFIG_KEY,
            PublicAuthSettings::YANDEX_CLIENT_SECRET_CONFIG_KEY,
            VisitorIdentityManifest::SECRET_CONFIG_KEY,
        ];
    }
}
