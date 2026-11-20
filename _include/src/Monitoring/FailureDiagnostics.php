<?php
/**
 * @copyright 2026 Register contributors
 * @license https://opensource.org/license/mit MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

/** Records failures even when the container or its database cannot start. */
final class FailureDiagnostics
{
    private ?string $incidentId = null;

    /**
     * @param array<string, mixed> $server
     * @param array{driver: string, transport: string} $database
     */
    public function __construct(
        private readonly QueryProfilerLog $log,
        private readonly float $startedAt,
        private readonly array $server,
        private readonly array $database,
        private readonly string $rootDirectory,
    ) {
    }

    public function incidentId(): ?string
    {
        return $this->incidentId;
    }

    public function record(\Throwable $exception): string
    {
        $this->incidentId = bin2hex(random_bytes(8));
        $context = ['incident_id' => $this->incidentId];
        try {
            $path = explode('?', (string)($this->server['REQUEST_URI'] ?? ''), 2)[0];
            // Authentication paths may carry a one-time secret in a segment.
            $path = preg_replace('~^(/(?:[^/?]+/)*auth)(?:/.*)?$~', '$1', $path) ?? '';
            $context += [
                'at' => gmdate(DATE_ATOM),
                'sapi' => PHP_SAPI,
                'pid' => getmypid(),
                'method' => substr((string)($this->server['REQUEST_METHOD'] ?? 'CLI'), 0, 16),
                'path' => mb_substr($path, 0, 500),
                'duration_ms' => round(max(0.0, microtime(true) - $this->startedAt) * 1000.0, 2),
                'database' => $this->database,
                'exceptions' => $this->exceptions($exception),
                'resources' => FailureResourceSnapshot::capture(),
                'release' => $this->release(),
            ];
            register_call_without_warnings(fn() => $this->log->append($context));
        } catch (\Throwable) {
            error_log('Register failure ' . $this->incidentId . ': diagnostic snapshot could not be written.');
        }

        return $this->incidentId;
    }

    /** @return list<array<string, mixed>> */
    private function exceptions(\Throwable $exception): array
    {
        $result = [];
        do {
            $entry = [
                'class' => $exception::class,
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
            if ($exception instanceof \PDOException) {
                $entry['sqlstate'] = $exception->errorInfo[0] ?? null;
                $entry['driver_code'] = $exception->errorInfo[1] ?? null;
                $entry['operation'] = ($exception->getTrace()[0]['function'] ?? null) === '__construct'
                    ? 'connect' : 'database_operation';
            }

            $entry['trace'] = array_map(static fn(array $frame): array => array_intersect_key(
                $frame, array_flip(['file', 'line', 'class', 'function']),
            ), array_slice($exception->getTrace(), 0, 32));
            $result[] = $entry;
            $exception = $exception->getPrevious();
        } while ($exception !== null && \count($result) < 8);

        return $result;
    }

    /** @return array<string, string> */
    private function release(): array
    {
        $filename = $this->rootDirectory . '/register-build.json';
        if (!is_file($filename) || is_link($filename)) {
            return [];
        }

        $json = file_get_contents($filename, length: 16384);
        $data = $json !== false ? json_decode($json, true) : null;
        $result = [];
        foreach (['release_id', 'commit'] as $key) {
            if (\is_array($data) && isset($data[$key]) && \is_string($data[$key])) {
                $result[$key] = substr($data[$key], 0, 100);
            }
        }

        return $result;
    }
}
