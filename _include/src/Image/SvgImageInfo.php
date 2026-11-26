<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Image;

/** Reads SVG geometry without rendering, fetching dependencies, or rewriting author content. */
final class SvgImageInfo
{
    /** @return array{0: int, 1: int, mime: string}|null */
    public static function read(string $path): ?array
    {
        $contents = register_call_without_warnings(static fn(): string|false => file_get_contents($path));
        if (!\is_string($contents) || $contents === '') {
            return null;
        }

        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        if (!register_call_without_warnings(static fn(): bool => $document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT))) {
            return null;
        }

        $root = $document->documentElement;
        if ($root === null || $root->localName !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg') {
            return null;
        }

        $viewBox = null;
        if ($root->hasAttribute('viewBox')) {
            $values = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')));
            if (!\is_array($values) || \count($values) !== 4) {
                return null;
            }

            foreach ($values as $value) {
                if (!is_numeric($value) || !is_finite((float)$value)) {
                    return null;
                }
            }

            $viewBox = array_map(floatval(...), $values);
            if ($viewBox[2] <= 0 || $viewBox[3] <= 0) {
                return null;
            }
        }

        $width = self::length($root->getAttribute('width'));
        $height = self::length($root->getAttribute('height'));
        if (($width !== null && $width <= 0) || ($height !== null && $height <= 0)) {
            return null;
        }

        if ($viewBox !== null) {
            if ($width === null && $height === null) {
                [$width, $height] = [$viewBox[2], $viewBox[3]];
            } elseif ($width === null) {
                $width = $height * $viewBox[2] / $viewBox[3];
            } elseif ($height === null) {
                $height = $width * $viewBox[3] / $viewBox[2];
            }
        }

        $width ??= 300.0;
        $height ??= 150.0;
        if (!is_finite($width) || !is_finite($height) || $width >= PHP_INT_MAX || $height >= PHP_INT_MAX) {
            return null;
        }

        return [max(1, (int)round($width)), max(1, (int)round($height)), 'mime' => 'image/svg+xml'];
    }

    private static function length(string $value): ?float
    {
        if (preg_match('/^([+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?)(px|in|cm|mm|q|pt|pc)?$/iD', trim($value), $match) !== 1) {
            return null;
        }

        return (float)$match[1] * match (strtolower($match[2] ?? '')) {
            'in' => 96.0,
            'cm' => 96.0 / 2.54,
            'mm' => 96.0 / 25.4,
            'q' => 96.0 / 101.6,
            'pt' => 96.0 / 72.0,
            'pc' => 16.0,
            default => 1.0,
        };
    }
}
