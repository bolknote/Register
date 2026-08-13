<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

final readonly class SlugGenerator
{
    public const int MAX_LENGTH = 255;

    public function __construct(
        private TransliteratorInterface $fallback,
        private ?TransliteratorInterface $primary = null,
    ) {
    }

    public function generate(string $title): string
    {
        $transliterated = $this->primary?->transliterate($title);
        if ($transliterated === null || preg_match('/^[\x00-\x7f]*$/D', $transliterated) !== 1) {
            $transliterated = $this->fallback->transliterate($title);
        }

        $slug = strtolower($transliterated);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        $slug = trim($slug ?? '', '-');

        return rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
    }
}
