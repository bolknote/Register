<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

final class SpamFeatureExtractor
{
    /**
     * @return list<string>
     */
    public function domains(string $text): array
    {
        $matchCount = preg_match_all('#https?://[^\s<>\]\[()"\']+#iu', $text, $matches);
        if ($matchCount === false || $matchCount === 0) {
            return [];
        }

        $domains = [];
        foreach ($matches[0] as $url) {
            $host = parse_url(rtrim($url, '.,;:!?'), PHP_URL_HOST);
            if (\is_string($host) && $host !== '') {
                $domains[] = trim(mb_strtolower($host), '.');
            }
        }

        return array_values(array_unique($domains));
    }

    public function linkCount(string $text): int
    {
        $count = preg_match_all('#(https?://\S{2,}?)(?=[\s),\'<>\]]|&lt;|&gt;|[.;:](?:\s|$)|$)#u', $text);

        return $count !== false ? $count : 0;
    }

    public function hasHtml(string $text): bool
    {
        return preg_match('#</?[a-z][^>]*>#i', $text) === 1;
    }

    public function hasFormattingControls(string $text): bool
    {
        return preg_match('#\p{Cf}#u', $text) === 1;
    }

    public function hasLongRepetition(string $text): bool
    {
        return preg_match('#(.)\1{9,}#us', $text) === 1;
    }
}
