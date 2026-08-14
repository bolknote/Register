<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

final readonly class RssReaderParser
{
    /**
     * Returns a stable aggregator identifier and the number of readers reported by its User-Agent.
     *
     * @return array{0: string, 1: int}|null
     */
    public function aggregator(string $userAgent): ?array
    {
        foreach ([
            'feeder.co'                              => 1,
            'NetNewsWire'                            => 1,
            'Feedspot'                               => 1,
            'http://www.google.com/feedfetcher.html' => 0,
        ] as $identifier => $readers) {
            if (str_contains($userAgent, $identifier)) {
                return [$identifier, $readers];
            }
        }

        $patterns = [
            'YandexBlog'      => '#(YandexBlog).*?(\d+) (readers)#',
            'AideRSS'         => '#(AideRSS).*?(\d+) (subscribers)#',
            'NewsGatorOnline' => '#(NewsGatorOnline).*?(\d+) (subscribers)#',
            'PostRank'        => '#(PostRank).*?(\d+) (subscribers)#',
            'Feedbin'         => '#(Feedbin feed-id:\d+) - (\d+) (subscribers)#',
            'theoldreader'    => '#(theoldreader).* (\d+) (subscribers; feed-id=[^\)]*)#',
        ];

        $pattern = '#(Feedly|Bloglovin|BazQux|inoreader|NewsBlur).* (\d+) (subscribers)#';
        foreach ($patterns as $identifier => $candidate) {
            if (str_contains($userAgent, $identifier)) {
                $pattern = $candidate;
                break;
            }
        }

        if (preg_match($pattern, $userAgent, $matches) !== 1) {
            return null;
        }

        return [$matches[1] . $matches[3], max(0, (int)$matches[2])];
    }

    public function totalReaders(string $logContents): int
    {
        $readers     = [];
        $aggregators = [];
        $lines = preg_split('/\R/', trim($logContents));
        foreach ($lines !== false ? $lines : [] as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode('^', $line, 3);
            if (\count($parts) !== 3) {
                continue;
            }

            [, $ip, $userAgent] = $parts;
            $aggregator = $this->aggregator($userAgent);
            if ($aggregator !== null) {
                $aggregators[$aggregator[0]] = $aggregator[1];
                continue;
            }

            $ipParts = preg_split('#[.:]#', $ip);
            $ipParts = $ipParts !== false ? $ipParts : [];
            $readers[($ipParts[0] ?? $ip) . $userAgent . ($ipParts[1] ?? '')] = true;
        }

        return \count($readers) + array_sum($aggregators);
    }
}
