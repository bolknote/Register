<?php

declare(strict_types = 1);

namespace unit\Cms\Comment;

use PHPUnit\Framework\TestCase;
use Register\Core\Comment\ObsoleteReplyUrl;

final class ObsoleteReplyUrlTest extends TestCase
{
    public function testRejectsAllFormsOfOldReplyGetAndHeadParameters(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            foreach (ObsoleteReplyUrl::QUERY_PARAMETERS as $key) {
                foreach (['42', '', null, []] as $value) {
                    self::assertTrue(ObsoleteReplyUrl::matches($method, [$key => $value]));
                }
            }
        }

        self::assertFalse(ObsoleteReplyUrl::matches('GET', ['utm_source' => 'test']));
        self::assertFalse(ObsoleteReplyUrl::matches('GET', []));
        self::assertFalse(ObsoleteReplyUrl::matches('POST', ['reply_number' => '1']));
    }

    public function testFrontControllerRejectsOldUrlsWithoutBootstrapping(): void
    {
        $code = <<<'PHP'
            $_SERVER['REQUEST_METHOD'] = $argv[1];
            $_GET = ['reply_to' => '42'];
            register_shutdown_function(static function (): void {
                echo "\n" . json_encode([
                    'status' => http_response_code(),
                    'bootstrapped' => class_exists(Register\Core\Framework\Application::class, false),
                ], JSON_THROW_ON_ERROR);
            });
            require $argv[2];
            PHP;
        foreach (['GET', 'HEAD'] as $method) {
            $process = proc_open([PHP_BINARY, '-r', $code, $method, \dirname(__DIR__, 4) . '/index.php'], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            self::assertSame('', $error);
            $body = $method === 'GET' ? ObsoleteReplyUrl::BODY : '';
            self::assertSame($body . "\n" . '{"status":404,"bootstrapped":false}', $output);
        }
    }
}
