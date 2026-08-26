#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * Exercises Register's checked-in .htaccess rules against a real isolated
 * Apache process. The application itself is never booted.
 */

$projectRoot = dirname(__DIR__, 2);
$required    = getenv('REGISTER_REQUIRE_APACHE_SECURITY_TEST') === '1';
$apache      = findApacheBinary();
$moduleDir   = findApacheModuleDirectory();

if ($apache === null || $moduleDir === null) {
    $message = "Apache policy test skipped: Apache or its module directory was not found.\n";
    fwrite($required ? STDERR : STDOUT, $message);
    exit($required ? 1 : 0);
}

$tempRoot = sys_get_temp_dir() . '/register_apache_policy_' . bin2hex(random_bytes(6));
$webRoot  = $tempRoot . '/web';
$process  = null;

try {
    createFixtureTree($projectRoot, $tempRoot, $webRoot);
    $port       = reserveLocalPort();
    $configFile = createApacheConfig($apache, $tempRoot, $webRoot, $moduleDir, $port);

    assertApacheConfigIsValid($apache, $configFile, $tempRoot . '/configtest.log');
    $process = startApache($apache, $configFile, $tempRoot . '/apache.log');
    waitForApache($process, $port, $tempRoot . '/apache-error.log');

    $expectations = [
        '/_cache/register_styles.deadbeef.css'          => 200,
        '/_cache/register_scripts.deadbeef.js.gz'       => 200,
        '/_cache/register_styles.deadbeef.css.br'       => 200,
        '/_cache/register_styles.deadbeef.css.zst'      => 200,
        '/_cache/register_styles.deadbeef.css.meta.php' => 403,
        '/_cache/register_config.php'                   => 403,
        '/_cache/phpstan/deadbeef.css'                  => 403,
        '/_pictures/photo.png'                          => 200,
        '/_pictures/shell.php'                          => 403,
        '/_pictures/active.svg'                         => 403,
        '/_assets/register/admin-yard/style.css'          => 200,
        '/_assets/register/admin-yard/script.js'          => 200,
        '/_vendor/example/package/composer.json'        => 403,
        '/_admin/templates/layout.php.inc'                => 403,
        '/_extensions/example/module.php'                 => 403,
        '/_styles/example/style.php'                      => 403,
        '/_include/secret.txt'                          => 403,
        '/service-worker.js'                           => 200,
        '/files/'                                      => 200,
        '/files/acid0/'                                => 200,
        '/files/newnumber.htm'                         => 200,
        '/files/opera-cam-recog.html'                  => 200,
        '/files/demo-assets/player-test.mp4'           => 200,
        '/files/iso-ir-111.py'                         => 200,
        '/files/physical.php'                          => 403,
        '/rss'                                         => 200,
        '/feed.json'                                   => 200,
        '/sitemap.xml'                                 => 200,
        '/99.html'                                     => 200,
        '/private.js'                                  => 403,
        '/nonexistent-private.sql'                     => 403,
        '/composer.lock'                                => 403,
        '/config.local.php'                             => 403,
        '/config.secrets.php'                           => 403,
    ];

    foreach ($expectations as $path => $expectedStatus) {
        $actualStatus = requestStatus($port, $path);
        if ($actualStatus !== $expectedStatus) {
            throw new RuntimeException(sprintf(
                'Apache returned %d for %s; expected %d.',
                $actualStatus,
                $path,
                $expectedStatus,
            ));
        }
    }

    $securityHeaders = [
        'x-content-type-options' => 'nosniff',
        'referrer-policy'        => 'strict-origin-when-cross-origin',
        'permissions-policy'     => 'camera=(self), microphone=(), geolocation=()',
    ];
    foreach (['/_pictures/photo.png', '/config.secrets.php'] as $path) {
        $response = requestResponse($port, $path);
        foreach ($securityHeaders as $name => $expectedValue) {
            $actualValues = $response['headers'][$name] ?? [];
            if ($actualValues !== [$expectedValue]) {
                throw new RuntimeException(sprintf(
                    'Apache returned %s: %s for %s; expected exactly %s.',
                    $name,
                    $actualValues === [] ? '(missing)' : implode(', ', $actualValues),
                    $path,
                    $expectedValue,
                ));
            }
        }
    }

    $identityResponse = requestResponse($port, '/_cache/register_styles.deadbeef.css');
    if (isset($identityResponse['headers']['content-encoding'])) {
        throw new RuntimeException('Apache encoded an asset without Accept-Encoding.');
    }

    $encodedExpectations = [
        'gzip'                   => 'gzip',
        'zstd'                   => 'zstd',
        'gzip, zstd'             => 'zstd',
        'gzip, zstd, br'         => 'br',
        'gzip, br;q=0, zstd;q=0' => 'gzip',
    ];
    foreach ($encodedExpectations as $acceptEncoding => $expectedEncoding) {
        $response = requestResponse(
            $port,
            '/_cache/register_styles.deadbeef.css',
            ['Accept-Encoding' => $acceptEncoding],
        );
        if (($response['headers']['content-encoding'] ?? []) !== [$expectedEncoding]) {
            throw new RuntimeException(sprintf(
                'Apache selected an invalid encoding for Accept-Encoding: %s.',
                $acceptEncoding,
            ));
        }
        if (!isset($response['headers']['vary'])
            || !in_array('Accept-Encoding', $response['headers']['vary'], true)
        ) {
            throw new RuntimeException('Apache omitted Vary: Accept-Encoding for a precompressed asset.');
        }
    }

    fwrite(STDOUT, sprintf(
        "Apache shared-hosting policy: OK (%d allow/deny checks, %d header checks).\n",
        count($expectations),
        count($securityHeaders) * 2,
    ));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    if (is_file($tempRoot . '/apache-error.log')) {
        $errorLog = file_get_contents($tempRoot . '/apache-error.log');
        if (is_string($errorLog) && $errorLog !== '') {
            fwrite(STDERR, $errorLog);
        }
    }
    exit(1);
} finally {
    if (is_resource($process)) {
        // The isolated process owns no application data. SIGKILL avoids
        // Apache's normal graceful-shutdown timeout in the quality suite.
        proc_terminate($process, 9);
        proc_close($process);
    }
    removeTree($tempRoot);
}

