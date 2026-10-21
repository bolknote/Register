<?php
/** Early public-cache bootstrap. Intentionally does not load Composer or the engine. */

declare(strict_types = 1);

use Register\Core\Http\Cache\EarlyPageCache;
use Register\Update\RuntimeLock;

require_once __DIR__ . '/src/Http/Cache/EarlyPageCache.php';

/** @return array{cache:EarlyPageCache,lock:RuntimeLock,record:\Closure(array{content_id:?string}):bool}|null */
$registerEarlyBootstrap = static function (): ?array {
    $root = dirname(__DIR__);
    $sourceStamp = EarlyPageCache::sourceStamp($root);
    if (!EarlyPageCache::accepts($_SERVER)) {
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

        $cacheDirectory = $location['directory'];
        require_once __DIR__ . '/src/Register/Module/Analytics/BotDetector.php';
        $_SERVER['REGISTER_EARLY_VARIANT'] = (new \Register\Module\Analytics\BotDetector())->isBot((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))
            ? 'crawler'
            : (str_contains(strtolower((string)($_SERVER['HTTP_PURPOSE'] ?? '') . ' ' . (string)($_SERVER['HTTP_SEC_PURPOSE'] ?? '')), 'prefetch') ? 'prefetch' : 'reader');
        /** @param array{content_id:?string} $hit */
        $record = static function (array $hit) use ($root, $cacheDirectory): bool {
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
        return ['lock' => RuntimeLock::acquireShared($root), 'record' => $record,
            'cache' => new EarlyPageCache($cacheDirectory, $root, sourceStamp: $sourceStamp)];
    } catch (Throwable) {
        return null;
    }
};

return $registerEarlyBootstrap();
