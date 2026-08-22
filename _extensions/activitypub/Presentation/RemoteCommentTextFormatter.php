<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Presentation;

/** Converts already-sanitized remote HTML to Register's bounded comment markup. */
final class RemoteCommentTextFormatter
{
    private const int MAX_BYTES = 65_535;

    public function format(string $html): string
    {
        if ($html === '' || \strlen($html) > 1_048_576 || !mb_check_encoding($html, 'UTF-8')) {
            throw new \InvalidArgumentException('Remote comment HTML is invalid or too large.');
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><body><div data-register-root="1">' . $html . '</div></body></html>',
                LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            throw new \InvalidArgumentException('Remote comment HTML cannot be parsed.');
        }

        $xpath = new \DOMXPath($document);
        $roots = $xpath->query('//*[@data-register-root="1"]');
        $root  = $roots === false ? null : $roots->item(0);
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('The remote comment formatter lost its document root.');
        }

        $text = '';
        foreach ($root->childNodes as $child) {
            $text .= $this->node($child);
        }

        $text = str_replace(["\r\n", "\r", "\u{00a0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('A remote comment cannot be empty after formatting.');
        }

        if (\strlen($text) > self::MAX_BYTES) {
            return rtrim(mb_strcut($text, 0, self::MAX_BYTES - 4, 'UTF-8')) . '…';
        }

        return $text;
    }

    private function node(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return str_replace(['[', ']'], ['［', '］'], $node->wholeText);
        }

        if (!$node instanceof \DOMElement) {
            return '';
        }

        $content = '';
        foreach ($node->childNodes as $child) {
            $content .= $this->node($child);
        }

        $name = strtolower($node->tagName);

        return match ($name) {
            'br' => "\n",
            'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre' => "\n" . trim($content) . "\n",
            'li' => "\n• " . trim($content),
            'blockquote' => "\n[Q]" . trim($content) . "[/Q]\n",
            'strong', 'b' => '[B]' . $content . '[/B]',
            'em', 'i' => '[I]' . $content . '[/I]',
            'a' => $this->link($node, $content),
            default => $content,
        };
    }

    private function link(\DOMElement $element, string $content): string
    {
        $href = trim($element->getAttribute('href'));
        $text = trim($content);
        if ($href === '') {
            return $content;
        }

        if ($text === '' || hash_equals($href, $text)) {
            return $href;
        }

        return $text . ' (' . $href . ')';
    }
}