/** @return non-empty-string|null */
function findApacheBinary(): ?string
{
    $configured = getenv('APACHE_BIN');
    $candidates = [
        is_string($configured) ? $configured : '',
        '/usr/sbin/httpd',
        '/usr/sbin/apache2',
        '/usr/local/sbin/httpd',
        '/opt/homebrew/sbin/httpd',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/** @return non-empty-string|null */
function findApacheModuleDirectory(): ?string
{
    $configured = getenv('APACHE_MODULE_DIR');
    $candidates = [
        is_string($configured) ? $configured : '',
        '/usr/libexec/apache2',
        '/usr/lib/apache2/modules',
        '/usr/local/libexec/apache2',
        '/opt/homebrew/lib/httpd/modules',
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate . '/mod_rewrite.so')) {
            return $candidate;
        }
    }

    return null;
}

function createFixtureTree(string $projectRoot, string $tempRoot, string $webRoot): void
{
    $directories = [
        $tempRoot,
        $webRoot,
        $webRoot . '/_cache/phpstan',
        $webRoot . '/_pictures',
        $webRoot . '/_include',
        $webRoot . '/_admin/templates',
        $webRoot . '/_extensions/example',
        $webRoot . '/_styles/example',
        $webRoot . '/_assets/register/admin-yard',
        $webRoot . '/_vendor/example/package',
        $webRoot . '/files/acid0',
        $webRoot . '/files/demo-assets',
    ];
    foreach ($directories as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Apache fixture directory: ' . $directory);
        }
    }

    $copies = [
        $projectRoot . '/.htaccess'           => $webRoot . '/.htaccess',
        $projectRoot . '/_cache/.htaccess'    => $webRoot . '/_cache/.htaccess',
        $projectRoot . '/_pictures/.htaccess' => $webRoot . '/_pictures/.htaccess',
    ];
    foreach ($copies as $source => $destination) {
        if (!copy($source, $destination)) {
            throw new RuntimeException('Unable to copy Apache policy fixture: ' . $source);
        }
    }

    $fixtures = [
        '/index.php'                                    => '<?php echo "front controller";',
        '/_cache/register_styles.deadbeef.css'          => 'body{color:#123}',
        '/_cache/register_styles.deadbeef.css.br'       => 'brotli fixture',
        '/_cache/register_styles.deadbeef.css.zst'      => 'zstd fixture',
        '/_cache/register_styles.deadbeef.css.gz'       => 'gzip fixture',
        '/_cache/register_scripts.deadbeef.js.gz'       => 'compressed fixture',
        '/_cache/register_styles.deadbeef.css.meta.php' => '<?php return ["secret" => true];',
        '/_cache/register_config.php'                   => '<?php return ["secret" => true];',
        '/_cache/phpstan/deadbeef.css'                  => 'private tool cache',
        '/_pictures/photo.png'                          => 'safe fixture',
        '/_pictures/shell.php'                          => '<?php echo "must not execute";',
        '/_pictures/active.svg'                         => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        '/_assets/register/admin-yard/style.css'          => 'body{font-family:sans-serif}',
        '/_assets/register/admin-yard/script.js'          => 'document.documentElement.dataset.ready="1";',
        '/_vendor/example/package/composer.json'        => '{"name":"private/fixture"}',
        '/_admin/templates/layout.php.inc'                => '<?php echo "private template";',
        '/_extensions/example/module.php'                 => '<?php echo "private extension";',
        '/_styles/example/style.php'                      => '<?php echo "private style";',
        '/_include/secret.txt'                          => 'private source',
        '/service-worker.js'                           => "'use strict';",
        '/files/acid0/index.html'                      => '<!doctype html><title>ASIT</title>',
        '/files/newnumber.htm'                         => '<!doctype html><title>Phone converter</title>',
        '/files/opera-cam-recog.html'                  => '<!doctype html><title>Camera demo</title>',
        '/files/demo-assets/player-test.mp4'           => 'passive video fixture',
        '/files/physical.php'                          => '<?php echo "must not execute";',
        '/private.js'                                  => 'private root script',
        '/composer.lock'                                => '{"packages":[]}',
        '/config.local.php'                             => '<?php return ["password" => "secret"];',
        '/config.secrets.php'                           => '<?php return ["REGISTER_AI_API_KEY" => "secret"];',
    ];
    foreach ($fixtures as $path => $content) {
        if (file_put_contents($webRoot . $path, $content) === false) {
            throw new RuntimeException('Unable to create Apache policy fixture: ' . $path);
        }
    }
}

function reserveLocalPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException(sprintf('Unable to reserve a local port: %s (%d).', $errorMessage, $errorCode));
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address)) {
        throw new RuntimeException('Unable to determine the reserved local port.');
    }

    $separatorPosition = strrpos($address, ':');
    if ($separatorPosition === false) {
        throw new RuntimeException('Unable to determine the reserved local port.');
    }

    return (int)substr($address, $separatorPosition + 1);
}

function createApacheConfig(string $apache, string $tempRoot, string $webRoot, string $moduleDir, int $port): string
{
    $moduleFiles = [
        'mpm_prefork_module' => 'mod_mpm_prefork.so',
        'unixd_module'       => 'mod_unixd.so',
        'authz_core_module'  => 'mod_authz_core.so',
        'headers_module'     => 'mod_headers.so',
        'dir_module'         => 'mod_dir.so',
        'mime_module'        => 'mod_mime.so',
        'rewrite_module'     => 'mod_rewrite.so',
    ];
    $compiledInModules = findCompiledInApacheModules($apache);
    $loadModules       = '';
    foreach ($moduleFiles as $moduleName => $moduleFile) {
        if (is_file($moduleDir . '/' . $moduleFile)) {
            $loadModules .= sprintf("LoadModule %s \"%s/%s\"\n", $moduleName, $moduleDir, $moduleFile);
            continue;
        }

        $sourceFile = substr($moduleFile, 0, -3) . '.c';
        if (!isset($compiledInModules[$sourceFile])) {
            throw new RuntimeException('Required Apache module is missing: ' . $moduleFile);
        }
    }

    $mimeTypes = null;
    foreach (['/private/etc/apache2/mime.types', '/etc/apache2/mime.types', '/etc/mime.types'] as $candidate) {
        if (is_file($candidate)) {
            $mimeTypes = $candidate;
            break;
        }
    }
    if ($mimeTypes === null) {
        throw new RuntimeException('Unable to locate the Apache MIME types file.');
    }

    $config = sprintf(
        <<<'APACHE'
ServerRoot "%s"
DefaultRuntimeDir "%s"
PidFile "%s/httpd.pid"
Listen 127.0.0.1:%d
ServerName 127.0.0.1
%s
TypesConfig "%s"
ErrorLog "%s/apache-error.log"
LogLevel warn
ServerLimit 1
StartServers 1
MinSpareServers 1
MaxSpareServers 1
MaxRequestWorkers 1
MaxConnectionsPerChild 0
DocumentRoot "%s"
DirectoryIndex index.html index.htm index.php
<Directory "%s">
    AllowOverride All
    Options FollowSymLinks
    Require all granted
</Directory>

APACHE,
        $tempRoot,
        $tempRoot,
        $tempRoot,
        $port,
        $loadModules,
        $mimeTypes,
        $tempRoot,
        $webRoot,
        $webRoot,
    );

    $configFile = $tempRoot . '/httpd.conf';
    if (file_put_contents($configFile, $config) === false) {
        throw new RuntimeException('Unable to create the Apache test configuration.');
    }

    return $configFile;
}

