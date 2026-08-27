<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Http;

use Symfony\Component\HttpFoundation\Response;

/** Grants a nonce to trusted post scripts and converter-owned style blocks. */
final readonly class TrustedScriptNonceInjector
{
    private const string START_MARKER = '<!--register-trusted-script-region:83e42a4f:start-->';

    private const string END_MARKER = '<!--register-trusted-script-region:83e42a4f:end-->';

    public static function markTrustedHtml(string $html): string
    {
        return self::START_MARKER . $html . self::END_MARKER;
    }

    /**
     * @return int Number of inline script and style elements granted the nonce.
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
            $scriptStart = stripos($html, '<' . 'script', $offset);
            $styleStart  = stripos($html, '<' . 'style', $offset);
            $element = $this->firstInlineElement($scriptStart, $styleStart);
            if ($element === null) {
                $result .= substr($html, $offset);
                break;
            }

            $elementName  = $element['name'];
            $elementStart = $element['start'];

            if ($commentStart !== false && $commentStart < $elementStart) {
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

            $nameEnd = $elementStart + 1 + strlen($elementName);
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

            $result .= substr($html, $offset, $elementStart - $offset);
            $tag     = substr($html, $elementStart, $tagEnd - $elementStart + 1);
            $offset  = $tagEnd + 1;

            if ($elementName === 'style' && !$this->isImportedInlineStyle($tag)) {
                // Historical raw CSS is externalized and scoped by article id. Never allow a
                // stored style block to re-enter the global cascade merely because its post is
                // otherwise trusted to run reviewed scripts.
                $closingTag = stripos($html, '</style', $offset);
                if ($closingTag === false) {
                    break;
                }

                $closingTagEnd = strpos($html, '>', $closingTag + 7);
                if ($closingTagEnd === false) {
                    break;
                }

                $offset = $closingTagEnd + 1;
                continue;
            }

            [$tag, $nonceAdded] = $this->nonceOpeningTag($tag, $nonce, $elementName);
            $result .= $tag;
            if ($nonceAdded) {
                ++$scriptCount;
            }

            // Script and style contents are raw text. Do not interpret tag-shaped strings inside them.
            $closingTag = stripos($html, '</' . $elementName, $offset);
            if ($closingTag === false) {
                $result .= substr($html, $offset);
                break;
            }

            $closingTagEnd = strpos($html, '>', $closingTag + 2 + strlen($elementName));
            if ($closingTagEnd === false) {
                $result .= substr($html, $offset);
                break;
            }

            $result .= substr($html, $offset, $closingTagEnd - $offset + 1);
            $offset  = $closingTagEnd + 1;
        }

        return [$result, $scriptCount];
    }

    private function isImportedInlineStyle(string $tag): bool
    {
        $attributes = substr($tag, 6, -1);

        return preg_match(
            '~(?:^|\s)data-register-imported-inline-styles'
                . '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?(?=\s|/|$)~iu',
            $attributes,
        ) === 1;
    }

    /**
     * @return null|array{name: 'script'|'style', start: int}
     */
    private function firstInlineElement(int|false $scriptStart, int|false $styleStart): ?array
    {
        if ($scriptStart === false) {
            return $styleStart === false ? null : ['name' => 'style', 'start' => $styleStart];
        }

        if ($styleStart === false || $scriptStart <= $styleStart) {
            return ['name' => 'script', 'start' => $scriptStart];
        }

        return ['name' => 'style', 'start' => $styleStart];
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
    private function nonceOpeningTag(string $tag, string $nonce, string $elementName): array
    {
        $openingLength = 1 + strlen($elementName);
        $attributes = substr($tag, $openingLength, -1);
        $attributes = preg_replace(
            '~\s+nonce\b(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?~iu',
            '',
            $attributes,
        );
        if ($attributes === null) {
            throw new \RuntimeException('Unable to normalize a trusted inline tag.');
        }

        $opening = substr($tag, 0, $openingLength);
        if ($elementName === 'script'
            && preg_match('~(?:^|\s)src(?:\s*=|\s|/|$)~iu', $attributes) === 1
        ) {
            return [$opening . $attributes . '>', false];
        }

        $encodedNonce = htmlspecialchars($nonce, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return [$opening . ' nonce="' . $encodedNonce . '"' . $attributes . '>', true];
    }
}
