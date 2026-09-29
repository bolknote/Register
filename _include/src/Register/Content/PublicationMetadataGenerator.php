<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Psr\Log\LoggerInterface;
use Register\Ai\AiClient;
use Register\Ai\AiException;
use Register\Ai\AiSettings;

/** Completes empty publication descriptions, with an optional AI pass and a local fallback. */
final readonly class PublicationMetadataGenerator
{
    public const int EXCERPT_LENGTH = 360;

    public const int META_DESCRIPTION_LENGTH = 160;

    private const int MAX_AI_SOURCE_LENGTH = 60000;

    private const string INVISIBLE_CHARACTERS = '[\x{00A0}\x{200B}\x{FEFF}]';

    public function __construct(
        private AiClient        $aiClient,
        private AiSettings      $aiSettings,
        private LoggerInterface $logger,
    ) {
    }

    public function complete(
        string $title,
        string $body,
        string $excerpt = '',
        string $metaDescription = '',
        bool   $generateExcerpt = true,
        bool   $generateMetaDescription = true,
    ): PublicationMetadata {
        $excerptMissing = $generateExcerpt && trim($excerpt) === '';
        $metaMissing = $generateMetaDescription && trim($metaDescription) === '';
        if (!$excerptMissing && !$metaMissing) {
            return new PublicationMetadata($excerpt, $metaDescription);
        }

        $plainText = $this->extractPlainText($title, $body);
        if ($plainText === '') {
            return new PublicationMetadata($excerpt, $metaDescription);
        }

        $localExcerpt = $this->summarize($plainText, self::EXCERPT_LENGTH);
        $localMetaDescription = $this->summarize($plainText, self::META_DESCRIPTION_LENGTH);
        $aiMetadata = null;
        if ($this->aiSettings->autoMetadataEnabled() && $this->aiSettings->isConfigured()) {
            try {
                $aiMetadata = $this->aiClient->generatePublicationMetadata(
                    mb_substr($this->normalizePlainText($title), 0, 500),
                    mb_substr($plainText, 0, self::MAX_AI_SOURCE_LENGTH),
                );
            } catch (AiException $exception) {
                $this->logger->warning('AI publication metadata generation failed; local summary used.', [
                    'provider' => $this->aiSettings->provider(),
                    'error'    => $exception->getMessage(),
                ]);
            }
        }

        $generatedWithAi = false;
        if ($excerptMissing) {
            $aiExcerpt = $aiMetadata === null
                ? ''
                : $this->summarize($this->normalizePlainText($aiMetadata['excerpt']), self::EXCERPT_LENGTH);
            // Excerpts are rendered as HTML; generated metadata itself is plain text.
            $excerpt = htmlspecialchars($aiExcerpt !== '' ? $aiExcerpt : $localExcerpt, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $generatedWithAi = $aiExcerpt !== '';
        }

        if ($metaMissing) {
            $aiMetaDescription = $aiMetadata === null
                ? ''
                : $this->summarize(
                    $this->normalizePlainText($aiMetadata['meta_description']),
                    self::META_DESCRIPTION_LENGTH,
                );
            $metaDescription = $aiMetaDescription !== '' ? $aiMetaDescription : $localMetaDescription;
            $generatedWithAi = $generatedWithAi || $aiMetaDescription !== '';
        }

        return new PublicationMetadata($excerpt, $metaDescription, $generatedWithAi);
    }

    private function extractPlainText(string $title, string $html): string
    {
        // The PHP 8.3 DOM parser understands HTML4 names. Numeric references
        // preserve HTML5 entities without decoding them into source markup.
        $html = preg_replace_callback('/&[a-z][a-z0-9]+;/i', static function (array $match): string {
            $decoded = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $match[0]) {
                return $match[0];
            }

            return mb_encode_numericentity($decoded, [0, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
        }, $html) ?? $html;

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if (!$loaded || $body === null) {
            return '';
        }

        $text = '';
        $beforeCut = null;
        $this->appendPlainText($body, $text, $beforeCut);
        $title = $this->normalizePlainText($title);
        // A title or invisible whitespace alone is not an editorial lead.
        $lead = $this->normalizeDescriptionText($beforeCut ?? $text, $title);
        return $lead !== '' ? $lead : $this->normalizeDescriptionText($text, $title);
    }

    private function normalizeDescriptionText(string $text, string $title): string
    {
        $lines = array_values(array_filter(
            array_map($this->normalizePlainText(...), explode("\n", $this->normalizeTextLines($text))),
            static fn(string $line): bool => $line !== '',
        ));
        if (isset($lines[0]) && $title !== '' && mb_strtolower($lines[0]) === mb_strtolower($title)) {
            array_shift($lines);
        }

        return implode(' ', $lines);
    }

    private function appendPlainText(\DOMNode $node, string &$text, ?string &$beforeCut): void
    {
        if ($node instanceof \DOMText) {
            $text .= $node->textContent;
            return;
        }

        if (!$node instanceof \DOMElement) {
            return;
        }

        $name = strtolower($node->tagName);
        if (\in_array($name, ['script', 'style', 'template', 'noscript', 'svg', 'math', 'pre', 'code'], true)) {
            $text .= ' ';
            return;
        }

        if ($name === 'cut' && $beforeCut === null) {
            $beforeCut = $text;
        }

        $block = \in_array($name, [
            'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'section',
            'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
        ], true);
        if ($block) {
            $text .= "\n";
        }

        foreach ($node->childNodes as $child) {
            $this->appendPlainText($child, $text, $beforeCut);
        }

        if ($block) {
            $text .= "\n";
        }
    }

    private function normalizeTextLines(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\R+ */u', "\n", $text) ?? $text;

        return trim($text);
    }

    private function normalizePlainText(string $text): string
    {
        // Body HTML and AI responses have already been decoded; titles are plain text.
        $text = preg_replace('/' . self::INVISIBLE_CHARACTERS . '/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function summarize(string $text, int $limit): string
    {
        $text = $this->normalizePlainText($text);
        if ($text === '' || mb_strlen($text) <= $limit) {
            return $text;
        }

        $sentences = preg_split('/(?<=[.!?…])\s+/u', $text);
        if ($sentences !== false) {
            $summary = '';
            foreach ($sentences as $sentence) {
                $candidate = $summary === '' ? $sentence : $summary . ' ' . $sentence;
                if (mb_strlen($candidate) > $limit) {
                    break;
                }

                $summary = $candidate;
            }

            if ($summary !== '') {
                return $summary;
            }
        }

        return $this->truncateAtWord($text, $limit);
    }

    private function truncateAtWord(string $text, int $limit): string
    {
        if ($limit < 2 || mb_strlen($text) <= $limit) {
            return mb_substr($text, 0, $limit);
        }

        $prefix = rtrim(mb_substr($text, 0, $limit - 1));
        if (preg_match('/^(.+)\s+\S*$/us', $prefix, $matches) === 1) {
            $prefix = rtrim($matches[1]);
        }

        return ($prefix !== '' ? $prefix : rtrim(mb_substr($text, 0, $limit - 1))) . '…';
    }
}
