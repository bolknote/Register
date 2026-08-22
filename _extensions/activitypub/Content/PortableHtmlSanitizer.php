<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Content;

use Register\Core\HttpClient\HttpClientException;
use Register\Core\HttpClient\HttpClientInterface;

/** Produces a bounded, script-free, absolute-URL HTML subset suitable for federation. */
final readonly class PortableHtmlSanitizer
{
    private const int MAX_INPUT_BYTES = 1_048_576;

    private const array ALLOWED_ELEMENTS = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'code', 'del', 'em',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'i', 'li', 'ol', 'p',
        'pre', 's', 'span', 'strong', 'sub', 'sup', 'u', 'ul',
    ];

    private const array DROP_WITH_CONTENT = [
        'applet', 'audio', 'canvas', 'embed', 'form', 'iframe', 'math', 'noscript',
        'object', 'script', 'style', 'svg', 'template', 'video',
    ];

    public function __construct(private HttpClientInterface $urlResolver)
    {
    }

    public function sanitize(string $html, string $baseUrl): string
    {
        if (\strlen($html) > self::MAX_INPUT_BYTES || !mb_check_encoding($html, 'UTF-8')) {
            throw new \InvalidArgumentException('Federated HTML must be valid UTF-8 and at most 1 MiB.');
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
            throw new \InvalidArgumentException('Federated HTML cannot be parsed.');
        }

        $xpath = new \DOMXPath($document);
        $roots = $xpath->query('//*[@data-register-root="1"]');
        $root  = $roots === false ? null : $roots->item(0);
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('The portable HTML sanitizer lost its document root.');
        }

        foreach ($this->children($root) as $child) {
            $this->sanitizeNode($child, $baseUrl);
        }

        $output = '';
        foreach ($this->children($root) as $child) {
            $serialized = $document->saveHTML($child);
            if ($serialized === false) {
                throw new \RuntimeException('Unable to serialize portable federated HTML.');
            }

            $output .= $serialized;
        }

        return $output;
    }

    private function sanitizeNode(\DOMNode $node, string $baseUrl): void
    {
        if ($node instanceof \DOMComment || $node instanceof \DOMProcessingInstruction) {
            $node->parentNode?->removeChild($node);
            return;
        }

        if (!$node instanceof \DOMElement) {
            return;
        }

        $name = strtolower($node->tagName);
        if (\in_array($name, self::DROP_WITH_CONTENT, true)) {
            $node->parentNode?->removeChild($node);
            return;
        }

        foreach ($this->children($node) as $child) {
            $this->sanitizeNode($child, $baseUrl);
        }

        if (!\in_array($name, self::ALLOWED_ELEMENTS, true)) {
            $this->unwrap($node);
            return;
        }

        foreach ($this->attributes($node) as $attribute) {
            $attributeName = strtolower($attribute->name);
            if ($this->isAllowedAttribute($name, $attributeName)) {
                continue;
            }

            $node->removeAttributeNode($attribute);
        }

        if ($name === 'a') {
            $href = $this->safeAbsoluteUrl($node->getAttribute('href'), $baseUrl);
            if ($href === null) {
                $node->removeAttribute('href');
            } else {
                $node->setAttribute('href', $href);
                $rel = preg_split('/\s+/', strtolower($node->getAttribute('rel')), -1, PREG_SPLIT_NO_EMPTY);
                $rel = \is_array($rel) ? $rel : [];
                $rel = array_values(array_unique(array_intersect(
                    [...$rel, 'noopener', 'noreferrer'],
                    ['me', 'tag', 'nofollow', 'ugc', 'noopener', 'noreferrer'],
                )));
                $node->setAttribute('rel', implode(' ', $rel));
            }
        }

        if ($node->hasAttribute('dir') && !\in_array(strtolower($node->getAttribute('dir')), ['ltr', 'rtl', 'auto'], true)) {
            $node->removeAttribute('dir');
        }

        if ($node->hasAttribute('class')) {
            $classes = preg_split('/\s+/', $node->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY);
            $classes = \is_array($classes) ? $classes : [];
            $classes = array_values(array_intersect($classes, ['mention', 'hashtag', 'invisible', 'ellipsis']));
            if ($classes === []) {
                $node->removeAttribute('class');
            } else {
                $node->setAttribute('class', implode(' ', array_unique($classes)));
            }
        }
    }

    private function isAllowedAttribute(string $element, string $attribute): bool
    {
        if (\in_array($attribute, ['lang', 'dir'], true)) {
            return true;
        }

        if ($attribute === 'class' && \in_array($element, ['a', 'code', 'span'], true)) {
            return true;
        }

        return $element === 'a' && \in_array($attribute, ['href', 'title', 'rel', 'hreflang'], true);
    }

    private function safeAbsoluteUrl(string $value, string $baseUrl): ?string
    {
        if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value) === 1 || str_contains($value, '\\')) {
            return null;
        }

        try {
            if (str_starts_with($value, '#')) {
                $fragmentPosition = strpos($baseUrl, '#');
                $url = ($fragmentPosition === false ? $baseUrl : substr($baseUrl, 0, $fragmentPosition)) . $value;
            } else {
                $url = $this->urlResolver->resolveRedirectUrl($value, $baseUrl);
            }
        } catch (HttpClientException | \InvalidArgumentException) {
            return null;
        }

        $parts = parse_url($url);
        if (!\is_array($parts)
            || !\in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !\is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return $url;
    }

    private function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (!$parent instanceof \DOMNode) {
            return;
        }

        while ($element->firstChild instanceof \DOMNode) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /** @return list<\DOMNode> */
    private function children(\DOMNode $node): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        return $children;
    }

    /** @return list<\DOMAttr> */
    private function attributes(\DOMElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute;
        }

        return $attributes;
    }
}
