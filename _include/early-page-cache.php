<?php
/** Early public-cache bootstrap. Intentionally does not load Composer or the engine. */

declare(strict_types = 1);

use Register\Core\Http\Cache\EarlyPageCache;
use Register\Core\Http\Cache\PublicPageCacheRequest;
use Register\Auth\PublicAuthCacheRequest;
use Register\Update\RuntimeLock;

require_once __DIR__ . '/src/Http/Cache/EarlyPageCache.php';

/** @return array{cache:EarlyPageCache,lock:RuntimeLock,record:\Closure(array{content_id:?string,feed_channel:?string}):bool,server:array<string,mixed>,transform:(\Closure(string):string)|null}|null */
$registerEarlyBootstrap = static function (): ?array {
    $root = dirname(__DIR__);
    $sourceStamp = EarlyPageCache::sourceStamp($root);
    require_once __DIR__ . '/src/Register/Module/Analytics/BotDetector.php';
    $_SERVER['REGISTER_EARLY_VARIANT'] = (new \Register\Module\Analytics\BotDetector())->isBot((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))
        ? 'crawler'
        : (str_contains(strtolower((string)($_SERVER['HTTP_PURPOSE'] ?? '') . ' ' . (string)($_SERVER['HTTP_SEC_PURPOSE'] ?? '')), 'prefetch') ? 'prefetch' : 'reader');
    // Cookie names and forwarding policy come from the evaluated configuration.
    // Before locating it, check only eligibility that does not depend on that policy.
    $publicServer = $_SERVER;
    unset($publicServer['HTTP_COOKIE']);
    $publicServer['REQUEST_URI'] = explode('?', (string)($publicServer['REQUEST_URI'] ?? ''), 2)[0];
    if (!EarlyPageCache::accepts($publicServer, ignoreForwardingHeaders: true)) {
        return null;
    }

    try {
        if (is_file($root . '/.register-maintenance.json')) {
            return null;
        }

        require_once __DIR__ . '/src/Http/ResponseCompressor.php';
        if (\Register\Core\Http\ResponseCompressor::phpManagesCompression()) {
            return null;
        }

        $location = EarlyPageCache::locate($root, $sourceStamp);
        if ($location === null || defined('REGISTER_DISABLE_CACHE') || defined('REGISTER_DEBUG')
            || defined('REGISTER_DEBUG_VIEW') || defined('REGISTER_SHOW_QUERIES')) {
            return null;
        }

        $auth = null;
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $authPath = $location['base_path'] . '/auth';
        if ($uri === $authPath || str_starts_with($uri, $authPath . '?') || str_starts_with($uri, $authPath . '/oauth/')) {
            require_once __DIR__ . '/src/Register/Auth/PublicReturnPath.php';
            require_once __DIR__ . '/src/Register/Auth/PublicAuthCacheRequest.php';
            $auth = PublicAuthCacheRequest::fromUri($uri, $authPath);
        }

        require_once __DIR__ . '/src/Http/Cache/PublicPageCacheRequest.php';
        $server = $auth?->cacheServer($_SERVER) ?? PublicPageCacheRequest::fromUri($uri)?->cacheServer($_SERVER) ?? $_SERVER;
        if (!EarlyPageCache::accepts($server, $location['forwarding_ignored'], $location['public_cookies'])) {
            return null;
        }

        $cacheDirectory = $location['directory'];
        $cache = new EarlyPageCache($cacheDirectory, $root, sourceStamp: $sourceStamp, ignoreForwardingHeaders: $location['forwarding_ignored'], publicCookies: $location['public_cookies']);
        /** @param array{content_id:?string,feed_channel:?string} $hit */
        $record = static function (array $hit) use ($root, $cacheDirectory, $cache): bool {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $hit['feed_channel'] !== null && $_SERVER['REGISTER_EARLY_VARIANT'] !== 'crawler'
                && !$cache->feedReadRecorded($_SERVER, $hit['feed_channel'])) {
                return false;
            }

            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || $hit['content_id'] === null || $_SERVER['REGISTER_EARLY_VARIANT'] !== 'reader') {
                return true;
            }

            try {
                foreach (['ContentType', 'ContentId', 'ContentViewIncrement', 'ContentViewSpoolException', 'ContentViewSpool'] as $class) {
                    require_once $root . '/_include/src/Register/Content/' . $class . '.php';
                }

                (new \Register\Content\ContentViewSpool($cacheDirectory . '/content-view-spool'))->append(
                    new \Register\Content\ContentViewIncrement(\Register\Content\ContentId::fromString($hit['content_id']), gmdate('Y-m-d')),
                );
                return true;
            } catch (\Throwable) {
                // The full handler retains the database fallback; do not lose a view.
                return false;
            }
        };
        require_once __DIR__ . '/src/Register/Update/RuntimeLock.php';
        return ['lock' => RuntimeLock::acquireShared($root), 'record' => $record, 'server' => $server,
            'transform' => $auth !== null && $auth->mode !== null ? $auth->expand(...) : null,
            'cache' => $cache];
    } catch (Throwable) {
        return null;
    }
};

return $registerEarlyBootstrap();
