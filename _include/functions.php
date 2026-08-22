<?php

declare(strict_types = 1);

/**
 * Loads common functions used throughout the site.
 *
 * @copyright 2009-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

/**
 * Encodes the contents of $str so that they are safe to output on an HTML page
 */
function register_htmlencode(mixed $str): string
{
    if (!\is_scalar($str) && $str !== null && !$str instanceof \Stringable) {
        throw new \InvalidArgumentException('Only scalar or stringable values can be HTML-encoded.');
    }

    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * Executes a warning-prone boundary operation without leaking PHP warnings to the response.
 *
 * The warning text is exposed to callers that need to turn it into a domain exception.
 *
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function register_call_without_warnings(callable $callback, ?string &$warningMessage = null): mixed
{
    set_error_handler(
        static function (int $_severity, string $message) use (&$warningMessage): bool {
            $warningMessage = $message;
            return true;
        },
        E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED
    );

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

/**
 * @throws \RuntimeException
 */
function register_overwrite_file_skip_locked(string $filename, string $content): void
{
    $dir = dirname($filename);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException(sprintf('Cannot create directory "%s".', $dir));
    }

    $fh = register_call_without_warnings(static fn() => fopen($filename, 'a+b'));

    if ($fh === false) {
        // Try to remove the file if it's not writable
        register_call_without_warnings(static fn(): bool => unlink($filename));
        $fh = register_call_without_warnings(static fn() => fopen($filename, 'a+b'));
    }

    if ($fh === false) {
        throw new RuntimeException(sprintf('Cannot open file "%s" for write.', $filename));
    }

    register_call_without_warnings(static fn(): bool => chmod($filename, 0600));

    if (flock($fh, LOCK_EX | LOCK_NB)) {
        ftruncate($fh, 0);
        fwrite($fh, $content);
        fflush($fh);
        flock($fh, LOCK_UN);
    }

    fclose($fh);
}

/**
 * Check APP_NAME env variable for test purposes.
 *
 * @see https://gist.github.com/samdark/01279afbce4871bd02b556bbb7ca4790 for details of getenv() / $_ENV
 */
function register_get_config_filename(): string
{
    $appEnv = getenv('APP_ENV');
    if (is_string($appEnv) && $appEnv !== '') {
        return sprintf('config.%s.php', $appEnv);
    }

    return 'config.php';
}

function register_get_default_cache_dir(): string
{
    $appEnv   = getenv('APP_ENV');
    $cacheDir = dirname(__DIR__) . '/_cache/';
    if (is_string($appEnv) && $appEnv !== '') {
        return $cacheDir . $appEnv . '/';
    }

    return $cacheDir;
}
