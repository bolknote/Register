<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final class UpdateDirectoryResolver
{
    public static function resolve(string $applicationRoot): string
    {
        $applicationRoot = rtrim($applicationRoot, '/\\');
        if ($applicationRoot === '') {
            throw new \InvalidArgumentException('The Register application root cannot be empty.');
        }

        $realRoot = realpath($applicationRoot);
        $identity = str_replace('\\', '/', $realRoot === false ? $applicationRoot : $realRoot);

        return dirname($applicationRoot) . '/register-updates-' . substr(hash('sha256', $identity), 0, 12);
    }
}
