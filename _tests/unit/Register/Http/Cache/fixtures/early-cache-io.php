<?php

declare(strict_types = 1);

// Isolated wrappers count logical reads, including reads satisfied by the OS cache.
namespace Register\Core\Http\Cache {
    function file_get_contents(
        string $path,
        bool $useIncludePath = false,
        mixed $context = null,
        int $offset = 0,
        ?int $length = null,
    ): string|false {
        if ($length !== null && $length < 0) {
            throw new \InvalidArgumentException('The measured read length must be nonnegative.');
        }

        $contents = \file_get_contents($path, $useIncludePath, $context, $offset, $length);
        $bytes = \is_string($contents) ? \strlen($contents) : 0;
        ++\Helper\CacheIoProbe::$reads;
        \Helper\CacheIoProbe::$readBytes += $bytes;
        if (\preg_match('~/[a-f0-9]{64}\.json$~D', $path) === 1) {
            ++\Helper\CacheIoProbe::$snapshotReads;
            \Helper\CacheIoProbe::$snapshotBytes += $bytes;
        }

        return $contents;
    }

    function hash_file(string $algorithm, string $path): string|false
    {
        ++\Helper\CacheIoProbe::$reads;
        \Helper\CacheIoProbe::$readBytes += (int)\filesize($path);
        return \hash_file($algorithm, $path);
    }

    function file_put_contents(string $path, mixed $contents): int|false
    {
        ++\Helper\CacheIoProbe::$writes;
        \Helper\CacheIoProbe::$writeBytes += \is_string($contents) ? \strlen($contents) : 0;
        return \file_put_contents($path, $contents);
    }
}

namespace {
    use Helper\CacheIoProbe;

    $repository = $argv[1] ?? throw new \InvalidArgumentException('The repository root is required.');
    require $repository . '/_vendor/autoload.php';
    $root = sys_get_temp_dir() . '/register-cache-io-' . bin2hex(random_bytes(8));
    mkdir($root . '/cache', 0700, true);
    try {
        file_put_contents($root . '/config.php', '<?php return [];');
        file_put_contents($root . '/register-build.json', '{"commit":"fixture"}');
        file_put_contents($root . '/cache/register_config.php', str_repeat('configuration fixture ', 2800));
        $cache = new Register\Core\Http\Cache\EarlyPageCache(
            $root . '/cache', $root, sourceStamp: hash('sha256', 'fixture'),
        );
        $urls = [];
        for ($i = 0; count($urls) < 32; ++$i) {
            $uri = '/all/entry-' . $i;
            $key = hash('sha256', implode("\0", ['example.test', 'https', $uri, 'reader']));
            if (str_starts_with($key, '7f')) {
                $urls[] = $uri;
            }
        }

        $server = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test', 'HTTPS' => 'on', 'REQUEST_URI' => $urls[0]];
        $headers = ['content-type' => ['text/html; charset=UTF-8'], 'x-register-page-cache' => ['miss'],
            'cache-control' => ['public, max-age=60']];
        $body = '<main>';
        for ($i = 0; $i < 280; ++$i) {
            $body .= '<p>Article paragraph ' . $i . ' ' . hash('sha256', 'paragraph-' . $i) . '</p>';
        }

        $body .= '</main>';
        $now = time();
        CacheIoProbe::reset();
        $cache->store($server, $headers, $body, validUntil: $now + 3600, now: $now);
        $empty = CacheIoProbe::result();
        foreach (array_slice($urls, 1, 30) as $url) {
            $server['REQUEST_URI'] = $url;
            $cache->store($server, $headers, $body, validUntil: $now + 3600, now: $now);
        }

        $server['REQUEST_URI'] = $urls[31];
        CacheIoProbe::reset();
        $start = hrtime(true);
        $stored = $cache->store($server, $headers, $body, validUntil: $now + 3600, now: $now);
        $full = CacheIoProbe::result();
        $full['wall_ms'] = (float)(hrtime(true) - $start) / 1e6;
        file_put_contents($root . '/cache/early-pages-v1/cleanup.json', '{"bucket":127}');
        CacheIoProbe::reset();
        $start = hrtime(true);
        $removed = $cache->collect($now);
        $collection = CacheIoProbe::result();
        $collection['wall_ms'] = (float)(hrtime(true) - $start) / 1e6;
        echo json_encode([
            'body_bytes' => strlen($body), 'stored' => $stored, 'empty_store' => $empty,
            'full_bucket_store' => $full, 'collect_removed' => $removed, 'collect' => $collection,
            'body_matches' => ($cache->lookup($server, $now)['body'] ?? null) === $body,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
    } finally {
        (new Symfony\Component\Filesystem\Filesystem())->remove($root);
    }
}
