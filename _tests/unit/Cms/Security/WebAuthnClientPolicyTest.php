<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Security;

use Codeception\Test\Unit;

final class WebAuthnClientPolicyTest extends Unit
{
    public function testPasskeyLoginIsOnlyRevealedInSupportedSecureContexts(): void
    {
        $root     = dirname(__DIR__, 4);
        $template = file_get_contents($root . '/_admin/templates/login.php.inc');
        $script   = file_get_contents($root . '/_admin/js/webauthn.js');

        self::assertIsString($template);
        self::assertIsString($script);
        self::assertMatchesRegularExpression(
            '~<button\b(?=[^>]*\bdata-webauthn-login\b)(?=[^>]*\bhidden\b)[^>]*>~',
            $template,
        );
        self::assertStringContainsString("webauthn.js?v=<?= \$adminAssetVersion('_admin/js/webauthn.js') ?>", $template);
        self::assertStringContainsString("window.location.protocol === 'https:'", $script);
        self::assertStringContainsString("window.location.hostname === 'localhost'", $script);
        self::assertStringContainsString('window.isSecureContext === true', $script);
        self::assertStringContainsString("typeof navigator.credentials?.get === 'function'", $script);
        self::assertMatchesRegularExpression(
            '~if \(loginButton && passkeyLoginSupported\) \{\s*loginButton\.hidden = false;~',
            $script,
        );
    }
}
