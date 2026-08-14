<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Module\VisitorIdentity\VisitorIdentityManager;
use S2\Cms\Config\StringProxy;
use S2\Cms\Controller\Rss\RssStrategyInterface;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsRecorder
{
    public const string BLOG_FEED_CHANNEL = 'feed:blog';

    private ?string $fingerprintsPrunedForDay = null;

    public function __construct(
        private readonly AnalyticsRepository $repository,
        private readonly BotDetector          $botDetector,
        private readonly RssReaderParser      $rssReaderParser,
        private readonly StringProxy          $salt,
        private readonly VisitorIdentityManager $visitorIdentityManager,
    ) {
    }

    /** @return bool Whether the browser must resolve an identity to finish unique counting. */
    public function recordPageView(Request $request): bool
    {
        if ($this->skipRequest($request)) {
            return false;
        }

        $day = date('Y-m-d');
        $this->pruneFingerprints($day);
        $visitorId = $this->visitorIdentityManager->visitorIdFromRequest($request);
        if ($visitorId === null) {
            $this->repository->recordHit($day, AnalyticsRepository::PAGE_CHANNEL);

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
        $this->pruneFingerprints($day);
        $this->repository->record(
            $day,
            AnalyticsRepository::PAGE_CHANNEL,
            $this->visitorFingerprint($day, $visitorId),
            hitWeight: 0,
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

    private function pruneFingerprints(string $day): void
    {
        if ($this->fingerprintsPrunedForDay === $day) {
            return;
        }

        $this->repository->forgetVisitorFingerprintsBefore($day);
        $this->fingerprintsPrunedForDay = $day;
    }
}
