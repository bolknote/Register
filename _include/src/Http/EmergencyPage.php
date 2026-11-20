<?php
/**
 * @copyright 2026 Register contributors
 * @license https://opensource.org/license/mit MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Http;

/** Resolves error-page text from disk without accessing failed application services. */
final readonly class EmergencyPage
{
    public function __construct(private string $rootDirectory, private string $cacheDirectory)
    {
    }

    /** @return array<string, string> */
    public function text(): array
    {
        $english = require $this->rootDirectory . '/_lang/English/common.php';
        $text = \is_array($english) ? $english : [];
        $cacheFile = $this->cacheDirectory . '/register_config.php';
        try {
            $config = is_file($cacheFile) && !is_link($cacheFile)
                ? register_call_without_warnings(static fn(): mixed => include $cacheFile)
                : [];
            $language = \is_array($config) ? ($config['REGISTER_LANGUAGE'] ?? 'English') : 'English';
            if (\is_string($language) && preg_match('/^[a-zA-Z0-9_-]+$/D', $language) === 1) {
                $file = $this->rootDirectory . '/_lang/' . $language . '/common.php';
                if (is_file($file)) {
                    $translated = require $file;
                    if (\is_array($translated)) {
                        $text = array_replace($text, $translated);
                    }
                }
            }
        } catch (\Throwable) {
            // A missing or damaged settings cache must not break the error page.
        }

        $result = [];
        foreach (['locale', 'Emergency title', 'Emergency message', 'Emergency reload', 'Emergency home', 'Emergency reference'] as $key) {
            $result[$key] = \is_string($text[$key] ?? null) ? $text[$key] : $key;
        }

        return $result;
    }
}
