<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Security\Monitoring;

use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Records bounded, privacy-reduced events used for local security alerts. */
final readonly class SecurityTelemetryRecorder
{
    public const int DEFAULT_MAX_FILE_BYTES = 5 * 1024 * 1024;

    private const array WATCHED_HTTP_STATUSES = [
        Response::HTTP_UNAUTHORIZED,
        Response::HTTP_FORBIDDEN,
        Response::HTTP_TOO_MANY_REQUESTS,
    ];

    public function __construct(
        private string             $filePath,
        private SpamIdentityHasher $identifierHasher,
        private int                $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
    ) {
        if ($this->maxFileBytes < 1) {
            throw new \InvalidArgumentException('The security telemetry file size limit must be positive.');
        }
    }

    public function recordResponse(Request $request, Response $response, bool $uploadRequest = false): bool
    {
        $statusCode    = $response->getStatusCode();
        $watchedStatus = \in_array($statusCode, self::WATCHED_HTTP_STATUSES, true);
        $uploadFailure = $uploadRequest
            && !\in_array($statusCode, [
                Response::HTTP_UNAUTHORIZED,
                Response::HTTP_FORBIDDEN,
                Response::HTTP_METHOD_NOT_ALLOWED,
                Response::HTTP_TOO_MANY_REQUESTS,
            ], true)
            && $this->isFailedJsonResponse($response);

        if (!$watchedStatus && !$uploadFailure) {
            return true;
        }

        try {
            $record = [
                'schema_version' => 1,
                'occurred_at'    => gmdate('Y-m-d\TH:i:s\Z'),
                'event'          => 'security_response',
                'status_code'    => $statusCode,
                ...$this->requestFingerprint($request),
            ];
            if ($uploadFailure) {
                $record['operation'] = 'upload';
                $record['outcome']   = 'failure';
            }

            $line = json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";

            return $this->write($line);
        } catch (\Throwable) {
            /** @noinspection ForgottenDebugOutputInspection */
            error_log('Unable to write a security telemetry event.');

            return false;
        }
    }

    private function isFailedJsonResponse(Response $response): bool
    {
        if (!$response instanceof JsonResponse) {
            return false;
        }

        $content = $response->getContent();
        if (!\is_string($content) || $content === '') {
            return false;
        }

        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return \is_array($data) && ($data['success'] ?? null) === false;
    }

    /** @return array<string, string> */
    private function requestFingerprint(Request $request): array
    {
        $result = [];
        $clientIp = trim($request->getClientIp() ?? '');
        if ($clientIp !== '') {
            $result['remote_ip_hash'] = $this->identifierHasher->ip($clientIp);
        }

        $userAgent = trim($request->headers->get('User-Agent') ?? '');
        if ($userAgent !== '') {
            $result['user_agent_hash'] = $this->identifierHasher->text($userAgent);
        }

        return $result;
    }

    private function write(string $line): bool
    {
        $directory = \dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the security telemetry directory.');
        }

        register_call_without_warnings(static fn(): bool => chmod($directory, 0700));

        if (is_link($this->filePath) || (file_exists($this->filePath) && !is_file($this->filePath))) {
            throw new \RuntimeException('The security telemetry file must be a regular file.');
        }

        $handle = fopen($this->filePath, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the security telemetry file.');
        }

        register_call_without_warnings(fn(): bool => chmod($this->filePath, 0600));

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the security telemetry file.');
            }

            try {
                $stat = fstat($handle);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                    throw new \RuntimeException('The security telemetry target must be a regular file.');
                }

                if (fseek($handle, 0, SEEK_END) !== 0) {
                    throw new \RuntimeException('Unable to seek in the security telemetry file.');
                }

                $size = ftell($handle);
                if ($size === false || $size > $this->maxFileBytes - strlen($line)) {
                    return false;
                }

                $length = strlen($line);
                $offset = 0;
                while ($offset < $length) {
                    $written = fwrite($handle, substr($line, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException('Unable to append the security telemetry event.');
                    }

                    $offset += $written;
                }

                if (!fflush($handle)) {
                    throw new \RuntimeException('Unable to flush the security telemetry event.');
                }
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }

        register_call_without_warnings(fn(): bool => chmod($this->filePath, 0600));

        return true;
    }
}
