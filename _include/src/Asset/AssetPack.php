<?php /** @noinspection HtmlWrongAttributeValue */
/** @noinspection HtmlUnknownTarget */
/**
 * @copyright 2023-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Asset;

class AssetPack
{
    public const string COLOR_SCHEME_LIGHT = 'light';

    public const string COLOR_SCHEME_DARK = 'dark';

    public const string COLOR_SCHEME_SYSTEM = 'light dark';

    public const string OPTION_PRELOAD = 'preload';

    public const string OPTION_DEFER = 'defer';

    public const string OPTION_ASYNC = 'async';

    public const string OPTION_MERGE = 'merge';

    /**
     * @var string[]
     */
    private array $meta = [];

    /** @var list<array{src: string, merge: bool}> */
    private array $css = [];

    /** @var list<array{src: string, is_async: bool, is_defer: bool}> */
    private array $headJs = [];

    /**
     * @var string[]
     */
    private array $headInlineJs = [];

    /** @var list<array{src: string, is_async: bool, is_defer: bool, merge: bool}> */
    private array $js = [];

    /**
     * @var string[]
     */
    private array $inlineJs = [];

    /** @var list<array{src: string, 'as': string}> */
    private array $preload = [];

    private ?string $favIcon = null;

    private ?string $colorScheme = null;

    private ?int $colorSchemeMetaIndex = null;

    private readonly string $localDir;

    public function __construct(string $localDir)
    {
        $this->localDir = rtrim($localDir, '/');
    }

    /**
     * @param list<string> $options
     */
    public function addCss(string $filename, array $options = []): self
    {
        $o = array_flip($options);

        $merge = isset($o[self::OPTION_MERGE]);
        unset($o[self::OPTION_MERGE]);
        if (\count($o) > 0) {
            throw new \DomainException(\sprintf('Found unknown options [%s] for style "%s".', implode(', ', array_keys($o)), $filename));
        }

        $this->css[] = ['src' => $filename, 'merge' => $merge];

        return $this;
    }

    /**
     * @param list<string> $options
     */
    public function addJs(string $filename, array $options = []): self
    {
        $o = array_flip($options);

        $isPreload = isset($o[self::OPTION_PRELOAD]);
        $isAsync   = isset($o[self::OPTION_ASYNC]);
        $isDefer   = isset($o[self::OPTION_DEFER]);
        $merge     = isset($o[self::OPTION_MERGE]);
        if ($isAsync && $isDefer) {
            throw new \DomainException(\sprintf('Async and defer options cannot be used together for script "%s".', $filename));
        }

        unset($o[self::OPTION_MERGE], $o[self::OPTION_ASYNC], $o[self::OPTION_DEFER], $o[self::OPTION_PRELOAD]);
        if (\count($o) > 0) {
            throw new \DomainException(\sprintf('Found unknown options [%s] for script "%s".', implode(', ', array_keys($o)), $filename));
        }

        if ($isPreload) {
            $this->preload[] = ['src' => $filename, 'as' => 'script'];
        }

        $this->js[] = ['src' => $filename, 'is_async' => $isAsync, 'is_defer' => $isDefer, 'merge' => $merge];

        return $this;
    }


    public function addInlineJs(string $code): self
    {
        $this->inlineJs[] = $code;

        return $this;
    }

    public function addMeta(string $code): self
    {
        $this->meta[] = $code;

        return $this;
    }

    public function setColorScheme(string $colorScheme): self
    {
        if (!\in_array($colorScheme, [
            self::COLOR_SCHEME_LIGHT,
            self::COLOR_SCHEME_DARK,
            self::COLOR_SCHEME_SYSTEM,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported color scheme "' . $colorScheme . '".');
        }

        $this->colorScheme = $colorScheme;
        $meta = '<meta name="color-scheme" content="' . $colorScheme . '">';
        if ($this->colorSchemeMetaIndex === null) {
            $this->colorSchemeMetaIndex = \count($this->meta);
            $this->meta[] = $meta;
        } else {
            $this->meta[$this->colorSchemeMetaIndex] = $meta;
        }

        return $this;
    }

    public function getColorScheme(): string
    {
        return $this->colorScheme ?? self::COLOR_SCHEME_SYSTEM;
    }

    /**
     * @param list<string> $options
     */
    public function addHeadJs(string $filename, array $options = []): self
    {
        $o = array_flip($options);
        if (isset($o[self::OPTION_PRELOAD])) {
            throw new \DomainException(\sprintf(
                'JS script "%s" in head cannot be preloaded.',
                $filename
            ));
        }

        if (isset($o[self::OPTION_MERGE])) {
            throw new \DomainException(\sprintf(
                'JS script "%s" in head cannot be merged. Try to use addJs() method instead.',
                $filename
            ));
        }

        $isAsync = isset($o[self::OPTION_ASYNC]);
        $isDefer = isset($o[self::OPTION_DEFER]);
        if ($isAsync && $isDefer) {
            throw new \DomainException(\sprintf(
                'Async and defer options cannot be used together for script "%s".',
                $filename
            ));
        }

        unset($o[self::OPTION_ASYNC], $o[self::OPTION_DEFER]);
        if (\count($o) > 0) {
            throw new \DomainException(\sprintf(
                'Found unknown options [%s] for script "%s".',
                implode(', ', array_keys($o)),
                $filename
            ));
        }

        $this->headJs[] = ['src' => $filename, 'is_async' => $isAsync, 'is_defer' => $isDefer];

        return $this;
    }

    public function addHeadInlineJs(string $code): self
    {
        $this->headInlineJs[] = $code;

        return $this;
    }

    public function setFavIcon(?string $favicon): self
    {
        $this->favIcon = $favicon;

        return $this;
    }

    /**
     * Return styles (as long as meta tags and scripts) to be included in the head section.
     *
     * @param string                   $pathPrefix Path prefix to be prepended to local file names
     *
     */
    public function getStyles(string $pathPrefix, ?AssetMergeInterface $assetMerge): string
    {
        $result = array_values($this->meta);

        foreach ($this->preload as $preloadItem) {
            $preloadPath = $this->getPrefixedPath($preloadItem['src'], $pathPrefix);
            $result[]    = \sprintf('<link rel="preload" href="%s" as="%s">', $preloadPath, $preloadItem['as']);
        }

        foreach ($this->css as $cssItem) {
            if ($assetMerge instanceof \Register\Core\Asset\AssetMergeInterface && $cssItem['merge']) {
                $assetMerge->concat(($this->requireDirPrefix($cssItem['src']) ? $this->localDir . '/' : '') . $cssItem['src']);
            } else {
                $cssPath  = $this->getPrefixedPath($cssItem['src'], $pathPrefix);
                $result[] = \sprintf('<link rel="stylesheet" href="%s">', $cssPath);
            }
        }

        if ($assetMerge instanceof \Register\Core\Asset\AssetMergeInterface) {
            $mergedPaths = $assetMerge->getMergedPaths();
            foreach ($mergedPaths as $mergedPath) {
                $result[] = \sprintf('<link rel="stylesheet" href="%s" />', $mergedPath);
            }
        }

        foreach ($this->headJs as $jsItem) {
            $result[] = \sprintf(
            /** @lang text */ '<script src="%s"%s%s></script>',
                $this->getPrefixedPath($jsItem['src'], $pathPrefix),
                $jsItem['is_defer'] ? ' defer' : '',
                $jsItem['is_async'] ? ' async' : ''
            );
        }

        if ($this->favIcon !== null) {
            $result[] = '<link rel="shortcut icon" type="' . $this->getFaviconMimeType($this->favIcon) . '" href="' . $this->getPrefixedPath($this->favIcon, $pathPrefix) . '">';
        }

        $result = array_merge($result, $this->headInlineJs);

        return implode("\n", $result);
    }

    /**
     * Return scripts to be included in the body section.
     *
     * @param string                   $pathPrefix Path prefix to be prepended to local file names
     *
     */
    public function getScripts(string $pathPrefix, ?AssetMergeInterface $assetMerge): string
    {
        $result = [];
        foreach ($this->js as $jsItem) {
            if ($assetMerge instanceof \Register\Core\Asset\AssetMergeInterface && $jsItem['merge']) {
                $assetMerge->concat(($this->requireDirPrefix($jsItem['src']) ? $this->localDir . '/' : '') . $jsItem['src']);
            } else {
                $result[] = \sprintf(
                /** @lang text */ '<script src="%s"%s%s></script>',
                    $this->getPrefixedPath($jsItem['src'], $pathPrefix),
                    $jsItem['is_defer'] ? ' defer' : '',
                    $jsItem['is_async'] ? ' async' : ''
                );
            }
        }

        if ($assetMerge instanceof \Register\Core\Asset\AssetMergeInterface) {
            foreach ($assetMerge->getMergedPaths() as $mergedPath) {
                $result[] = \sprintf('<script src="%s" defer></script>', $mergedPath);
            }
        }

        $result = array_merge($result, $this->inlineJs);

        return implode("\n", $result);
    }

    private function getFaviconMimeType(string $filename): string
    {
        return match (pathinfo($filename, PATHINFO_EXTENSION)) {
            'ico' => 'image/vnd.microsoft.icon',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpg',
            'svg', 'svgz' => 'image/svg+xml',
            default => throw new \InvalidArgumentException('This file type is not allowed for a favicon image'),
        };
    }

    private function requireDirPrefix(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/') {
            return false;
        }

        return !str_starts_with($path, 'http://') && !str_starts_with($path, 'https://');
    }

    private function getPrefixedPath(string $path, string $dirPrefix): string
    {
        if ($this->requireDirPrefix($path)) {
            return $dirPrefix . $path;
        }

        return $path;
    }
}
