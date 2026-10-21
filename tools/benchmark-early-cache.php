<?php
/** Measures server CPU in an isolated installation, never against a live site's database. */

declare(strict_types = 1);

use Register\Content\ContentIdAllocator;
use Register\Content\ContentSchema;
use Register\Core\Framework\Container;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PDO;
use Register\Model\Installer;
use Register\Module\BaseModuleInstaller;
use Register\Module\BaseModuleRegistry;
use Register\Schema\SchemaManager;
use Register\Schema\SchemaMigrator;
use Symfony\Component\Filesystem\Filesystem;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This benchmark is CLI-only.');
}

$root = dirname(__DIR__);
require $root . '/_vendor/autoload.php';
$options = getopt('', ['requests:', 'keep']);
$requestOption = $options['requests'] ?? '200';
if (!is_string($requestOption) || !ctype_digit($requestOption)) {
    throw new InvalidArgumentException('--requests must be a single positive integer.');
}

$requests = (int)$requestOption;
if ($requests < 10 || $requests > 5000) {
    throw new InvalidArgumentException('--requests must be between 10 and 5000.');
}

$fixture = sys_get_temp_dir() . '/register-early-benchmark-' . bin2hex(random_bytes(8));
mkdir($fixture . '/_include', 0700, true);
mkdir($fixture . '/_cache', 0700);
$server = null;
$failed = false;
$filesystem = new Filesystem();
try {
    foreach (['index.php', 'service-worker.js', '_include/common.php', '_include/setup.php', '_include/early-page-cache.php'] as $file) {
        copy($root . '/' . $file, $fixture . '/' . $file);
    }

    foreach (['_vendor', '_styles', '_assets', '_lang', '_extensions', '_include/src', '_include/views', '_include/templates'] as $path) {
        if (is_dir($root . '/' . $path)) {
            symlink($root . '/' . $path, $fixture . '/' . $path);
        }
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve a local benchmark port.');
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address)) {
        throw new RuntimeException('Unable to inspect the local benchmark port.');
    }

    $baseUrl = 'http://' . $address;
    file_put_contents($fixture . '/config.php', '<?php return ' . var_export([
        'database' => ['type' => 'sqlite', 'host' => '', 'user' => '', 'password' => '', 'name' => 'fixture.sqlite', 'prefix' => ''],
        'http' => ['base_url' => $baseUrl, 'base_path' => '', 'url_prefix' => ''],
        'files' => ['cache_dir' => $fixture . '/_cache/'],
        'options' => ['debug' => false],
        'security' => ['secret_file' => $fixture . '/_cache/secrets.php', 'antispam_secret' => str_repeat('a', 64)],
        'backups' => ['enabled' => false],
    ], true) . ';');
    $pdo = new PDO('sqlite:' . $fixture . '/fixture.sqlite');
    $db = new DbLayerSqlite($pdo);
    $installer = new Installer($db);
    $installer->createTables();
    $installer->insertConfigData('Cache benchmark', 'admin@example.test', 'English');
    $container = new Container(['db_prefix' => '']);
    $container->set(\PDO::class, $pdo);
    (new SchemaManager($db, $container, new BaseModuleInstaller(new BaseModuleRegistry()), new SchemaMigrator($db, [])))->ensureCurrent();
    $installer->insertMainPage('Cache benchmark', time());
    $ids = [];
    for ($i = 1; $i <= 12; ++$i) {
        $id = ContentIdAllocator::allocate($db);
        $ids[$i] = $id;
        $stmt = $pdo->prepare('INSERT INTO ' . ContentSchema::TABLE_NAME . ' (id,content_type,slug_scope,slug,title,excerpt,body,created_at,published_at,updated_at,published,comments_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        if ($stmt === false) {
            throw new RuntimeException('Unable to prepare the fixture article insert.');
        }

        $stmt->execute([$id, 'post', 'root', 'benchmark-' . $i, 'Benchmark page ' . $i, '',
            str_repeat('<p>Public benchmark article. No private or site-specific content.</p>', 100)
                . ($i === 12 ? '<style>.benchmark { color: inherit; }</style>' : ''),
            time() - 3600, time() - 3600, time(), 1, $i === 11 ? 1 : 0]);
    }

    $router = <<<'PHP'
