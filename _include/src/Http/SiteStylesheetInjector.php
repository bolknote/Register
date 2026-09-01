<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http;

/** Adds site-owned stylesheets without teaching the engine about a site's content or migrations. */
final readonly class SiteStylesheetInjector
{
    /** @var list<array{href: string, markers: list<string>, themes: list<string>}> */
    private array $stylesheets;

    private string $rootDir;

    private string $basePath;

    /** @param array<array-key, mixed> $stylesheets */
    public function __construct(string $rootDir, string $basePath, array $stylesheets)
    {
        $this->rootDir    = rtrim($rootDir, '/') . '/';
        $this->basePath   = rtrim($basePath, '/');
        $this->stylesheets = $this->normalizeStylesheets($stylesheets);
    }

    public function inject(string $html, string $theme): string
    {
        $headEnd = stripos($html, '</head>');
        if ($headEnd === false || $this->stylesheets === []) {
            return $html;
        }

        $links = [];
        foreach ($this->stylesheets as $stylesheet) {
            if (
                ($stylesheet['themes'] !== [] && !in_array($theme, $stylesheet['themes'], true))
                || !$this->containsAny($html, $stylesheet['markers'])
            ) {
                continue;
            }

            $href = $this->versionedHref($stylesheet['href']);
            if (isset($links[$href]) || str_contains($html, 'href="' . $href . '"')) {
                continue;
            }

            $links[$href] = '<link rel="stylesheet" href="'
                . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        }

        if ($links === []) {
            return $html;
        }

        return substr($html, 0, $headEnd)
            . implode("\n", $links) . "\n"
            . substr($html, $headEnd);
    }

    /**
     * @param array<array-key, mixed> $stylesheets
     * @return list<array{href: string, markers: list<string>, themes: list<string>}>
     */
    private function normalizeStylesheets(array $stylesheets): array
    {
        $result = [];

        foreach ($stylesheets as $index => $stylesheet) {
            if (is_string($stylesheet)) {
                $stylesheet = ['href' => $stylesheet];
            }

            if (!is_array($stylesheet)) {
                throw new \InvalidArgumentException(sprintf(
                    'Site stylesheet %s must be a path or an array.',
                    (string)$index,
                ));
            }

            $unknownKeys = array_diff(array_keys($stylesheet), ['href', 'markers', 'themes']);
            if ($unknownKeys !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'Site stylesheet %s contains an unknown option: %s.',
                    (string)$index,
                    (string)reset($unknownKeys),
                ));
            }

            $href = $stylesheet['href'] ?? null;
            if (!is_string($href)) {
                throw new \InvalidArgumentException(sprintf(
                    'Site stylesheet %s has no string href.',
                    (string)$index,
                ));
            }

            $href = trim($href);
            $this->assertSafeHref($href);

            $result[] = [
                'href'    => $href,
                'markers' => $this->stringList($stylesheet['markers'] ?? [], 'markers', (string)$index),
                'themes'  => $this->themeList($stylesheet['themes'] ?? [], (string)$index),
            ];
        }

        return $result;
    }

    private function assertSafeHref(string $href): void
    {
        $segments = explode('/', ltrim($href, '/'));
        if (
            $href === ''
            || !str_starts_with($href, '/')
            || str_starts_with($href, '//')
            || !str_ends_with(strtolower($href), '.css')
            || str_contains($href, '\\')
            || str_contains($href, '?')
            || str_contains($href, '#')
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || preg_match('~[\x00-\x1f\x7f]~', $href) === 1
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Site stylesheet href "%s" must be a safe root-relative CSS path.',
                $href,
            ));
        }
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $option, string $index): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Site stylesheet %s option "%s" must be an array.',
                $index,
                $option,
            ));
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || preg_match('~[\x00-\x1f\x7f]~', $item) === 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Site stylesheet %s option "%s" contains an invalid value.',
                    $index,
                    $option,
                ));
            }

            $result[] = $item;
        }

        return array_values(array_unique($result));
    }

    /** @return list<string> */
    private function themeList(mixed $value, string $index): array
    {
        $themes = $this->stringList($value, 'themes', $index);
        foreach ($themes as $theme) {
            if (preg_match('/\A[0-9A-Za-z_-]+\z/D', $theme) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Site stylesheet %s contains an invalid theme name.',
                    $index,
                ));
            }
        }

        return $themes;
    }

    /** @param list<string> $markers */
    private function containsAny(string $html, array $markers): bool
    {
        if ($markers === []) {
            return true;
        }

        foreach ($markers as $marker) {
            if (str_contains($html, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function versionedHref(string $href): string
    {
        $filename = $this->rootDir . ltrim($href, '/');
        if (!is_file($filename)) {
            throw new \LogicException(sprintf('The configured site stylesheet "%s" does not exist.', $filename));
        }

        $modifiedAt = filemtime($filename);
        if ($modifiedAt === false) {
            throw new \LogicException(sprintf('Unable to read the modification time of "%s".', $filename));
        }

        return $this->basePath . $href . '?v=' . $modifiedAt;
    }
}
