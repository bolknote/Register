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

$options = getopt('', ['requests:', 'keep', 'assert-fast', 'root:', 'base-path:']);
$rootOption = $options['root'] ?? dirname(__DIR__);
$root = is_string($rootOption) ? realpath($rootOption) : false;
if ($root === false || !is_file($root . '/_vendor/autoload.php')) {
    throw new InvalidArgumentException('The benchmark source root is invalid.');
}

require $root . '/_vendor/autoload.php';
$basePath = $options['base-path'] ?? '';
if (!is_string($basePath) || preg_match('#^(?:/[a-zA-Z0-9_-]+)*$#D', $basePath) !== 1) {
    throw new InvalidArgumentException('The benchmark base path is invalid.');
}

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

    $origin = 'http://' . $address;
    $baseUrl = $origin . $basePath;
    file_put_contents($fixture . '/config.php', '<?php return ' . var_export([
        'database' => ['type' => 'sqlite', 'host' => '', 'user' => '', 'password' => '', 'name' => 'fixture.sqlite', 'prefix' => ''],
        'http' => ['base_url' => $baseUrl, 'base_path' => $basePath, 'url_prefix' => ''],
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
    for ($i = 1; $i <= 12; ++$i) {
        $id = ContentIdAllocator::allocate($db);
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
    $mount = '$_SERVER["SCRIPT_FILENAME"] = __DIR__ . "/index.php"; $_SERVER["SCRIPT_NAME"] = '
        . var_export($basePath . '/index.php', true) . '; $_SERVER["PHP_SELF"] = $_SERVER["SCRIPT_NAME"];';
    file_put_contents($fixture . '/router.php', str_replace('<?php', '<?php ' . $mount, $router));
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


    /** @param list<string> $extraHeaders
     *  @param array<string,string> $fields
     *  @return array{headers:array<string,string>,body:string}
     */
    $send = static function (string $path, int $status = 200, array $extraHeaders = [], string $method = 'GET', array $fields = []) use ($baseUrl, $origin): array {
        $headers = array_merge(['Connection: close', 'User-Agent: Mozilla/5.0 diagnostic fixture', 'Accept-Encoding: identity'], $extraHeaders);
        $http = ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15, 'method' => $method];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $headers[] = 'Origin: ' . $origin;
            $http['content'] = http_build_query($fields);
        }

        $http['header'] = implode("\r\n", $headers);
        $stream = fopen($baseUrl . $path, 'rb', false, stream_context_create(['http' => $http]));
        if (!is_resource($stream)) {
            throw new RuntimeException('A fixture request failed.');
        }

        $responseHeaders = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        $body = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($body) || ($status !== 0 && !str_contains($responseHeaders[0] ?? '', ' ' . $status . ' '))) {
            throw new RuntimeException('Unexpected fixture response: ' . json_encode($responseHeaders, JSON_THROW_ON_ERROR));
        }

        $parsed = [];
        foreach ($responseHeaders as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower($name)] = trim($value);
            }
        }

        return ['headers' => $parsed, 'body' => $body];
    };
    /** @return list<array{cpu_ms:float,engine_loaded:bool,included_files:int}> */
    $readMetrics = static function (int $count) use ($fixture): array {
        for ($i = 0; $i < 200; ++$i) {
            $lines = file($fixture . '/metrics.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines) && count($lines) >= $count) {
                return array_map(static fn(string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines);
            }

            usleep(10_000);
        }

        throw new RuntimeException('Fixture CPU measurements did not finish.');
    };
    $scenarios = [];
    foreach ([
        'cached_article' => ['/benchmark-1', 200],
        'auth_plain_page' => ['/auth', 200],
        'auth_page' => ['/auth?return=%2Fbenchmark-1', 200],
        'auth_email_mode' => ['/auth?mode=email&return=%2Fbenchmark-1', 200],
        'auth_password_mode' => ['/auth?mode=password&return=%2Fbenchmark-1', 200],
        'oauth_get_405' => ['/auth/oauth/vk?return=%2Fbenchmark-1', 405],
        'oauth_yandex_get_405' => ['/auth/oauth/yandex?return=%2Fbenchmark-1', 405],
        'oauth_mail_get_405' => ['/auth/oauth/mail_ru?return=%2Fbenchmark-1', 405],
        'oauth_ok_get_405' => ['/auth/oauth/ok_ru?return=%2Fbenchmark-1', 405],
    ] as $name => [$path, $status]) {
        file_put_contents($fixture . '/metrics.jsonl', '');
        for ($i = 0; $i < 5; ++$i) {
            $send($path, $status);
        }

        $readMetrics(5);
        // Measure the response path independently of scheduled background work.
        // The ordinary early-cache benchmark separately tests scheduler pulses.
        file_put_contents($fixture . '/_cache/early-pages-v1/engine-pulse.json', json_encode(['next' => time() + 60], JSON_THROW_ON_ERROR));
        file_put_contents($fixture . '/metrics.jsonl', '');
        $decisions = [];
        for ($i = 0; $i < $requests; ++$i) {
            $target = str_contains($path, 'return=') ? str_replace('%2Fbenchmark-1', rawurlencode('/destination-' . $i), $path) : $path;
            $reply = $send($target, $status);
            $key = $reply['headers']['x-register-early-cache'] ?? 'none';
            $decisions[$key] = ($decisions[$key] ?? 0) + 1;
        }

        $metrics = $readMetrics($requests);
        $samples = array_column($metrics, 'cpu_ms');
        sort($samples);
        $scenarios[$name] = [
            'requests' => count($metrics), 'status' => $status, 'early_cache' => $decisions,
            'engine_loaded' => count(array_filter($metrics, static fn(array $sample): bool => $sample['engine_loaded'])),
            'cpu_ms_mean' => (float)array_sum($samples) / (float)count($samples), 'cpu_ms_median' => $samples[(int)floor(count($samples) / 2)],
            'included_files_mean' => (float)array_sum(array_column($metrics, 'included_files')) / (float)count($metrics),
        ];
    }

    $checks = [];
    $return = '/article?value="<test>&other=1#comment';
    $path = '/auth?mode=password&return=' . rawurlencode($return);
    $reply = $send($path);
    $document = new DOMDocument();
    $document->loadHTML($reply['body'], LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $values = $xpath->query('//input[@name="return_path"]/@value');
    $checks['every_form_keeps_the_requested_return'] = $values !== false && $values->length > 0;
    if ($values !== false) {
        foreach ($values as $value) {
            $checks['every_form_keeps_the_requested_return'] = $checks['every_form_keeps_the_requested_return'] && $value->nodeValue === $return;
        }
    }

    $checks['no_template_marker_is_public'] = !str_contains($reply['body'], '__register_auth_return_');
    $checks['no_return_path_html_injection'] = !str_contains($reply['body'], '<test>');
    $etag = $reply['headers']['etag'] ?? '';
    $same = $send($path, 304, ['If-None-Match: ' . $etag]);
    $other = $send('/auth?mode=password&return=%2Fanother', 200, ['If-None-Match: ' . $etag]);
    $checks['conditional_response_matches_only_its_return_path'] = $etag !== '' && $same['body'] === '' && ($other['headers']['etag'] ?? '') !== $etag;
    $head = $send('/auth/oauth/vk?return=%2Fanother', 405, ['If-None-Match: *'], 'HEAD');
    $checks['method_refusal_is_not_304_and_head_has_no_body'] = $head['body'] === '' && ($head['headers']['allow'] ?? '') === 'POST';
    foreach ([
        'unknown_cookie' => ['/auth?return=%2Fprivate', ['Cookie: private_session=1'], 'GET', []],
        'authorization' => ['/auth?return=%2Fprivate', ['Authorization: Bearer private'], 'GET', []],
        'reload' => ['/auth?return=%2Fprivate', ['Cache-Control: no-cache'], 'GET', []],
        'partial_navigation' => ['/auth?return=%2Fprivate', ['X-Register-Navigation: partial'], 'GET', []],
        'unknown_query' => ['/auth?return=%2Fprivate&unrecognized=1', [], 'GET', []],
        'oauth_post' => ['/auth/oauth/vk?return=%2Fprivate', [], 'POST', ['auth_token' => 'invalid']],
        'oauth_callback' => ['/auth/oauth/vk/callback?state=invalid&code=invalid', [], 'GET', []],
    ] as $name => [$target, $headers, $method, $fields]) {
        file_put_contents($fixture . '/metrics.jsonl', '');
        $send($target, 0, $headers, $method, $fields);
        $sample = $readMetrics(1)[0];
        $checks[$name . '_uses_normal_handler'] = $sample['engine_loaded'];
    }

    $snapshots = glob($fixture . '/_cache/early-pages-v1/[a-f0-9][a-f0-9]/*.json');
    $checks['different_returns_keep_a_bounded_number_of_snapshots'] = is_array($snapshots) && count($snapshots) <= 9;
    $host = $address;
    $key = hash('sha256', implode("\0", [$host, 'http', $basePath . '/auth', 'reader:auth:password']));
    $snapshot = $fixture . '/_cache/early-pages-v1/' . substr($key, 0, 2) . '/' . $key . '.json';
    if (is_file($snapshot)) {
        $entry = json_decode((string)file_get_contents($snapshot), true, 16, JSON_THROW_ON_ERROR);
        $entry['expires'] = time() - 1;
        file_put_contents($snapshot, json_encode($entry, JSON_THROW_ON_ERROR));
        file_put_contents($fixture . '/metrics.jsonl', '');
        $expired = $send('/auth?mode=password&return=%2Fexpired');
        $checks['expired_credentials_refresh_through_the_engine'] = $readMetrics(1)[0]['engine_loaded']
            && ($expired['headers']['x-register-early-cache'] ?? '') === 'stored';
    } else {
        $checks['expired_credentials_refresh_through_the_engine'] = false;
    }

    file_put_contents($fixture . '/config.php', "\n", FILE_APPEND);
    file_put_contents($fixture . '/metrics.jsonl', '');
    $send('/auth?return=%2Fconfig-change');
    $checks['configuration_changes_invalidate_auth_snapshots'] = $readMetrics(1)[0]['engine_loaded'];
    if (isset($options['assert-fast'])) {
        foreach ($scenarios as $name => $scenario) {
            if ($scenario['engine_loaded'] !== 0 || ($scenario['early_cache']['hit'] ?? 0) !== $requests) {
                throw new RuntimeException($name . ' still bootstrapped the engine.');
            }
        }

        if (in_array(false, $checks, true)) {
            throw new RuntimeException('Auth-cache regression checks failed: ' . implode(', ', array_keys($checks, false, true)));
        }
    }

    echo json_encode(['environment' => 'isolated local SQLite fixture; production is never accessed', 'php' => PHP_VERSION, 'base_path' => $basePath,
        'source' => 'per-request PHP getrusage user+system; warm OPCache; scheduled background work excluded',
        'scenarios' => $scenarios, 'safety_checks' => $checks], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
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
