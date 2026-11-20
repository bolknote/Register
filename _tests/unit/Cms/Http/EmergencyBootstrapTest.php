<?php

declare(strict_types = 1);

namespace unit\Cms\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class EmergencyBootstrapTest extends TestCase
{
    public function testFailedDatabaseDuringNormalBootstrapReturnsLocalizedErrorWithCorrelatedLog(): void
    {
        $root = \dirname(__DIR__, 4);
        $directory = sys_get_temp_dir() . '/register-emergency-' . bin2hex(random_bytes(8));
        $environment = 'emergency-' . bin2hex(random_bytes(8));
        $configFile = $root . '/config.' . $environment . '.php';
        mkdir($directory, 0700);
        $config = [
            'database' => ['type' => 'sqlite', 'host' => '', 'name' => '/missing-' . $environment . '/db.sqlite',
                'user' => '', 'password' => 'private-database-password', 'prefix' => 'test_'],
            'http' => ['base_url' => 'http://localhost', 'base_path' => '/blog'],
            'files' => ['cache_dir' => $directory, 'log_dir' => $directory],
        ];
        file_put_contents($configFile, '<?php return ' . var_export($config, true) . ';');
        file_put_contents($directory . '/register_config.php', "<?php return ['REGISTER_LANGUAGE' => 'Russian'];");
        $router = $directory . '/router.php';
        file_put_contents($router, '<?php if ($_SERVER["REQUEST_URI"] === "/ready") { echo "ready"; return; } '
            . 'putenv(' . var_export('APP_ENV=' . $environment, true) . '); require ' . var_export($root . '/index.php', true) . ';');
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        fclose($reservation);
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', $root, $router], [
            0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
        try {
            $deadline = microtime(true) + 5.0;
            do {
                $ready = register_call_without_warnings(static fn(): string|false => file_get_contents('http://' . $address . '/ready', context: $context)) === 'ready';
                if (!$ready) usleep(10000);
            } while (!$ready && microtime(true) < $deadline);

            self::assertTrue($ready, 'The isolated PHP server must start.');
            $stream = fopen('http://' . $address . '/blog/article?private-query=1', 'rb', false, $context);
            self::assertIsResource($stream);
            $response = stream_get_contents($stream);
            $metadata = stream_get_meta_data($stream);
            fclose($stream);
            self::assertIsString($response);
            $headers = $metadata['wrapper_data'];
            self::assertIsArray($headers);
            self::assertStringContainsString('500', (string) $headers[0]);
            self::assertContains('Cache-Control: no-store, private', $headers);
            self::assertStringContainsString('<html lang="ru">', $response);
            self::assertStringContainsString('Сайт временно недоступен', $response);
            self::assertStringNotContainsString('DEBUG', $response);
            self::assertStringNotContainsString('private-database-password', $response);
            $json = file_get_contents($directory . '/incidents.jsonl');
            self::assertIsString($json);
            $record = json_decode(trim($json), true, flags: JSON_THROW_ON_ERROR);
            $id = $record['incident_id'];
            self::assertStringContainsString($id, $response);
            self::assertContains('X-Register-Incident: ' . $id, $headers);
            self::assertSame('/blog/article', $record['path']);
            self::assertSame('cli-server', $record['sapi']);
            self::assertStringNotContainsString('private-query', $json);
            $log = file_get_contents($directory . '/app.log');
            self::assertIsString($log);
            self::assertStringContainsString($id, $log);
            self::assertStringContainsString('Unable to create new database file', $log);
        } finally {
            proc_terminate($process);
            proc_close($process);
            (new Filesystem())->remove([$configFile, $directory]);
        }
    }
}
