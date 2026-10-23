<?php
/**
 * @copyright 2026 Register contributors
 * @license https://opensource.org/license/mit MIT
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

/** Plain-data public responses readable before Composer, the container or a database exists. */
final class EarlyPageCache
{
    public const string VIEW_ATTRIBUTE = '_register_early_views';

    public const string CONTENT_ATTRIBUTE = '_register_early_content';

    public const string DEADLINE_HEADER = 'X-Register-Cache-Deadline';

    private const int FORMAT = 1;

    private const int MAX_BYTES = 4_194_304;

    private const int MAX_AGE = 86_400;

    // 256 hash buckets cap public snapshots at 256 MiB and 8192 files.
    private const int MAX_BUCKET_BYTES = 1_048_576;

    private const int MAX_BUCKET_ENTRIES = 32;

    private const int MAX_BUCKET_SCAN = 128;

    private const array FORWARDED_HEADERS = ['HTTP_FORWARDED', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO',
        'HTTP_X_FORWARDED_PORT', 'HTTP_X_FORWARDED_PREFIX', 'HTTP_X_FORWARDED_FOR'];

    private readonly string $directory;

    private ?string $generation;

    private string $decision = 'miss';

    private bool $unavailable = false;

    private readonly string $sourceStamp;

    /** @var resource|null */
    private mixed $maintenanceLock = null;

    public function decision(): string
    {
        return $this->decision;
    }

    /** @phpstan-impure Reads the currently deployed configuration and code. */
    public static function sourceStamp(string $root): string
    {
        $env = getenv('APP_ENV');
        $config = '/config' . (is_string($env) && $env !== '' ? '.' . $env : '') . '.php';
        $values = [];
        foreach ([$config, '/register-build.json', '/index.php', '/_include/early-page-cache.php', '/_include/src/Http/Cache/EarlyPageCache.php'] as $file) {
            $values[] = is_file($root . $file) ? self::withoutWarnings(static fn(): string|false => hash_file('sha256', $root . $file)) : '';
        }

        return hash('sha256', implode(':', $values));
    }

    /**
     * @return array{directory:string,stamp:string,forwarding_ignored:bool}|null
     * @phpstan-impure The location can change between requests and configuration writes.
     */
    public static function locate(string $root, ?string $sourceStamp = null): ?array
    {
        $path = self::locationPath($root);
        if (!is_file($path) || is_link($path) || self::withoutWarnings(static fn(): int|false => filesize($path)) > 2048) {
            return null;
        }

        $json = self::withoutWarnings(static fn(): string|false => file_get_contents($path));
        $location = is_string($json) ? json_decode($json, true, 4) : null;
        if (!is_array($location) || ($location['enabled'] ?? false) !== true
            || !is_string($location['directory'] ?? null) || !is_string($location['stamp'] ?? null)
            || !is_bool($location['forwarding_ignored'] ?? null)
            || $location['stamp'] !== ($sourceStamp ?? self::sourceStamp($root))) {
            return null;
        }

        return ['directory' => $location['directory'], 'stamp' => $location['stamp'], 'forwarding_ignored' => $location['forwarding_ignored']];
    }

    /** Publish only normalized configuration after the normal bootstrap has evaluated it. */
    public function publishLocation(string $expectedSourceStamp): void
    {
        if ($expectedSourceStamp !== self::sourceStamp($this->applicationRoot)) {
            return;
        }

        $directory = realpath($this->cacheDirectory);
        if ($directory === false) {
            return;
        }

        $location = ['directory' => $directory, 'enabled' => !$this->disabled && !$this->unavailable,
            'stamp' => $expectedSourceStamp, 'forwarding_ignored' => $this->ignoreForwardingHeaders];
        $path = self::locationPath($this->applicationRoot);
        if ($this->read($path) !== $location) {
            $this->write($path, $location);
        }
    }

    private static function locationPath(string $root): string
    {
        return $root . '/_cache/.early-cache-' . substr(hash('sha256', (string)getenv('APP_ENV')), 0, 16) . '.json';
    }

    public function __construct(
        private readonly string $cacheDirectory,
        private readonly string $applicationRoot,
        private readonly bool $disabled = false,
        ?string $sourceStamp = null,
        private readonly bool $ignoreForwardingHeaders = false,
    ) {
        $this->sourceStamp = $sourceStamp ?? self::sourceStamp($applicationRoot);
        $this->directory = rtrim($cacheDirectory, '/\\') . '/early-pages-v1';
        $this->generation = $this->epoch();
        if (!$disabled && $this->generation === null) {
            try {
                $this->invalidate();
                $this->generation = $this->epoch();
            } catch (\Throwable) {
                $this->unavailable = true;
            }
        }
    }