/** @return array<non-empty-string, true> */
function findCompiledInApacheModules(string $apache): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([$apache, '-l'], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to inspect compiled-in Apache modules.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || !is_string($output)) {
        throw new RuntimeException(
            'Unable to inspect compiled-in Apache modules: ' . (is_string($errorOutput) ? trim($errorOutput) : ''),
        );
    }

    $lines = preg_split('/\R/', $output);
    if ($lines === false) {
        throw new RuntimeException('Unable to parse compiled-in Apache modules.');
    }

    $modules = [];
    foreach ($lines as $line) {
        $module = trim($line);
        if ($module !== '' && preg_match('/^[a-z0-9_]+\.c$/i', $module) === 1) {
            $modules[$module] = true;
        }
    }

    return $modules;
}

function assertApacheConfigIsValid(string $apache, string $configFile, string $outputFile): void
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $outputFile, 'a'],
        2 => ['file', $outputFile, 'a'],
    ];
    $process = proc_open([$apache, '-t', '-f', $configFile], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to validate the Apache test configuration.');
    }
    fclose($pipes[0]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $output = file_get_contents($outputFile);
        throw new RuntimeException('Apache rejected the test configuration: ' . (is_string($output) ? trim($output) : ''));
    }
}

/** @return resource */
function startApache(string $apache, string $configFile, string $outputFile)
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $outputFile, 'a'],
        2 => ['file', $outputFile, 'a'],
    ];
    $process = proc_open([$apache, '-X', '-f', $configFile], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the Apache policy test process.');
    }
    fclose($pipes[0]);

    return $process;
}

/** @param resource $process */
function waitForApache($process, int $port, string $errorLog): void
{
    $deadline = microtime(true) + 5.0;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $error = is_file($errorLog) ? file_get_contents($errorLog) : '';
            throw new RuntimeException('Apache stopped before accepting requests. ' . (is_string($error) ? trim($error) : ''));
        }

        set_error_handler(static fn(int $severity): bool => $severity !== 0);
        try {
            $socket = fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
        } finally {
            restore_error_handler();
        }
        if (is_resource($socket)) {
            fclose($socket);
            return;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException(sprintf('Apache did not start on port %d.', $port));
}

function requestStatus(int $port, string $path): int
{
    return requestResponse($port, $path)['status'];
}

/**
 * @param array<string, string> $requestHeaders
 * @return array{status: int, headers: array<string, list<string>>}
 */
function requestResponse(int $port, string $path, array $requestHeaders = []): array
{
    $socket = fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 2.0);
    if ($socket === false) {
        throw new RuntimeException(sprintf('Unable to connect to Apache: %s (%d).', $errorMessage, $errorCode));
    }

    $rawHeaders = '';
    foreach ($requestHeaders as $name => $value) {
        if (preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1 || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RuntimeException('Invalid Apache test request header.');
        }
        $rawHeaders .= $name . ': ' . $value . "\r\n";
    }
    fwrite($socket, sprintf(
        "GET %s HTTP/1.1\r\nHost: 127.0.0.1\r\n%sConnection: close\r\n\r\n",
        $path,
        $rawHeaders,
    ));
    $statusLine = fgets($socket);
    if (!is_string($statusLine) || preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $statusLine, $matches) !== 1) {
        fclose($socket);
        throw new RuntimeException('Apache returned an invalid HTTP status line for ' . $path . '.');
    }

    $headers = [];
    while (($line = fgets($socket)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            break;
        }

        $separator = strpos($line, ':');
        if ($separator === false) {
            fclose($socket);
            throw new RuntimeException('Apache returned an invalid HTTP header for ' . $path . '.');
        }

        $name = strtolower(trim(substr($line, 0, $separator)));
        $headers[$name][] = trim(substr($line, $separator + 1));
    }
    fclose($socket);

    return ['status' => (int)$matches[1], 'headers' => $headers];
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $itemPath = $path . '/' . $item;
        if (is_dir($itemPath) && !is_link($itemPath)) {
            removeTree($itemPath);
        } else {
            unlink($itemPath);
        }
    }
    rmdir($path);
}
