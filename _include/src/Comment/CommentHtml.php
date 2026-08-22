<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Comment;

use S2\Cms\Helper\StringHelper;

/**
 * Parses the deliberately small HTML dialect accepted from the comment editor.
 *
 * Older comments remain in their original BBCode/plain-text representation. New
 * rich comments carry a private prefix so rendering never has to guess whether
 * angle brackets are text or trusted, already-sanitized markup.
 */
final class CommentHtml
{
    private const string STORAGE_PREFIX = '<!--register-comment-html:v1-->';

    /** @var array<string, string> */
    private const array TAG_ALIASES = [
        'b'      => 'strong',
        'del'    => 's',
        'div'    => 'p',
        'h1'     => 'p',
        'h2'     => 'p',
        'h3'     => 'p',
        'h4'     => 'p',
        'h5'     => 'p',
        'h6'     => 'p',
        'i'      => 'em',
        'strike' => 's',
    ];

    /** @var array<string, true> */
    private const array ALLOWED_TAGS = [
        'a'          => true,
        'blockquote' => true,
        'br'         => true,
        'code'       => true,
        'em'         => true,
        'li'         => true,
        'ol'         => true,
        'p'          => true,
        'pre'        => true,
        's'          => true,
        'strong'     => true,
        'ul'         => true,
    ];

    /** @var array<string, true> */
    private const array DROPPED_TAGS = [
        'audio'    => true,
        'button'   => true,
        'canvas'   => true,
        'embed'    => true,
        'form'     => true,
        'iframe'   => true,
        'img'      => true,
        'input'    => true,
        'math'     => true,
        'object'   => true,
        'option'   => true,
        'script'   => true,
        'select'   => true,
        'source'   => true,
        'style'    => true,
        'svg'      => true,
        'template' => true,
        'textarea' => true,
        'track'    => true,
        'video'    => true,
    ];

    /** @var array<string, true> */
    private const array BLOCK_TAGS = [
        'blockquote' => true,
        'div'        => true,
        'h1'         => true,
        'h2'         => true,
        'h3'         => true,
        'h4'         => true,
        'h5'         => true,
        'h6'         => true,
        'li'         => true,
        'ol'         => true,
        'p'          => true,
        'pre'        => true,
        'ul'         => true,
    ];

    public static function sanitizeForStorage(string $input): string
    {
        if (str_starts_with($input, self::STORAGE_PREFIX)) {
            $input = substr($input, \strlen(self::STORAGE_PREFIX));
        }

        $html = self::sanitizeFragment($input);
        if (self::plainTextFromHtml($html, false) === '') {
            return '';
        }

        return self::STORAGE_PREFIX . $html;
    }

    public static function render(string $stored, string $wroteText): string
    {
        if (!str_starts_with($stored, self::STORAGE_PREFIX)) {
            return StringHelper::bbcodeToHtml(s2_htmlencode($stored), $wroteText);
        }

        return self::sanitizeFragment(substr($stored, \strlen(self::STORAGE_PREFIX)));
    }

    public static function editorHtml(string $stored, string $wroteText): string
    {
        return self::render($stored, $wroteText);
    }

    public static function plainText(string $stored, bool $includeLinkTargets = true): string
    {
        if (!str_starts_with($stored, self::STORAGE_PREFIX)) {
            return trim(html_entity_decode(
                StringHelper::bbcodeToMail($stored),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ));
        }

        $html = self::sanitizeFragment(substr($stored, \strlen(self::STORAGE_PREFIX)));

        return self::plainTextFromHtml($html, $includeLinkTargets);
    }

    private static function sanitizeFragment(string $input): string
    {
        $body = self::parseFragment($input);
        if (!$body instanceof \DOMElement) {
            return '';
        }

        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= self::sanitizeNode($child);
        }

        return trim($html);
    }

    private static function sanitizeNode(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars(
                $node->nodeValue ?? '',
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            );
        }

        if (!$node instanceof \DOMElement) {
            return '';
        }

        $sourceTag = mb_strtolower($node->tagName);
        if (isset(self::DROPPED_TAGS[$sourceTag])) {
            return '';
        }

        $children = '';
        foreach ($node->childNodes as $child) {
            $children .= self::sanitizeNode($child);
        }

        if ($sourceTag === 'span') {
            return self::applySafeSpanStyle($children, $node->getAttribute('style'));
        }

        $tag = self::TAG_ALIASES[$sourceTag] ?? $sourceTag;
        if (!isset(self::ALLOWED_TAGS[$tag])) {
            return $children;
        }

        if ($tag === 'br') {
            return '<br>';
        }

        if ($tag === 'a') {
            $href = self::safeHref($node->getAttribute('href'));
            if ($href === null) {
                return $children;
            }

            return '<a href="' . self::encodeAttribute($href) . '" rel="nofollow ugc">'
                . $children . '</a>';
        }

        return '<' . $tag . '>' . $children . '</' . $tag . '>';
    }

    private static function applySafeSpanStyle(string $children, string $style): string
    {
        $normalized = mb_strtolower($style);
        if (preg_match('/(?:^|;)\s*font-weight\s*:\s*(?:bold|[6-9]00)\b/u', $normalized) === 1) {
            $children = '<strong>' . $children . '</strong>';
        }
        if (preg_match('/(?:^|;)\s*font-style\s*:\s*italic\b/u', $normalized) === 1) {
            $children = '<em>' . $children . '</em>';
        }
        if (preg_match('/(?:^|;)\s*text-decoration(?:-line)?\s*:[^;]*\bline-through\b/u', $normalized) === 1) {
            $children = '<s>' . $children . '</s>';
        }

        return $children;
    }

    private static function safeHref(string $href): ?string
    {
        $href = trim($href);
        if (
            $href === ''
            || str_starts_with($href, '//')
            || str_contains($href, '\\')
            || preg_match('/[\x00-\x20\x7f]/u', $href) === 1
        ) {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/iu', $href, $matches) === 1) {
            $scheme = mb_strtolower($matches[1]);
            if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
                return null;
            }
        }

        return $href;
    }

    private static function encodeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function plainTextFromHtml(string $html, bool $includeLinkTargets): string
    {
        $body = self::parseFragment($html);
        if (!$body instanceof \DOMElement) {
            return '';
        }

        $text = '';
        foreach ($body->childNodes as $child) {
            $text .= self::nodeText($child, $includeLinkTargets);
        }

        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n[ \t]+/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private static function nodeText(\DOMNode $node, bool $includeLinkTargets): string
    {
        if ($node instanceof \DOMText) {
            return $node->nodeValue ?? '';
        }

        if (!$node instanceof \DOMElement) {
            return '';
        }

        $tag = mb_strtolower($node->tagName);
        if ($tag === 'br') {
            return "\n";
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::nodeText($child, $includeLinkTargets);
        }

        if ($tag === 'a' && $includeLinkTargets) {
            $href = self::safeHref($node->getAttribute('href'));
            if ($href !== null && trim($text) !== $href) {
                $text .= ' (' . $href . ')';
            }
        }

        if ($tag === 'li') {
            return '- ' . trim($text) . "\n";
        }

        return isset(self::BLOCK_TAGS[$tag]) ? $text . "\n" : $text;
    }

    private static function parseFragment(string $html): ?\DOMElement
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            return null;
        }

        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof \DOMElement ? $body : null;
    }
}