    /** @param array<string, mixed> $server */
    public static function accepts(array $server, bool $ignoreForwardingHeaders = false): bool
    {
        return self::requestBypassReason($server, $ignoreForwardingHeaders) === null;
    }

    /** @param array<string,mixed> $server */
    private static function requestBypassReason(array $server, bool $ignoreForwardingHeaders): ?string
    {
        if (!in_array($server['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
            return 'method';
        }

        foreach (['HTTP_COOKIE', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_RANGE',
            'HTTP_X_REGISTER_NAVIGATION', 'HTTP_IF_MATCH', 'HTTP_IF_UNMODIFIED_SINCE', 'PHP_AUTH_USER', 'PHP_AUTH_PW'] as $header) {
            if (isset($server[$header]) && $server[$header] !== '') {
                return strtolower(str_replace('_', '-', $header));
            }
        }

        if (!$ignoreForwardingHeaders) {
            foreach (self::FORWARDED_HEADERS as $header) {
                if (isset($server[$header]) && $server[$header] !== '') {
                    return 'forwarded';
                }
            }
        }

        if (preg_match('/(?:no-cache|no-store|max-age\s*=\s*0)/i', (string)($server['HTTP_CACHE_CONTROL'] ?? '')) === 1
            || str_contains(strtolower((string)($server['HTTP_PRAGMA'] ?? '')), 'no-cache')) {
            return 'cache-control';
        }

        $uri = $server['REQUEST_URI'] ?? '';
        $host = $server['HTTP_HOST'] ?? '';
        if (!is_string($uri) || strlen($uri) > 2048 || !str_starts_with($uri, '/')
            || str_starts_with($uri, '//') || str_contains($uri, '?')
            || preg_match('/[\x00-\x20\x7f\\\\#]|%(?:00|0a|0d)/i', $uri) === 1) {
            return 'uri';
        }

        return is_string($host) && preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/iD', $host) === 1 ? null : 'host';
    }

    /**
     * @param array<string, mixed> $server
     * @return array{status:int,headers:array<string,list<string>>,body:string,content_id:?string}|null
     */
    public function lookup(array $server, ?int $now = null): ?array
    {
        if ($this->disabled || $this->unavailable || !self::accepts($server, $this->ignoreForwardingHeaders) || is_file($this->applicationRoot . '/.register-maintenance.json')) {
            return null;
        }

        try {
            $now ??= time();
            $entry = $this->read($this->path($server));
            if ($entry === null || ($entry['format'] ?? null) !== self::FORMAT
                || ($entry['generation'] ?? null) !== $this->epoch()
                || ($entry['fingerprint'] ?? null) !== $this->fingerprint()
                || !is_int($entry['expires'] ?? null) || $entry['expires'] <= $now
                || !is_string($entry['body'] ?? null) || !is_array($entry['headers'] ?? null)
                || !$this->validHeaders($entry['headers'])
                || !is_array($entry['views'] ?? null)) {
                return null;
            }

            $body = base64_decode($entry['body'], true);
            if (!is_string($body)) {
                return null;
            }

            if (($entry['body_compression'] ?? '') === 'gzip') {
                $body = function_exists('gzdecode') ? self::withoutWarnings(static fn(): string|false => gzdecode($body, self::MAX_BYTES)) : false;
                if (!is_string($body)) {
                    return null;
                }
            }

            $changed = false;
            foreach ($entry['views'] as $id => $hash) {
                $view = $this->read($this->viewPath((string)$id));
                if ($view === null || !is_string($view['html'] ?? null)
                    || ($view['fingerprint'] ?? null) !== $entry['fingerprint']) {
                    return null;
                }

                $changed = $changed || hash('sha256', $view['html']) !== $hash;
                $body = str_replace($this->viewMarker((string)$id), $view['html'], $body);
            }

            $headers = $entry['headers'];
            $headers['x-register-page-cache'] = ['hit'];
            $headers['x-register-early-cache'] = ['hit'];
            $headers['vary'] = array_values(array_unique([...($headers['vary'] ?? []), 'Accept-Encoding']));
            $headers['etag'] = ['W/"' . md5($body) . '"'];
            unset($headers['content-length'], $headers['content-encoding'], $headers['last-modified']);
            $status = 200;
            $validator = (string)($server['HTTP_IF_NONE_MATCH'] ?? '');
            if ($validator !== '' && ($validator === '*' || in_array(
                substr($headers['etag'][0], 2),
                array_map(static fn(string $tag): string => preg_replace('/^W\//', '', trim($tag)) ?? '', explode(',', $validator)),
                true,
            ))) {
                $status = 304;
                $body = '';
            } else {
                $encoding = $this->encoding((string)($server['HTTP_ACCEPT_ENCODING'] ?? ''));
                if ($encoding === null) {
                    return null;
                }

                if ($encoding === 'gzip' && function_exists('gzencode')) {
                    $compressed = !$changed && is_string($entry['gzip'] ?? null) ? base64_decode($entry['gzip'], true) : gzencode($body, 3);
                    if (!is_string($compressed)) {
                        return null;
                    }

                    $body = $compressed;
                    $headers['content-encoding'] = ['gzip'];
                }

                $headers['content-length'] = [(string)strlen($body)];
            }

            if (($entry['generation'] ?? null) !== $this->epoch()) {
                return null;
            }

            return ['status' => $status, 'headers' => $headers, 'body' => $body,
                'content_id' => is_string($entry['content_id'] ?? null) ? $entry['content_id'] : null];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $server */
    public function serve(array $server, ?\Closure $beforeSend = null): bool
    {
        if ($this->requiresEngine()) {
            return false;
        }

        $hit = $this->lookup($server);
        if ($hit === null || headers_sent()) {
            return false;
        }

        if ($beforeSend !== null && !$beforeSend($hit)) {
            return false;
        }

        header_remove('X-Powered-By');
        http_response_code($hit['status']);
        foreach ($hit['headers'] as $name => $values) {
            foreach ($values as $i => $value) {
                header($name . ': ' . $value, $i === 0);
            }
        }

        if (($server['REQUEST_METHOD'] ?? '') !== 'HEAD') {
            echo $hit['body'];
        }

        return true;
    }

    /** One request periodically visits the normal scheduler, even on an all-cache-hit site. */
    public function requiresEngine(?int $now = null): bool
    {
        if ($this->disabled || $this->unavailable) {
            return true;
        }

        $now ??= time();
        $next = $this->read($this->directory . '/engine-pulse.json')['next'] ?? 0;
        if (is_int($next) && $next > $now) {
            return false;
        }

        $lock = self::withoutWarnings(fn() => fopen($this->directory . '/engine-pulse.lock', 'c+b'));
        if ($lock === false) {
            return true;
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }

        self::withoutWarnings(fn(): bool => chmod($this->directory . '/engine-pulse.lock', 0600));
        $this->maintenanceLock = $lock;
        return true;
    }

    public function engineFinished(?int $now = null): void
    {
        if ($this->disabled || $this->unavailable) {
            return;
        }

        $now ??= time();
        try {
            $next = $this->read($this->directory . '/engine-pulse.json')['next'] ?? 0;
            if (!is_int($next) || $next <= $now + 5) {
                $this->write($this->directory . '/engine-pulse.json', ['next' => $now + 10]);
                $this->collect($now);
            }
        } finally {
            if (is_resource($this->maintenanceLock)) {
                $lock = $this->maintenanceLock;
                $this->maintenanceLock = null;
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, list<string|null>> $headers
     * @param array<string, array{html:string,count:int}> $views
     */
    public function store(array $server, array $headers, string $body, array $views = [], ?string $contentId = null, ?int $validUntil = null, ?int $now = null): bool
    {
        $headers = $this->normalizeHeaders($headers);
        $requestBypass = self::requestBypassReason($server, $this->ignoreForwardingHeaders);
        if ($requestBypass !== null) {
            $this->decision = 'bypass-request-' . $requestBypass;
            return false;
        }

        $responseBypass = match (true) {
            $this->disabled => 'disabled',
            $this->unavailable => 'storage',
            ($server['REQUEST_METHOD'] ?? '') !== 'GET' => 'method',
            strlen($body) > self::MAX_BYTES / 2 => 'size',
            isset($headers['set-cookie']) => 'set-cookie',
            !isset($headers['x-register-page-cache']) => 'unshared',
            !str_starts_with(strtolower($headers['content-type'][0] ?? ''), 'text/html') => 'content-type',
            str_contains(strtolower(implode(',', $headers['cache-control'] ?? [])), 'no-store') => 'private',
            str_contains(implode(' ', $headers['content-security-policy'] ?? []), "'nonce-")
                || preg_match('/\bnonce\s*=/i', $body) === 1 => 'nonce',
            default => null,
        };
        if ($responseBypass !== null) {
            $this->decision = 'bypass-response-' . $responseBypass;
            return false;
        }

        foreach (explode(',', implode(',', $headers['vary'] ?? [])) as $vary) {
            if (!in_array(strtolower(trim($vary)), ['', 'accept-encoding', 'x-register-navigation', 'cookie'], true)) {
                $this->decision = 'bypass-vary';
                return false;
            }
        }

        try {
            $now ??= time();
            $expires = min($now + self::MAX_AGE, $validUntil ?? PHP_INT_MAX);
            if ($expires <= $now) {
                $this->decision = 'bypass-expired';
                return false;
            }

            $epoch = $this->generation;
            if ($epoch === null || $epoch !== $this->epoch()) {
                $this->decision = 'bypass-generation';
                return false;
            }

            $gzip = function_exists('gzencode') ? gzencode($body, 3) : false;
            $viewHashes = [];
            foreach ($views as $id => $view) {
                if (!str_contains($body, $view['html'])) {
                    continue;
                }

                $this->publishView($id, $view['count'], $view['html']);
                $body = str_replace($view['html'], $this->viewMarker($id), $body);
                $viewHashes[$id] = hash('sha256', $view['html']);
            }

            foreach (['date', 'expires', 'content-length', 'content-encoding', 'x-register-page-cache-key',
                'x-register-rendered-content', 'x-register-cache-deadline', 'server-timing'] as $header) {
                unset($headers[$header]);
            }

            $packed = function_exists('gzencode') ? gzencode($body, 3) : false;
            $entry = ['format' => self::FORMAT, 'generation' => $epoch, 'fingerprint' => $this->fingerprint(),
                'expires' => $expires, 'headers' => $headers, 'body' => base64_encode(is_string($packed) ? $packed : $body),
                'body_compression' => is_string($packed) ? 'gzip' : '',
                'gzip' => is_string($gzip) ? base64_encode($gzip) : null, 'views' => $viewHashes, 'content_id' => $contentId];
            if (!$this->storeSnapshot($this->path($server), $entry, $now)) {
                $this->decision = 'bypass-capacity';
                return false;
            }

            $this->decision = 'stored';
            return $epoch === $this->epoch();
        } catch (\Throwable) {
            $this->decision = 'bypass-unavailable';
            return false;
        }
    }

    public function invalidate(): void
    {
        if ($this->disabled || $this->unavailable) {
            return;
        }

        $this->write($this->directory . '/generation.json', ['value' => bin2hex(random_bytes(16))]);
        // Do not let an in-flight renderer adopt a newer generation after invalidation.
    }

    public function publishView(string $contentId, int $count, string $html): void
    {
        if ($this->disabled || $this->unavailable || $count < 0 || preg_match('/^(?:post|page):[1-9][0-9]*$/D', $contentId) !== 1) {
            return;
        }

        $path = $this->viewPath($contentId);
        $parent = dirname($path);
        if (!is_dir($parent) && !self::withoutWarnings(static fn(): bool => mkdir($parent, 0700, true)) && !is_dir($parent)) {
            throw new \RuntimeException('Unable to create the public-counter directory.');
        }

        $lock = self::withoutWarnings(static fn() => fopen($path . '.lock', 'c+b'));
        if ($lock === false) {
            throw new \RuntimeException('Unable to lock a public counter.');
        }

        try {
            self::withoutWarnings(static fn(): bool => chmod($path . '.lock', 0600));
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock a public counter.');
            }

            $fingerprint = $this->fingerprint();
            $epoch = $this->epoch();
            $old = $this->read($path);
            $oldCount = $old['count'] ?? null;
            if (($old['fingerprint'] ?? null) === $fingerprint && ($old['generation'] ?? null) === $epoch
                && is_int($oldCount) && ($oldCount > $count || ($oldCount === $count && ($old['html'] ?? null) === $html))) {
                return;
            }

            $this->write($path, ['fingerprint' => $fingerprint, 'generation' => $epoch, 'count' => $count, 'html' => $html]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $server */
    private function path(array $server): string
    {
        // Match the normal request parser: X-HTTPS is not a trusted transport signal.
        $https = (string)($server['HTTPS'] ?? '');
        $secure = $https !== '' && $https !== '0' && strtolower($https) !== 'off';
        $key = hash('sha256', implode("\0", [strtolower((string)$server['HTTP_HOST']), $secure ? 'https' : 'http',
            (string)$server['REQUEST_URI'], (string)($server['REGISTER_EARLY_VARIANT'] ?? 'reader')]));
        return $this->directory . '/' . substr($key, 0, 2) . '/' . $key . '.json';
    }

    /** @param array<string, mixed> $entry */
    private function storeSnapshot(string $path, array $entry, int $now): bool
    {
        $bytes = strlen(json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($bytes > self::MAX_BUCKET_BYTES) {
            return false;
        }

        return $this->withDirectoryLock(dirname($path), function () use ($path, $entry, $bytes, $now): bool {
            $removed = 0;
            if (!$this->trimBucket(dirname($path), $now, $removed, $path, $bytes)) {
                return false;
            }

            $this->write($path, $entry);
            return true;
        });
    }

    /** Retire one bucket per engine pulse, including snapshots that are never requested again. */
    public function collect(?int $now = null): int
    {
        if ($this->disabled || $this->unavailable) {
            return 0;
        }

        $now ??= time();
        $removed = 0;
        try {
            $this->withDirectoryLock($this->directory, function () use ($now, &$removed): bool {
                $cursorPath = $this->directory . '/cleanup.json';
                $cursor = $this->read($cursorPath)['bucket'] ?? 0;
                $cursor = is_int($cursor) && $cursor >= 0 && $cursor < 256 ? $cursor : 0;

                $bucket = $this->directory . '/' . sprintf('%02x', $cursor);
                $complete = !is_dir($bucket) || $this->withDirectoryLock($bucket, function () use ($bucket, $now, &$removed): bool {
                    return $this->trimBucket($bucket, $now, $removed);
                });
                $this->write($cursorPath, ['bucket' => $complete ? ($cursor + 1) % 256 : $cursor]);
                return true;
            });
        } catch (\Throwable) {
            // Disk maintenance is optional; normal rendering must remain available.
        }

        return $removed;
    }

    /**
     * Inspect at most 128 old files per call; legacy unbounded buckets drain over several pulses.
     * The caller holds the bucket lock through pruning and publication, so concurrent writers
     * cannot each claim the same free space or race cleanup against a replacement.
     */
    private function trimBucket(string $directory, int $now, int &$removed, ?string $replacement = null, int $reservedBytes = 0): bool
    {
        $count = $replacement === null ? 0 : 1;
        $bytes = $reservedBytes;
        $inspected = 0;
        $epoch = $this->epoch();
        $fingerprint = $this->fingerprint();
        foreach (new \DirectoryIterator($directory) as $file) {
            if (preg_match('/^[a-f0-9]{64}\.json$/D', $file->getFilename()) !== 1) {
                continue;
            }

            if (++$inspected > self::MAX_BUCKET_SCAN) {
                return false;
            }

            $path = $file->getPathname();
            if ($path === $replacement) {
                continue;
            }

            $size = $file->isLink() ? 0 : $file->getSize();
            if ($size === false) {
                return false;
            }

            $entry = $count >= self::MAX_BUCKET_ENTRIES || $bytes + $size > self::MAX_BUCKET_BYTES ? null : $this->read($path);
            if ($entry === null || ($entry['format'] ?? null) !== self::FORMAT
                || ($entry['generation'] ?? null) !== $epoch || ($entry['fingerprint'] ?? null) !== $fingerprint
                || !is_int($entry['expires'] ?? null) || $entry['expires'] <= $now) {
                if (!self::withoutWarnings(static fn(): bool => unlink($path))) {
                    return false;
                }

                ++$removed;
                continue;
            }

            ++$count;
            $bytes += $size;
        }

        return true;
    }

    /** @param \Closure(): bool $operation */
    private function withDirectoryLock(string $directory, \Closure $operation): bool
    {
        if (is_link($directory)
            || (!is_dir($directory) && !self::withoutWarnings(static fn(): bool => mkdir($directory, 0700, true)) && !is_dir($directory))) {
            return false;
        }

        $path = $directory . '/bucket.lock';
        if (is_link($path)) {
            return false;
        }

        $lock = self::withoutWarnings(static fn() => fopen($path, 'c+b'));
        if ($lock === false) {
            return false;
        }

        try {
            self::withoutWarnings(static fn(): bool => chmod($path, 0600));
            return flock($lock, LOCK_EX | LOCK_NB) && $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function fingerprint(): string
    {
        $env = getenv('APP_ENV');
        $config = $this->applicationRoot . '/config' . (is_string($env) && $env !== '' ? '.' . $env : '') . '.php';
        $hashes = [self::FORMAT, $this->applicationRoot, $this->sourceStamp, $this->ignoreForwardingHeaders ? 'ignore-forwarded' : 'respect-forwarded'];
        foreach ([$config, $this->applicationRoot . '/register-build.json', $this->cacheDirectory . '/register_config.php'] as $path) {
            $hashes[] = is_file($path) ? self::withoutWarnings(static fn(): string|false => hash_file('sha256', $path)) : '';
        }

        return hash('sha256', implode(':', $hashes));
    }

    private function epoch(): ?string
    {
        $value = $this->read($this->directory . '/generation.json')['value'] ?? null;
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1 ? $value : null;
    }

    private function viewMarker(string $id): string
    {
        return '<!--register-early-view:' . $id . '-->';
    }

    private function viewPath(string $id): string
    {
        return $this->directory . '/views/' . hash('sha256', $id) . '.json';
    }

    /** @return array<string, mixed>|null */
    private function read(string $path): ?array
    {
        if (is_link($path) || !is_file($path) || self::withoutWarnings(static fn(): int|false => filesize($path)) > self::MAX_BYTES) {
            return null;
        }

        $json = self::withoutWarnings(static fn(): string|false => file_get_contents($path));
        $data = is_string($json) ? json_decode($json, true, 16) : null;
        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !self::withoutWarnings(static fn(): bool => mkdir($directory, 0700, true)) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the early-cache directory.');
        }

        $temporary = self::withoutWarnings(static fn(): string|false => tempnam($directory, '.write-'));
        if (!is_string($temporary)) {
            throw new \RuntimeException('Unable to stage an early-cache entry.');
        }

        try {
            self::withoutWarnings(static fn(): bool => chmod($temporary, 0600));
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > self::MAX_BYTES
                || self::withoutWarnings(static fn(): int|false => file_put_contents($temporary, $json)) !== strlen($json)
                || !self::withoutWarnings(static fn(): bool => rename($temporary, $path))) {
                throw new \RuntimeException('Unable to publish an early-cache entry.');
            }
        } finally {
            if (is_file($temporary)) {
                self::withoutWarnings(static fn(): bool => unlink($temporary));
            }
        }
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private static function withoutWarnings(\Closure $operation): mixed
    {
        // Cache I/O must fail back to the engine, never leak warnings into public HTML.
        set_error_handler(static fn(): bool => true, E_WARNING | E_NOTICE);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string,list<string|null>> $headers
     * @return array<string,list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[$name] = [];
            foreach ($values as $value) {
                if ($value !== null) {
                    $normalized[$name][] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $headers
     * @phpstan-assert-if-true array<string,list<string>> $headers
     * @psalm-assert-if-true array<string,list<string>> $headers
     */
    private function validHeaders(array $headers): bool
    {
        foreach ($headers as $name => $values) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1
                || $name === 'set-cookie' || !is_array($values) || !array_is_list($values)) {
                return false;
            }

            foreach ($values as $value) {
                if (!is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                    return false;
                }
            }
        }

        return true;
    }

    private function encoding(string $header): ?string
    {
        $weights = [];
        foreach (explode(',', strtolower($header)) as $part) {
            $bits = array_map(trim(...), explode(';', $part));
            $weight = 1.0;
            foreach (array_slice($bits, 1) as $parameter) {
                if (str_starts_with($parameter, 'q=')) {
                    $weight = is_numeric(substr($parameter, 2)) ? max(0.0, min(1.0, (float)substr($parameter, 2))) : 0.0;
                }
            }

            $weights[$bits[0]] = $weight;
        }

        $gzip = $weights['gzip'] ?? $weights['*'] ?? 0.0;
        $identity = $weights['identity'] ?? (($weights['*'] ?? 1.0) === 0.0 ? 0.0 : 1.0);
        return $gzip > 0.0 && $gzip >= $identity && function_exists('gzencode') ? 'gzip' : ($identity > 0.0 ? 'identity' : null);
    }
}
