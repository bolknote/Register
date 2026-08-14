<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

/** Rewrites only matching href attributes and preserves every other source byte. */
final readonly class HtmlLinkRewriter
{
    private const array RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea', 'title'];

    public function __construct(private LinkUrlNormalizer $normalizer)
    {
    }

    public function rewrite(
        string $html,
        string $sourcePath,
        string $targetUrl,
        string $replacementUrl,
    ): HtmlLinkRewriteResult {
        if ($html === '') {
            return new HtmlLinkRewriteResult('', 0);
        }

        $result           = '';
        $replacementCount = 0;
        $offset            = 0;
        $length            = \strlen($html);
        while ($offset < $length) {
            $tagStart = strpos($html, '<', $offset);
            if ($tagStart === false) {
                $result .= substr($html, $offset);
                break;
            }

            $result .= substr($html, $offset, $tagStart - $offset);
            if (substr($html, $tagStart, 4) === '<!--') {
                $commentEnd = strpos($html, '-->', $tagStart + 4);
                if ($commentEnd === false) {
                    $result .= substr($html, $tagStart);
                    break;
                }

                $commentEnd += 3;
                $result     .= substr($html, $tagStart, $commentEnd - $tagStart);
                $offset      = $commentEnd;
                continue;
            }

            $tagEnd = $this->tagEnd($html, $tagStart);
            if ($tagEnd === null) {
                $result .= substr($html, $tagStart);
                break;
            }

            $tag     = substr($html, $tagStart, $tagEnd - $tagStart + 1);
            $tagName = $this->openingTagName($tag);
            if ($tagName === 'a') {
                $tag = $this->rewriteAnchorTag(
                    $tag,
                    $sourcePath,
                    $targetUrl,
                    $replacementUrl,
                    $replacementCount,
                );
            }

            $result .= $tag;
            $offset  = $tagEnd + 1;
            if ($tagName !== null && \in_array($tagName, self::RAW_TEXT_ELEMENTS, true)) {
                $closingStart = $this->rawTextClosingStart($html, $tagName, $offset);
                if ($closingStart === null) {
                    $result .= substr($html, $offset);
                    break;
                }

                $result .= substr($html, $offset, $closingStart - $offset);
                $offset  = $closingStart;
            }
        }

        return new HtmlLinkRewriteResult($result, $replacementCount);
    }

    private function tagEnd(string $html, int $tagStart): ?int
    {
        $quote  = null;
        $length = \strlen($html);
        for ($position = $tagStart + 1; $position < $length; ++$position) {
            $character = $html[$position];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '>') {
                return $position;
            }
        }

        return null;
    }

    private function openingTagName(string $tag): ?string
    {
        if (preg_match('/^<\s*([a-z][a-z0-9:-]*)\b/iD', $tag, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function rewriteAnchorTag(
        string $tag,
        string $sourcePath,
        string $targetUrl,
        string $replacementUrl,
        int    &$replacementCount,
    ): string {
        if (preg_match('/^<\s*a\b/iD', $tag, $matches) !== 1) {
            return $tag;
        }

        $length   = \strlen($tag);
        $position = \strlen($matches[0]);
        while ($position < $length) {
            while ($position < $length && ctype_space($tag[$position])) {
                ++$position;
            }

            if ($position >= $length || $tag[$position] === '>' || $tag[$position] === '/') {
                break;
            }

            $nameStart = $position;
            while ($position < $length
                && !ctype_space($tag[$position])
                && !\in_array($tag[$position], ['=', '>', '/'], true)
            ) {
                ++$position;
            }

            $name = strtolower(substr($tag, $nameStart, $position - $nameStart));
            while ($position < $length && ctype_space($tag[$position])) {
                ++$position;
            }

            if ($position >= $length || $tag[$position] !== '=') {
                continue;
            }

            ++$position;
            while ($position < $length && ctype_space($tag[$position])) {
                ++$position;
            }

            if ($position >= $length) {
                break;
            }

            $quote = '';
            if ($tag[$position] === '"' || $tag[$position] === "'") {
                $quote      = $tag[$position];
                $valueStart = ++$position;
                $valueEnd   = strpos($tag, $quote, $valueStart);
                if ($valueEnd === false) {
                    break;
                }

                $position = $valueEnd + 1;
            } else {
                $valueStart = $position;
                while ($position < $length && !ctype_space($tag[$position]) && $tag[$position] !== '>') {
                    ++$position;
                }

                $valueEnd = $position;
            }

            if ($name !== 'href') {
                continue;
            }

            $rawValue  = substr($tag, $valueStart, $valueEnd - $valueStart);
            $normalized = $this->normalizer->normalize($rawValue, $sourcePath);
            if (!$normalized instanceof NormalizedLink) {
                return $tag;
            }

            if ($normalized->kind !== LinkKind::EXTERNAL
                || $normalized->url !== $targetUrl
            ) {
                return $tag;
            }

            $replacement = $replacementUrl;
            if ($normalized->fragment !== '') {
                $replacement .= '#' . $normalized->fragment;
            }

            $escaped = htmlspecialchars($replacement, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            ++$replacementCount;

            return $quote === ''
                ? substr($tag, 0, $valueStart) . '"' . $escaped . '"' . substr($tag, $valueEnd)
                : substr($tag, 0, $valueStart) . $escaped . substr($tag, $valueEnd);
        }

        return $tag;
    }

    private function rawTextClosingStart(string $html, string $tagName, int $offset): ?int
    {
        $match = preg_match(
            '~</' . preg_quote($tagName, '~') . '(?=[\s/>])~i',
            $html,
            $matches,
            PREG_OFFSET_CAPTURE,
            $offset,
        );
        if ($match !== 1) {
            return null;
        }

        return $matches[0][1];
    }
}
