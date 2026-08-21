<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Http;

use Symfony\Component\HttpFoundation\Response;

/** Grants the current response nonce only to inline scripts in explicitly trusted post bodies. */
final readonly class TrustedScriptNonceInjector
{
    private const string START_MARKER = '<!--register-trusted-script-region:83e42a4f:start-->';

    private const string END_MARKER = '<!--register-trusted-script-region:83e42a4f:end-->';

    public static function markTrustedHtml(string $html): string
    {
        return self::START_MARKER . $html . self::END_MARKER;
    }

    /**
     * @return int Number of inline script elements granted the nonce.
     */
    public function injectIntoResponse(Response $response, string $nonce): int
    {
        $content = (string)$response->getContent();
        if (!str_contains($content, self::START_MARKER)) {
            return 0;
        }

        $contentType = strtolower((string)$response->headers->get('Content-Type'));
        if ($contentType !== '' && !str_starts_with($contentType, 'text/html')) {
            $response->setContent(str_replace([self::START_MARKER, self::END_MARKER], '', $content));

            return 0;
        }

        [$processedContent, $scriptCount] = $this->injectMarkedRegions($content, $nonce);
        $response->setContent($processedContent);

        return $scriptCount;
    }

    /**
     * @return array{string, int}
     */
    private function injectMarkedRegions(string $html, string $nonce): array
    {
        $result      = '';
        $offset      = 0;
        $scriptCount = 0;
        $startLength = \strlen(self::START_MARKER);
        $endLength   = \strlen(self::END_MARKER);

        while (($start = strpos($html, self::START_MARKER, $offset)) !== false) {
            $result      .= substr($html, $offset, $start - $offset);
            $regionStart  = $start + $startLength;
            $end          = strpos($html, self::END_MARKER, $regionStart);
            if ($end === false) {
                // Fail closed: remove the internal marker, but do not trust an unbounded region.
                $result .= substr($html, $regionStart);

                return [$result, $scriptCount];
            }

            [$region, $regionScriptCount] = $this->injectRegion(
                substr($html, $regionStart, $end - $regionStart),
                $nonce,
            );
            $result      .= $region;
            $scriptCount += $regionScriptCount;
            $offset       = $end + $endLength;
        }

        $result .= substr($html, $offset);

        return [$result, $scriptCount];
    }

    /**
     * @return array{string, int}
     */
    private function injectRegion(string $html, string $nonce): array
    {
        $result      = '';
        $offset      = 0;
        $scriptCount = 0;
        $length      = \strlen($html);

        while ($offset < $length) {
            $commentStart = stripos($html, '<!--', $offset);
            $scriptStart  = stripos($html, '<' . 'script', $offset);
            if ($scriptStart === false) {
                $result .= substr($html, $offset);
                break;
            }

            if ($commentStart !== false && $commentStart < $scriptStart) {
                $commentEnd = strpos($html, '-->', $commentStart + 4);
                if ($commentEnd === false) {
                    $result .= substr($html, $offset);
                    break;
                }

                $commentEnd += 3;
                $result     .= substr($html, $offset, $commentEnd - $offset);
                $offset      = $commentEnd;
                continue;
            }

            $nameEnd = $scriptStart + 7;
            if ($nameEnd < $length && !$this->isTagNameBoundary($html[$nameEnd])) {
                $result .= substr($html, $offset, $nameEnd - $offset);
                $offset  = $nameEnd;
                continue;
            }

            $tagEnd = $this->tagEnd($html, $nameEnd);
            if ($tagEnd === null) {
                $result .= substr($html, $offset);
                break;
            }

            $result .= substr($html, $offset, $scriptStart - $offset);
            $tag     = substr($html, $scriptStart, $tagEnd - $scriptStart + 1);
            [$tag, $nonceAdded] = $this->nonceOpeningTag($tag, $nonce);
            $result .= $tag;
            if ($nonceAdded) {
                ++$scriptCount;
            }

            $offset = $tagEnd + 1;

            // Script contents are raw text. Do not interpret tag-shaped strings inside them.
            $closingTag = stripos($html, '</script', $offset);
            if ($closingTag === false) {
                $result .= substr($html, $offset);
                break;
            }

            $closingTagEnd = strpos($html, '>', $closingTag + 8);
            if ($closingTagEnd === false) {
                $result .= substr($html, $offset);
                break;
            }

            $result .= substr($html, $offset, $closingTagEnd - $offset + 1);
            $offset  = $closingTagEnd + 1;
        }

        return [$result, $scriptCount];
    }

    private function isTagNameBoundary(string $character): bool
    {
        return ctype_space($character) || $character === '>' || $character === '/';
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

    /**
     * @return array{string, bool}
     */
    private function nonceOpeningTag(string $tag, string $nonce): array
    {
        $attributes = substr($tag, 7, -1);
        $attributes = preg_replace(
            '~\s+nonce\b(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?~iu',
            '',
            $attributes,
        );
        if ($attributes === null) {
            throw new \RuntimeException('Unable to normalize a trusted script tag.');
        }

        $opening = substr($tag, 0, 7);
        if (preg_match('~(?:^|\s)src(?:\s*=|\s|/|$)~iu', $attributes) === 1) {
            return [$opening . $attributes . '>', false];
        }

        $encodedNonce = htmlspecialchars($nonce, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return [$opening . ' nonce="' . $encodedNonce . '"' . $attributes . '>', true];
    }
}
