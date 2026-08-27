<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Http;

/** Adds isolated legacy-content styles only to pages that render matching imported markup. */
final readonly class LegacyContentStylesheetInjector
{
    private const array IMPORTED_MARKERS = [
        'lj-',
        'readme-',
        'register-autolink',
        'register-import-style-d105615e03df6e3f0b3b',
    ];

    /** Posts whose former raw style blocks live in the scoped legacy-posts stylesheet. */
    private const array LEGACY_STYLE_POST_IDS = [484, 485, 486, 615, 3050, 3957, 6316, 6565];

    private string $rootDir;

    private string $basePath;

    public function __construct(string $rootDir, string $basePath)
    {
        $this->rootDir  = rtrim($rootDir, '/') . '/';
        $this->basePath = rtrim($basePath, '/');
    }

    public function inject(string $html, string $styleName): string
    {
        $headEnd = stripos($html, '</head>');
        if ($headEnd === false) {
            return $html;
        }

        $assets = [];
        if ($this->containsAny($html, self::IMPORTED_MARKERS)) {
            $assets[] = '/_assets/register/imported-content/site.css';

            if (preg_match('/\A[0-9A-Za-z_-]+\z/D', $styleName) === 1) {
                $themeAsset = '/_styles/' . $styleName . '/imported-content.css';
                if (is_file($this->rootDir . ltrim($themeAsset, '/'))) {
                    $assets[] = $themeAsset;
                }
            }
        }

        if (str_contains($html, 'files-archive-')) {
            $assets[] = '/_assets/register/files-archive/site.css';
        }

        foreach (self::LEGACY_STYLE_POST_IDS as $postId) {
            if (str_contains($html, 'data-post-id="' . $postId . '"')) {
                $assets[] = '/_assets/register/imported-content/legacy-posts.css';
                break;
            }
        }

        if ($assets === []) {
            return $html;
        }

        $links = [];
        foreach (array_values(array_unique($assets)) as $asset) {
            $href = $this->versionedHref($asset);
            if (str_contains($html, 'href="' . $href . '"')) {
                continue;
            }

            $links[] = '<link rel="stylesheet" href="'
                . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
        }

        if ($links === []) {
            return $html;
        }

        return substr($html, 0, $headEnd)
            . implode("\n", $links) . "\n"
            . substr($html, $headEnd);
    }

    /** @param list<string> $needles */
    private function containsAny(string $html, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($html, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function versionedHref(string $asset): string
    {
        $filename = $this->rootDir . ltrim($asset, '/');
        if (!is_file($filename)) {
            throw new \LogicException(sprintf('The legacy-content stylesheet "%s" does not exist.', $filename));
        }

        $modifiedAt = filemtime($filename);
        if ($modifiedAt === false) {
            throw new \LogicException(sprintf('Unable to read the modification time of "%s".', $filename));
        }

        return $this->basePath . $asset . '?v=' . $modifiedAt;
    }
}