<?php
$usageStart = getrusage();
register_shutdown_function(static function () use ($usageStart): void {
    $end = getrusage();
    $cpu = static fn(array $u): float => ($u['ru_utime.tv_sec'] + $u['ru_stime.tv_sec']) * 1000.0
        + ($u['ru_utime.tv_usec'] + $u['ru_stime.tv_usec']) / 1000.0;
    file_put_contents(__DIR__ . '/metrics.jsonl', json_encode([
        'cpu_ms' => $cpu($end) - $cpu($usageStart),
        'engine_loaded' => class_exists('Register\Core\Framework\Application', false),
        'included_files' => count(get_included_files()),
    ]) . "\n", FILE_APPEND | LOCK_EX);
});
require __DIR__ . '/index.php';
PHP;
    file_put_contents($fixture . '/router.php', $router);
    $environment = getenv();
    $environment['APP_ENV'] = '';
    unset($environment['PHP_CLI_SERVER_WORKERS']);
    $server = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.revalidate_freq=0', '-S', $address, '-t', $fixture, $fixture . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $fixture . '/server.log', 'a'], 2 => ['file', $fixture . '/server.log', 'a']], $pipes, $fixture, $environment);
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start the isolated PHP server.');
    }

    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        $connection = @stream_socket_client('tcp://' . $address, timeout: 0.02);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }

        usleep(20_000);
    }

    if (!$ready) {
        throw new RuntimeException('The isolated PHP server did not start.');
    }

    $expectedViews = 0;
    $send = static function (string $path, array $headers = [], int $expectedStatus = 200) use ($baseUrl, &$expectedViews): array {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20,
            'header' => implode("\r\n", array_merge(['Connection: close'], $headers))]]);
        $stream = fopen($baseUrl . $path, 'rb', false, $context);
        if (!is_resource($stream)) {
            throw new RuntimeException('A benchmark HTTP connection failed.');
        }

        $metadata = stream_get_meta_data($stream);
        $responseHeaders = $metadata['wrapper_data'] ?? [];
        $body = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($body)) {
            throw new RuntimeException('A benchmark HTTP request failed.');
        }

        $parsed = [];
        foreach ($responseHeaders as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower($name)] = trim($value);
            } else {
                $parsed['status'] = $line;
            }
        }

        if (!str_contains($parsed['status'] ?? '', ' ' . $expectedStatus . ' ')) {
            throw new RuntimeException('Unexpected benchmark response: ' . ($parsed['status'] ?? 'missing status') . ' ' . substr(strip_tags($body), 0, 300));
        }

        $checkBody = ($parsed['content-encoding'] ?? '') === 'gzip' ? gzdecode($body) : $body;
        if ($expectedStatus === 200) {
            if (!is_string($checkBody) || !str_contains($checkBody, 'Benchmark page')) {
                throw new RuntimeException('The benchmark did not render the fixture article: ' . substr(strip_tags((string)$checkBody), 0, 300));
            }

            if (preg_match('#^/benchmark-(?:[1-9]|10)$#D', $path) === 1
                && in_array('User-Agent: Mozilla/5.0 benchmark reader', $headers, true)) {
                ++$expectedViews;
            }
        }

        return ['headers' => $parsed, 'body' => $body];
    };
    $readMetrics = static function (int $count) use ($fixture): array {
        for ($i = 0; $i < 100; ++$i) {
            $lines = file($fixture . '/metrics.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines) && count($lines) >= $count) {
                return array_map(static fn(string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines);
            }

            usleep(10_000);
        }

        throw new RuntimeException('Server CPU measurements did not finish.');
    };
    // A first request without a locator must not seed the wrong representation.
    $send('/benchmark-1', ['User-Agent: SyntheticCrawler/1.0']);
    $initialReader = $send('/benchmark-1', ['User-Agent: Mozilla/5.0 benchmark reader']);
    $checks = ['cold_crawler_cannot_seed_reader_snapshot' => str_contains($initialReader['body'], 'name="register-analytics"')];
    $send('/benchmark-2', ['User-Agent: Mozilla/5.0 benchmark reader']);
    $send('/benchmark-2', ['User-Agent: Mozilla/5.0 benchmark reader']);
    $queryReply = $send('/benchmark-2?utm_source=probe', ['User-Agent: SyntheticCrawler/1.0']);
    $checks['query_cannot_publish_clean_url_snapshot'] = !in_array($queryReply['headers']['x-register-early-cache'] ?? '', ['hit', 'stored'], true);
    $cleanReader = $send('/benchmark-2', ['User-Agent: Mozilla/5.0 benchmark reader']);
    $checks['query_cannot_poison_reader_variant'] = str_contains($cleanReader['body'], 'name="register-analytics"');
    $forwardedHeaders = ['User-Agent: Mozilla/5.0 benchmark reader', 'X-Forwarded-Proto: https'];
    $send('/benchmark-3', $forwardedHeaders);
    $send('/benchmark-3', $forwardedHeaders);
    $forwardedReply = $send('/benchmark-3', $forwardedHeaders);
    $checks['forwarded_public_request_can_be_cached'] = ($forwardedReply['headers']['x-register-early-cache'] ?? '') === 'hit';
    $readMetrics(9);
    $scenarios = [];
    foreach (['reader' => 'Mozilla/5.0 benchmark reader', 'crawler' => 'SyntheticCrawler/1.0'] as $kind => $agent) {
        foreach (['identity', 'gzip'] as $encoding) {
            file_put_contents($fixture . '/metrics.jsonl', '');
            $headers = ['User-Agent: ' . $agent, 'Accept-Encoding: ' . $encoding];
            for ($i = 0; $i < 30; ++$i) {
                $send('/benchmark-' . (($i % 10) + 1), $headers);
            }

            // Wait for the last warm-up's shutdown callback before clearing its measurements.
            $readMetrics(30);
            foreach (['engine' => ['Cache-Control: no-cache'], 'early' => []] as $mode => $extra) {
                file_put_contents($fixture . '/metrics.jsonl', '');
                $hits = 0;
                for ($i = 0; $i < $requests; ++$i) {
                    $reply = $send('/benchmark-' . (($i % 10) + 1), array_merge($headers, $extra));
                    if ($i === 0) {
                        file_put_contents($fixture . '/' . $kind . '-' . $encoding . '-' . $mode . '.json', json_encode($reply['headers'], JSON_THROW_ON_ERROR));
                    }

                    $hits += ($reply['headers']['x-register-early-cache'] ?? '') === 'hit' ? 1 : 0;
                }

                $metrics = $readMetrics($requests);
                $samples = array_column($metrics, 'cpu_ms');
                sort($samples);
                $scenarios[$kind . '_' . $encoding][$mode] = [
                    'requests' => count($samples), 'early_hits' => $hits,
                    'cpu_ms_total' => (float)array_sum($samples), 'cpu_ms_mean' => (float)array_sum($samples) / (float)count($samples),
                    'cpu_ms_median' => $samples[(int)floor(count($samples) / 2)],
                    'cpu_ms_p95' => $samples[min(count($samples) - 1, (int)floor((float)count($samples) * 0.95))],
                    'engine_loaded' => count(array_filter($metrics, static fn(array $sample): bool => $sample['engine_loaded'])),
                    'included_files_mean' => (float)array_sum(array_column($metrics, 'included_files')) / (float)count($samples),
                ];
            }
        }
    }

    foreach (['/benchmark-11' => 'private_comment_form', '/benchmark-12' => 'fresh_csp_nonce'] as $path => $name) {
        $send($path, ['User-Agent: Mozilla/5.0 benchmark reader']);
        $reply = $send($path, ['User-Agent: Mozilla/5.0 benchmark reader']);
        $checks[$name] = ($reply['headers']['x-register-early-cache'] ?? '') !== 'hit';
    }

    foreach (['Cookie: session=anything', 'Authorization: Bearer anything', 'X-Register-Navigation: partial'] as $header) {
        if (str_starts_with($header, 'X-Register-Navigation')) {
            continue; // JSON partial responses are covered by integration tests.
        }

        $reply = $send('/benchmark-1', ['User-Agent: Mozilla/5.0 benchmark reader', $header]);
        $checks[explode(':', $header, 2)[0] . '_bypasses'] = ($reply['headers']['x-register-early-cache'] ?? '') !== 'hit';
    }

    $readMetrics($requests + 6);
    $spool = new \Register\Content\ContentViewSpool($fixture . '/_cache/content-view-spool', minimumSegmentAge: 0);
    $spool->sealDue(time() + 20);
    $pending = 0;
    foreach ($spool->sealedSegments(128) as $segment) {
        foreach ($spool->readSegment($segment)['increments'] as $increment) {
            if (in_array($increment->contentId->value, array_slice(array_values($ids), 0, 10), true)) {
                $pending += $increment->views;
            }
        }
    }

    $viewQuery = $pdo->query('SELECT COALESCE(SUM(views),0) FROM content_views_daily WHERE content_id IN (' . implode(',', array_slice(array_values($ids), 0, 10)) . ')');
    if ($viewQuery === false) {
        throw new RuntimeException('Unable to read fixture view totals.');
    }

    $persisted = (int)$viewQuery->fetchColumn();
    $accountedViews = $expectedViews;
    $checks['all_reader_views_preserved'] = $expectedViews === $persisted + $pending;

    // Exercise the real product mutation/invalidation path in this fixture only.
    $app = require $fixture . '/_include/common.php';
    if (!$app instanceof \Register\Core\Framework\Application) {
        throw new RuntimeException('The fixture application did not start.');
    }

    $processor = $app->container->get(\Register\Content\ContentViewSpoolProcessor::class);
    foreach ($spool->sealedSegments(128) as $segment) {
        $processor->process($segment);
    }

    $currentCount = $app->container->get(\Register\Content\ContentViewRepository::class)->total(\Register\Content\ContentId::post($ids[2]));
    $refreshed = $send('/benchmark-2', ['User-Agent: Mozilla/5.0 benchmark reader']);
    $checks['flushed_totals_refresh_early_snapshot'] = ($refreshed['headers']['x-register-early-cache'] ?? '') === 'hit'
        && str_contains($refreshed['body'], 'data-view-content="post:' . $ids[2] . '"')
        && str_contains($refreshed['body'], 'aria-hidden="true">' . $currentCount . '</span>');
    $appPdo = $app->container->get(\PDO::class);
    $appPdo->beginTransaction();
    $appPdo->exec("UPDATE content SET body='<p>Updated benchmark article</p>' WHERE id=" . $ids[1]);
    $app->container->get(\Register\Content\ContentChangeDispatcher::class)->dispatch(\Register\Content\ContentId::post($ids[1]));
    $appPdo->commit();
    $updated = $send('/benchmark-1', ['User-Agent: Mozilla/5.0 benchmark reader']);
    $checks['content_edit_invalidates'] = str_contains($updated['body'], 'Updated benchmark article')
        && ($updated['headers']['x-register-early-cache'] ?? '') !== 'hit';
    $appPdo->beginTransaction();
    $appPdo->exec('UPDATE content SET published=0 WHERE id=' . $ids[1]);
    $app->container->get(\Register\Content\ContentChangeDispatcher::class)->dispatch(\Register\Content\ContentId::post($ids[1]));
    $appPdo->commit();
    $hidden = $send('/benchmark-1', ['User-Agent: Mozilla/5.0 benchmark reader'], 404);
    $checks['unpublishing_removes_public_snapshot'] = !str_contains($hidden['body'], 'Updated benchmark article')
        && ($hidden['headers']['x-register-early-cache'] ?? '') !== 'hit';
    foreach ($scenarios as &$scenario) {
        $scenario['cpu_reduction_percent'] = 100.0 * (1.0 - $scenario['early']['cpu_ms_total'] / $scenario['engine']['cpu_ms_total']);
        if ($scenario['early']['early_hits'] !== $requests || $scenario['early']['engine_loaded'] !== 0) {
            throw new RuntimeException('The measured early responses did not all bypass the engine: ' . json_encode($scenario, JSON_THROW_ON_ERROR));
        }
    }

    unset($scenario);
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('Early-cache safety checks failed: ' . implode(', ', array_keys($checks, false, true)));
    }

    echo json_encode(['php' => PHP_VERSION, 'cpu_source' => 'server getrusage user+system per request including bootstrap and shutdown',
        'fixture_pages' => 10, 'opcache_cli' => true, 'scenarios' => $scenarios, 'safety_checks' => $checks,
        'accounted_reader_views' => ['expected' => $accountedViews, 'database' => $persisted, 'pending_spool' => $pending],
        'fixture_directory' => isset($options['keep']) ? $fixture : null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . "\n");
    $failed = true;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }

    if (!isset($options['keep'])) {
        $filesystem->remove($fixture);
    } else {
        fwrite(STDERR, 'Benchmark fixture retained: ' . $fixture . "\n");
    }
}

if ($failed) {
    exit(1);
}
