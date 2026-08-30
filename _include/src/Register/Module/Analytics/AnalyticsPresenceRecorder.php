<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Register\Core\Config\StringProxy;
use Register\Live\LiveUpdatePolledEvent;
use Register\Module\VisitorIdentity\VisitorIdentityManager;

/** Reuses successful live-update polls as anonymous browser-presence heartbeats. */
final readonly class AnalyticsPresenceRecorder
{
    public function __construct(
        private AnalyticsPresenceStore $store,
        private VisitorIdentityManager  $identityManager,
        private BotDetector             $botDetector,
        private StringProxy             $salt,
    ) {
    }

    public function record(LiveUpdatePolledEvent $event): void
    {
        try {
            $request = $event->request;
            if ($this->botDetector->isBot($request->headers->get('User-Agent') ?? '')) {
                return;
            }

            $pageViewId = $request->query->get('analytics_pageview_id');
            $sessionId  = $request->query->get('analytics_session_id');
            if (!\is_string($pageViewId)
                || preg_match('/^[a-f0-9]{32}$/D', $pageViewId) !== 1
                || !\is_string($sessionId)
                || preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1
            ) {
                return;
            }

            $path  = $this->path($request->query->get('analytics_path'));
            $title = $this->text($request->query->get('analytics_title'), 255);
            if ($path === null) {
                return;
            }

            $secret    = $this->salt->get();
            $visitorId = $this->identityManager->visitorIdFromRequest($request);
            $identity  = $visitorId === null
                ? "live-session\0" . $sessionId
                : "event-visitor\0" . $visitorId;
            $this->store->touch(
                hash_hmac('sha256', "live-pageview\0" . $pageViewId, $secret),
                hash_hmac('sha256', $identity, $secret),
                $path,
                $title,
                $event->occurredAt,
            );
        } catch (\Throwable) {
            // Presence is optional telemetry and cannot break page synchronization.
        }
    }

    private function path(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > 4096) {
            return null;
        }

        $parsed = parse_url($value);
        if ($parsed === false || !isset($parsed['path'])) {
            return null;
        }

        $path = '/' . ltrim($parsed['path'], '/');
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        return \strlen($path) <= 1024 ? $path : null;
    }

    private function text(mixed $value, int $maximumBytes): string
    {
        if (!\is_string($value)) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        return \strlen($value) <= $maximumBytes
            ? $value
            : mb_strcut($value, 0, $maximumBytes, 'UTF-8');
    }
}
