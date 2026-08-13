<?php

declare(strict_types = 1);

/**
 * Functions of the counter extension
 *
 * @copyright 2007-2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   s2_counter
 */

use S2\Cms\Controller\Rss\RssStrategyInterface;
use Symfony\Component\HttpFoundation\Request;

if (!defined('S2_COUNTER_TOTAL_HITS_FNAME')) {
    define('S2_COUNTER_TOTAL_HITS_FNAME', '/data/total_hits.txt');
}

if (!defined('S2_COUNTER_TODAY_INFO_FNAME')) {
    define('S2_COUNTER_TODAY_INFO_FNAME', '/data/today_info.txt');
}

if (!defined('S2_COUNTER_ARCH_INFO_FNAME')) {
    define('S2_COUNTER_ARCH_INFO_FNAME', '/data/arch_info.txt');
}

if (!defined('S2_COUNTER_TODAY_DATA_FNAME')) {
    define('S2_COUNTER_TODAY_DATA_FNAME', '/data/today_data.txt');
}

function s2_counter_is_bot(): bool
{
    $sebot = [
        'bot',
        'Yahoo!',
        'Mediapartners-Google',
        'Spider',
        'Yandex',
        'StackRambler',
        'ia_archiver',
        'appie',
        'ZyBorg',
        'WebAlta',
        'ichiro',
        'TurtleScanner',
        'LinkWalker',
        'Snoopy',
        'libwww',
        'Aport',
        'Crawler',
        'Spyder',
        'findlinks',
        'Parser',
        'Mail.Ru',
        'rulinki.ru',
    ];

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if (!\is_string($userAgent)) {
        return false;
    }

    foreach ($sebot as $se1) {
        if (stristr($userAgent, $se1) !== false) {
            return true;
        }
    }

    return false;
}

