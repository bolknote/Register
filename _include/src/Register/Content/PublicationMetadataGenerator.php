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

    private const string INVISIBLE_CHARACTERS = '[\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]';

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
            $excerpt = $aiExcerpt !== '' ? $aiExcerpt : $localExcerpt;
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

    private function extractPlainText(string $title, string $body): string
    {
        $beforeCut = preg_split('#<cut\s*/?>#iu', $body, 2);
        $preferredBody = $beforeCut !== false && \count($beforeCut) > 1 ? $beforeCut[0] : $body;
        $plainText = $this->htmlToPlainText($preferredBody);
        if ($plainText === '' && $preferredBody !== $body) {
            $plainText = $this->htmlToPlainText($body);
        }

        $title = $this->normalizePlainText($title);
        if ($title === '' || $plainText === '') {
            return $plainText;
        }

        $lines = preg_split('/\R+/u', $plainText);
        if ($lines !== false && mb_strtolower(trim($lines[0])) === mb_strtolower($title)) {
            array_shift($lines);
            $plainText = implode(' ', $lines);
        }

        return $this->normalizePlainText($plainText);
    }

    private function htmlToPlainText(string $html): string
    {
        $html = preg_replace(
            '#<(script|style|template|noscript|svg|math|pre|code)\b[^>]*>.*?</\1\s*>#isu',
            ' ',
            $html,
        ) ?? $html;
        $html = preg_replace(
            '#</?(?:address|article|aside|blockquote|br|dd|div|dl|dt|figcaption|figure|footer|h[1-6]|header|hr|li|main|nav|ol|p|section|table|tbody|td|tfoot|th|thead|tr|ul)\b[^>]*>#iu',
            "\n",
            $html,
        ) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\R+ */u', "\n", $text) ?? $text;

        return trim($text);
    }

    private function normalizePlainText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
