<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Core\Config\StringProxy;
use Register\Core\Controller\Rss\RssStrategyInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalyticsRecorder
{
    public const string BLOG_FEED_CHANNEL = 'feed:blog';

    public function __construct(
        private AnalyticsRepository $repository,
        private BotDetector          $botDetector,
        private RssReaderParser      $rssReaderParser,
        private StringProxy          $salt,
        private VisitorIdentityManager $visitorIdentityManager,
    ) {
    }

    /** @return bool Whether the browser must resolve an identity to finish unique counting. */
    public function recordPageView(Request $request): bool
    {
        if ($this->skipRequest($request)) {
            return false;
        }

        $day = date('Y-m-d');
        $visitorId = $this->visitorIdentityManager->visitorIdFromRequest($request);
        if ($visitorId === null) {
            // Count the page only after the browser resolves its signed visitor identity.
            // Plain HTML fetchers never perform that step and must not turn one shared
            // daily aggregate row into a database lock hotspot.
            return true;
        }

        $this->repository->record(
            $day,
            AnalyticsRepository::PAGE_CHANNEL,
            $this->visitorFingerprint($day, $visitorId),
        );

        return false;
    }

    public function recordResolvedPageVisitor(Request $request, string $visitorId): void
    {
        if ($this->botDetector->isBot($request->headers->get('User-Agent', '') ?? '')) {
            return;
        }

        $day = date('Y-m-d');
        $this->repository->record(
            $day,
            AnalyticsRepository::PAGE_CHANNEL,
            $this->visitorFingerprint($day, $visitorId),
            hitWeight: 1,
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

        return $this->botDetector->isBot($request->headers->get('User-Agent', '') ?? '');
    }

    private function visitorFingerprint(string $day, string $visitorId): string
    {
        return hash_hmac(
            'sha256',
            $day . "\0visitor\0" . $visitorId,
            $this->salt->get(),
        );
    }

    private function feedChannel(RssStrategyInterface $rssStrategy): string
    {
        $id = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($rssStrategy->getId()));

        return 'feed:' . ($id === null || $id === '' ? 'other' : $id);
    }
}
