<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Security\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/** Central fail-closed method and CSRF validation for administration mutations. */
final class AdminMutationGuard
{
    public function isPost(Request $request): bool
    {
        return $request->getRealMethod() === Request::METHOD_POST;
    }

    public function hasValidCsrfToken(
        Request $request,
        string $expectedToken,
        string $parameter = 'csrf_token',
    ): bool {
        if (preg_match('/^_{0,2}[a-z][a-z0-9_]{0,63}$/Di', $parameter) !== 1) {
            throw new \InvalidArgumentException('Invalid CSRF parameter name.');
        }

        try {
            $candidate = $request->request->get($parameter);
        } catch (BadRequestException) {
            return false;
        }

        return \is_string($candidate) && self::tokensMatch($expectedToken, $candidate);
    }

    public static function tokensMatch(string $expectedToken, string $candidate): bool
    {
        return $expectedToken !== ''
            && $candidate !== ''
            && hash_equals($expectedToken, $candidate);
    }
}
