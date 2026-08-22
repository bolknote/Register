<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Template;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Builds the compact representation used by progressive client-side navigation. */
final class PartialPageResponse
{
    public const string REQUEST_HEADER = 'X-Register-Navigation';

    public const string RESPONSE_CONTENT_TYPE = 'application/vnd.register.page+json';

    public const string START_MARKER = '<!-- register-page-start -->';

    public const string END_MARKER = '<!-- register-page-end -->';

    public static function create(Request $request, Response $fullResponse): Response
    {
        $fullResponse->setVary(self::REQUEST_HEADER, false);
        if (
            !$request->isMethod(Request::METHOD_GET)
            || $request->headers->get(self::REQUEST_HEADER) !== 'partial'
        ) {
            return $fullResponse;
        }

        $html = $fullResponse->getContent();
        if (!\is_string($html)) {
            return $fullResponse;
        }

        $page = self::extractPage($html);
        if ($page === null) {
            return $fullResponse;
        }

        $response = new JsonResponse(null, $fullResponse->getStatusCode());
        $response->setEncodingOptions(
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $response->setData([
            'version'   => 1,
            'title'     => self::extractTitle($page['head']),
            'lang'      => self::extractHtmlLanguage($html),
            'bodyClass' => self::extractBodyClass($html),
            'head'      => self::extractManagedHead($page['head']),
            'fragment'  => $page['fragment'],
            'assets'    => self::extractAssets($html),
        ]);
        $response->headers->set('Content-Type', self::RESPONSE_CONTENT_TYPE . '; charset=UTF-8');
        $response->headers->set(self::REQUEST_HEADER, 'partial');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->setVary(self::REQUEST_HEADER);
        $response->setEtag(md5((string)$response->getContent()));

        foreach ($fullResponse->headers->getCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /** @return array{head: string, fragment: string}|null */
    private static function extractPage(string $html): ?array
    {
        $start = strpos($html, self::START_MARKER);
        if ($start === false) {
            return null;
        }

        $start += \strlen(self::START_MARKER);

        $end = strpos($html, self::END_MARKER, $start);
        if ($end === false) {
            return null;
        }

        $fragment = trim(substr($html, $start, $end - $start));
        if (preg_match('#^<div\s+id=(?:"register-page"|\'register-page\')(?=\s|>)#i', $fragment) !== 1) {
            return null;
        }

        if (preg_match('#<head\b[^>]*>(.*?)</head>#is', $html, $headMatch) !== 1) {
            return null;
        }

        return ['head' => $headMatch[1], 'fragment' => $fragment];
    }

    private static function extractTitle(string $head): string
    {
        if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $head, $matches) !== 1) {
            return '';
        }

        return html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function extractManagedHead(string $head): string
    {
        $scriptPattern = '<' . 'script\b[^>]*>.*?</' . 'script>';
        if (preg_match_all(
            '#<title\b[^>]*>.*?</title>|<meta\b[^>]*>|<link\b[^>]*>|' . $scriptPattern . '#is',
            $head,
            $matches,
        ) === false) {
            return '';
        }

        $managed = [];
        foreach ($matches[0] as $tag) {
            $tagName = self::tagName($tag);
            if ($tagName === 'title') {
                $managed[] = $tag;
                continue;
            }

            if ($tagName === 'meta') {
                $name = strtolower(self::attributeValue($tag, 'name'));
                if (
                    self::attributeValue($tag, 'property') !== ''
                    || \in_array($name, ['description', 'keywords', 'robots'], true)
                    || str_starts_with($name, 'register-')
                ) {
                    $managed[] = $tag;
                }

                continue;
            }

            if ($tagName === 'link') {
                $relations = preg_split('/\s+/', strtolower(self::attributeValue($tag, 'rel')));
                if (
                    \is_array($relations)
                    && array_intersect($relations, ['alternate', 'canonical', 'next', 'prev', 'up']) !== []
                ) {
                    $managed[] = $tag;
                }

                continue;
            }

            if (
                $tagName === 'script'
                && strtolower(self::attributeValue($tag, 'type')) === 'application/ld+json'
            ) {
                $managed[] = $tag;
            }
        }

        return implode("\n", $managed);
    }

    private static function tagName(string $tag): string
    {
        return preg_match('#^<([a-z][a-z0-9]*)\b#i', $tag, $matches) === 1 ? strtolower($matches[1]) : '';
    }

    private static function attributeValue(string $tag, string $attribute): string
    {
        $pattern = '#\b' . preg_quote($attribute, '#') . '\s*=\s*(["\'])(.*?)\1#is';
        if (preg_match($pattern, $tag, $matches) !== 1) {
            return '';
        }

        return html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function extractHtmlLanguage(string $html): string
    {
        return self::extractAttribute($html, 'html', 'lang');
    }

    private static function extractBodyClass(string $html): string
    {
        return self::extractAttribute($html, 'body', 'class');
    }

    private static function extractAttribute(string $html, string $tag, string $attribute): string
    {
        $pattern = '#<' . preg_quote($tag, '#') . '\b[^>]*\b' . preg_quote($attribute, '#')
            . '\s*=\s*(["\'])(.*?)\1#is';
        if (preg_match($pattern, $html, $matches) !== 1) {
            return '';
        }

        return html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return list<string> */
    private static function extractAssets(string $html): array
    {
        $previousErrors = libxml_use_internal_errors(true);
        $document       = new \DOMDocument();
        $loaded         = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        if (!$loaded) {
            return [];
        }

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query(
            '//link[contains(concat(" ", normalize-space(@rel), " "), " stylesheet ")][@href] | //script[@src]',
        );
        if (!$nodes instanceof \DOMNodeList) {
            return [];
        }

        $assets = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $attribute = strtolower($node->tagName) === 'script' ? 'src' : 'href';
            $value     = $node->getAttribute($attribute);
            if ($value !== '') {
                $assets[] = $value;
            }
        }

        sort($assets, SORT_STRING);

        return array_values(array_unique($assets));
    }
}
