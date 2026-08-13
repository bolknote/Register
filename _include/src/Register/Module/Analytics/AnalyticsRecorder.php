<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use S2\Cms\Config\StringProxy;
use S2\Cms\Controller\Rss\RssStrategyInterface;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsRecorder
{
    public const string BLOG_FEED_CHANNEL = 'feed:blog';

    public const string PAGES_FEED_CHANNEL = 'feed:pages';

    private ?string $fingerprintsPrunedForDay = null;

    public function __construct(
        private readonly AnalyticsRepository $repository,
        private readonly BotDetector          $botDetector,
        private readonly RssReaderParser      $rssReaderParser,
        private readonly StringProxy          $salt,
    ) {
    }

    public function recordPageView(Request $request): void
    {
        if ($this->skipRequest($request)) {
            return;
        }

        $day = date('Y-m-d');
        $this->pruneFingerprints($day);
        $this->repository->record(
            $day,
            AnalyticsRepository::PAGE_CHANNEL,
            $this->fingerprint($day, $request),
        );
    }

    public function recordFeedRead(Request $request, RssStrategyInterface $rssStrategy): void
    {
        if ($this->skipRequest($request)) {
            return;
        }

        $day        = date('Y-m-d');
        $userAgent  = $request->headers->get('User-Agent', '') ?? '';
        $aggregator = $this->rssReaderParser->aggregator($userAgent);
        $identity   = $aggregator !== null
            ? 'aggregator:' . $aggregator[0]
            : 'reader:' . ($request->getClientIp() ?? 'unknown') . ':' . $userAgent;
        $readerCount = $aggregator !== null ? $aggregator[1] : 1;

        $this->pruneFingerprints($day);
        $this->repository->record(
            $day,
            $this->feedChannel($rssStrategy),
            hash_hmac('sha256', $day . "\0" . $identity, $this->salt->get()),
            hitWeight: 1,
            uniqueWeight: $readerCount,
            countRepeatedHits: false,
        );
    }

    private function skipRequest(Request $request): bool
    {
        if (!$request->isMethod(Request::METHOD_GET)) {
            return true;
        }

        if ($request->headers->get('DNT') === '1' || $request->headers->get('Sec-GPC') === '1') {
            return true;
        }

        return $this->botDetector->isBot($request->headers->get('User-Agent', '') ?? '');
    }

    private function fingerprint(string $day, Request $request): string
    {
        return hash_hmac(
            'sha256',
            $day . "\0" . ($request->getClientIp() ?? 'unknown') . "\0" . ($request->headers->get('User-Agent', '') ?? ''),
            $this->salt->get(),
        );
    }

    private function feedChannel(RssStrategyInterface $rssStrategy): string
    {
        $shortClass = (new \ReflectionClass($rssStrategy))->getShortName();

        return match ($shortClass) {
            'BlogRssStrategy'    => self::BLOG_FEED_CHANNEL,
            'ArticleRssStrategy' => self::PAGES_FEED_CHANNEL,
            default => 'feed:' . (preg_replace('/[^a-z0-9_-]+/', '-', strtolower($shortClass)) ?? 'other'),
        };
    }

    private function pruneFingerprints(string $day): void
    {
        if ($this->fingerprintsPrunedForDay === $day) {
            return;
        }

        $this->repository->forgetVisitorFingerprintsBefore($day);
        $this->fingerprintsPrunedForDay = $day;
    }
}
