<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Http;

/** Removes style attributes without reserializing or otherwise normalizing the HTML document. */
final readonly class InlineStyleAttributeStripper
{
    public function strip(string $html): string
    {
        if (stripos($html, 'style') === false || !str_contains($html, '<')) {
            return $html;
        }

        $result = '';
        $offset = 0;
        $length = \strlen($html);

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

            if (substr($html, $tagStart, 9) === '<![CDATA[') {
                $cdataEnd = strpos($html, ']]>', $tagStart + 9);
                if ($cdataEnd === false) {
                    $result .= substr($html, $tagStart);
                    break;
                }

                $cdataEnd += 3;
                $result   .= substr($html, $tagStart, $cdataEnd - $tagStart);
                $offset    = $cdataEnd;
                continue;
            }

            $tagEnd = $this->tagEnd($html, $tagStart + 1);
            if ($tagEnd === null) {
                $result .= substr($html, $tagStart);
                break;
            }

            $tag     = substr($html, $tagStart, $tagEnd - $tagStart + 1);
            $result .= $this->stripTag($tag);
            $offset  = $tagEnd + 1;

            $rawTextElement = $this->rawTextElement($tag);
            if ($rawTextElement !== null) {
                $closingTag = stripos($html, '</' . $rawTextElement, $offset);
                if ($closingTag === false) {
                    $result .= substr($html, $offset);
                    break;
                }

                $result .= substr($html, $offset, $closingTag - $offset);
                $offset  = $closingTag;
            }
        }

        return $result;
    }

    private function tagEnd(string $html, int $offset): ?int
    {
        $quote  = null;
        $length = \strlen($html);

        for ($position = $offset; $position < $length; ++$position) {
            $character = $html[$position];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }

            if ($character === '>') {
                return $position;
            }
        }

        return null;
    }

    private function stripTag(string $tag): string
    {
        $length   = \strlen($tag);
        $position = 1;

        if ($length < 3 || $tag[$position] === '/' || $tag[$position] === '!' || $tag[$position] === '?') {
            return $tag;
        }

        while ($position < $length && !ctype_space($tag[$position]) && $tag[$position] !== '>' && $tag[$position] !== '/') {
            ++$position;
        }

        $result = substr($tag, 0, $position);
        while ($position < $length) {
            $whitespaceStart = $position;
            while ($position < $length && ctype_space($tag[$position])) {
                ++$position;
            }

            if ($position >= $length || $tag[$position] === '>' || $tag[$position] === '/') {
                $result .= substr($tag, $whitespaceStart);
                break;
            }

            $nameStart = $position;
            while (
                $position < $length
                && !ctype_space($tag[$position])
                && $tag[$position] !== '='
                && $tag[$position] !== '>'
                && $tag[$position] !== '/'
            ) {
                ++$position;
            }

            $attributeName = substr($tag, $nameStart, $position - $nameStart);

            $attributeNameEnd = $position;
            $equalsPosition   = $position;
            while ($equalsPosition < $length && ctype_space($tag[$equalsPosition])) {
                ++$equalsPosition;
            }

            if ($equalsPosition < $length && $tag[$equalsPosition] === '=') {
                $position = $equalsPosition + 1;
                while ($position < $length && ctype_space($tag[$position])) {
                    ++$position;
                }

                if ($position < $length && ($tag[$position] === '"' || $tag[$position] === "'")) {
                    $quote = $tag[$position++];
                    while ($position < $length && $tag[$position] !== $quote) {
                        ++$position;
                    }

                    if ($position < $length) {
                        ++$position;
                    }
                } else {
                    while ($position < $length && !ctype_space($tag[$position]) && $tag[$position] !== '>') {
                        ++$position;
                    }
                }
            } else {
                $position = $attributeNameEnd;
            }

            if (strcasecmp($attributeName, 'style') !== 0) {
                $result .= substr($tag, $whitespaceStart, $position - $whitespaceStart);
            }
        }

        return $result;
    }

    private function rawTextElement(string $tag): ?string
    {
        if (preg_match('~^<\s*(script|style|textarea|title)\b[^>]*>~iD', $tag, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }
}