function s2_counter_append_file(string $filename, string $str): void
{
    $f = fopen($filename, 'a+');
    if ($f === false) {
        throw new \RuntimeException('Unable to open counter file: ' . $filename);
    }

    try {
        flock($f, LOCK_EX);
        fwrite($f, $str);
        fflush($f);
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

function s2_counter_refresh_file(string $filename, string $str): void
{
    $f = fopen($filename, 'a+');
    if ($f === false) {
        throw new \RuntimeException('Unable to open counter file: ' . $filename);
    }

    try {
        flock($f, LOCK_EX);
        ftruncate($f, 0);
        fwrite($f, $str);
        fflush($f);
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }
}

function s2_counter_get_total_hits(string $dir): int
{
    $f = fopen($dir . S2_COUNTER_TOTAL_HITS_FNAME, 'a+');
    if ($f === false) {
        throw new \RuntimeException('Unable to open the total hits counter.');
    }

    try {
        flock($f, LOCK_EX);
        $contents = fread($f, 100);
        $hits = (int)($contents !== false ? $contents : '0') + 1;

        ftruncate($f, 0);
        fwrite($f, (string)$hits);
        fflush($f);
    } finally {
        flock($f, LOCK_UN);
        fclose($f);
    }

    return $hits;
}

function s2_counter_process(): void
{
    if (s2_counter_is_bot()) {
        return;
    }

    $dir = __DIR__;

    if (!is_file($dir . S2_COUNTER_TODAY_DATA_FNAME) && !is_writable(dirname($dir . S2_COUNTER_TODAY_DATA_FNAME))) {
        return;
    }

    $f_day_info = fopen($dir . S2_COUNTER_TODAY_DATA_FNAME, 'a+');
    if ($f_day_info === false) {
        return;
    }

    flock($f_day_info, LOCK_EX);

    $serializedIps = file_get_contents($dir . S2_COUNTER_TODAY_DATA_FNAME);
    $decodedIps = $serializedIps !== false ? unserialize($serializedIps, ['allowed_classes' => false]) : [];
    /** @var array<string, positive-int> $ips */
    $ips = \is_array($decodedIps) ? $decodedIps : [];

    clearstatcache();
    $modifiedAt = filemtime($dir . S2_COUNTER_TODAY_DATA_FNAME);
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ($modifiedAt === false || date('j', $modifiedAt) === date('j')) {
        // We have already some hits today

        // Let's correct the data saved before
        if (isset($ips[$remoteAddress])) {
            ++$ips[$remoteAddress];
        } else {
            $ips[$remoteAddress] = 1;
        }

        $today_hosts = count($ips);
        $today_hits  = array_sum($ips);
    } else {
        // It's a new day!

        // Logging yesterday info
        s2_counter_append_file($dir . S2_COUNTER_ARCH_INFO_FNAME, date('Y-m-d', time() - 86400) . '^' . ($ips !== [] ? array_sum($ips) : 0) . '^' . count($ips) . "\n");

        // Erase yesterday info
        $ips = [$remoteAddress => 1];
        $today_hits = 1;
        $today_hosts = 1;
    }

    ftruncate($f_day_info, 0);
    fwrite($f_day_info, serialize($ips));
    fflush($f_day_info);

    flock($f_day_info, LOCK_UN);
    fclose($f_day_info);

    $total_hits = s2_counter_get_total_hits($dir);
    s2_counter_refresh_file($dir . S2_COUNTER_TODAY_INFO_FNAME, $total_hits . "\n" . $today_hits . "\n" . $today_hosts);
}

/**
 * @return array{string, int}|null
 */
function s2_counter_get_aggregator(string $userAgent): ?array
{
    foreach ([
                 'feeder.co'                              => 1,
                 'NetNewsWire'                            => 1,
                 'Feedspot'                               => 1,
                 'http://www.google.com/feedfetcher.html' => 0,
             ] as $noStatsAggregator => $readersNum) {
        if (str_contains($userAgent, $noStatsAggregator)) {
            return [$noStatsAggregator, $readersNum];
        }
    }

    $knownAggregators = [
        'YandexBlog'      => '#(YandexBlog).*?(\d+) (readers)#',
        'AideRSS'         => '#(AideRSS).*?(\d+) (subscribers)#',
        'NewsGatorOnline' => '#(NewsGatorOnline).*?(\d+) (subscribers)#',
        'PostRank'        => '#(PostRank).*?(\d+) (subscribers)#',
        'Feedbin'         => '#(Feedbin feed-id:\d+) - (\d+) (subscribers)#',
        'theoldreader'    => '#(theoldreader).* (\d+) (subscribers; feed-id=[^\)]*)#',
        'universal'       => '#(Feedly|Bloglovin|BazQux|inoreader|NewsBlur).* (\d+) (subscribers)#',
    ];

    $pattern = $knownAggregators['universal'];
    unset($knownAggregators['universal']);
    foreach ($knownAggregators as $aggregator => $candidatePattern) {
        if (str_contains($userAgent, $aggregator)) {
            $pattern = $candidatePattern;
            break;
        }
    }

    if (preg_match($pattern, $userAgent, $matches) === 1) {
        return [$matches[1] . $matches[3], (int)$matches[2]];
    }

    return null;
}

function s2_counter_rss_count(Request $request, RssStrategyInterface $rssStrategy): void
{
    if (s2_counter_is_bot()) {
        return;
    }

    $dir = __DIR__;

    $userAgent = $request->headers->get('User-Agent', '') ?? '';
    $serverAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $clientIp  = $request->getClientIp() ?? (\is_string($serverAddress) ? $serverAddress : 'unknown');
    $fileName   = match ($rssStrategy::class) {
        \S2\Cms\Model\Article\ArticleRssStrategy::class => '/data/rss_main.txt',
        \s2_extensions\s2_blog\Model\BlogRssStrategy::class => '/data/rss_s2_blog.txt',
        default => '/data/rss_'.array_reverse(explode('\\', $rssStrategy::class))[0].'.txt',
    };

    $fullFileName = $dir . $fileName;
    if (!is_file($fullFileName) && !is_writable(dirname($fullFileName))) {
        return;
    }

    clearstatcache();
    $modifiedAt = filemtime($fullFileName);
    if ($modifiedAt === false || date('j', $modifiedAt) === date('j')) {
        s2_counter_append_file($fullFileName, time() . '^' . $clientIp . '^' . $userAgent . "\n");
    } else {
        $fileDayInfo = fopen($fullFileName, 'a+');
        if ($fileDayInfo === false) {
            return;
        }

        flock($fileDayInfo, LOCK_EX);

        $yesterdayLog = s2_call_without_warnings(static fn(): string|false => file_get_contents($fullFileName));

        $totalReaders = s2_counter_get_total_readers($yesterdayLog !== false ? $yesterdayLog : '');

        s2_counter_append_file($fullFileName . '.log', date('Y-m-d', time() - 86400) . '^' . $totalReaders . "\n");

        ftruncate($fileDayInfo, 0);
        fwrite($fileDayInfo, time() . '^' . $clientIp . '^' . $userAgent . "\n");
        fflush($fileDayInfo);

        flock($fileDayInfo, LOCK_UN);
        fclose($fileDayInfo);
    }

}

function s2_counter_get_total_readers(string $logContents): int
{
    $rss_readers = [];
    $online_aggregators = [];
    foreach (explode("\n", substr($logContents, 0, -1)) as $line) {
        if ($line === '') {
            continue;
        }

        $parts = explode('^', $line, 3);
        if (\count($parts) !== 3) {
            continue;
        }

        [, $ip, $ua] = $parts;

        $aggregator_info = s2_counter_get_aggregator($ua);
        if ($aggregator_info !== null) {
            $online_aggregators[$aggregator_info[0]] = $aggregator_info[1];
        } else {
            $ipParts = preg_split('#[.:]#', $ip);
            $ip0 = $ipParts[0] ?? $ip;
            $ip1 = $ipParts[1] ?? '';
            $rss_readers[$ip0 . $ua . $ip1] = 1;
        }
    }

    return \count($rss_readers) + array_sum($online_aggregators);
}

define('S2_COUNTER_FUNCTIONS_LOADED', 1);
