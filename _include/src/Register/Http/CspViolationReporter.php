<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Http;

use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use Symfony\Component\HttpFoundation\Request;

/**
 * Stores bounded, privacy-reduced CSP rollout telemetry.
 *
 * Browser reports are attacker-controlled. Only a small fixed set of fields is
 * accepted; full URLs, referrers, policy text and source samples are discarded.
 */
final readonly class CspViolationReporter
{
    public const int DEFAULT_MAX_FILE_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private string             $filePath,
        private SpamIdentityHasher $identifierHasher,
        private int                $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
    ) {
        if ($this->maxFileBytes < 1) {
            throw new \InvalidArgumentException('The CSP report file size limit must be positive.');
        }
    }

    /** @param array<string, mixed> $report */
    public function record(Request $request, array $report): bool
    {
        try {
            $record = [
                'schema_version'      => 1,
                'occurred_at'         => gmdate('Y-m-d\TH:i:s\Z'),
                'event'               => 'csp_violation',
                'disposition'         => $this->disposition($report),
                'effective_directive' => $this->directive($report),
                ...$this->blockedResource($report),
                ...$this->location($report),
                ...$this->requestFingerprint($request),
            ];

            $line = json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";

            return $this->write($line);
        } catch (\Throwable) {
            /** @noinspection ForgottenDebugOutputInspection */
            error_log('Unable to write a CSP violation report.');

            return false;
        }
    }

    /** @param array<string, mixed> $report */
    private function directive(array $report): string
    {
        $value = $this->stringValue(
            $report,
            'effective-directive',
            'effectiveDirective',
            'violated-directive',
            'violatedDirective',
        );
        $firstToken = strtok(trim($value), " \t");
        $directive  = strtolower($firstToken !== false ? $firstToken : '');

        return preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $directive) === 1 ? $directive : 'unknown';
    }

    /** @param array<string, mixed> $report */
    private function disposition(array $report): string
    {
        $value = strtolower($this->stringValue($report, 'disposition'));

        return \in_array($value, ['enforce', 'report'], true) ? $value : 'unknown';
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, string>
     */
    private function blockedResource(array $report): array
    {
        $blockedUri = trim($this->stringValue($report, 'blocked-uri', 'blockedURL', 'blockedURI'));
        if ($blockedUri === '') {
            return ['blocked_resource' => 'unknown'];
        }

        $keyword = strtolower($blockedUri);
        if (\in_array($keyword, ['inline', 'eval', 'wasm-eval', 'trusted-types-policy', 'trusted-types-sink'], true)) {
            return ['blocked_resource' => $keyword];
        }

        $parts = parse_url($blockedUri);
        if (!\is_array($parts)) {
            return ['blocked_resource' => 'other'];
        }

        $scheme = strtolower(\is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');
        if (\in_array($scheme, ['data', 'blob', 'filesystem', 'about'], true)) {
            return ['blocked_resource' => $scheme];
        }

        if (!\in_array($scheme, ['http', 'https'], true)) {
            return ['blocked_resource' => 'other'];
        }

        $blockedOrigin = $this->origin($parts);
        if ($blockedOrigin === null) {
            return ['blocked_resource' => 'other'];
        }

        $documentUri    = $this->stringValue($report, 'document-uri', 'documentURL', 'documentURI');
        $documentParts  = parse_url($documentUri);
        $documentOrigin = \is_array($documentParts) ? $this->origin($documentParts) : null;

        return [
            'blocked_resource' => $blockedOrigin === $documentOrigin
                ? 'same_origin'
                : ($scheme === 'http' ? 'cross_origin_http' : 'cross_origin_https'),
            'blocked_origin'   => $blockedOrigin,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, int|string>
     */
    private function location(array $report): array
    {
        $result = [];
        $documentUri = $this->normalizedDocumentUri($this->stringValue($report, 'document-uri', 'documentURL', 'documentURI'));
        if ($documentUri !== null) {
            $result['document_hash'] = $this->identifierHasher->rateBucket('csp-document', $documentUri);
        }

        $statusCode = $this->intValue($report, 'status-code', 'statusCode');
        if ($statusCode !== null && $statusCode >= 100 && $statusCode <= 599) {
            $result['status_code'] = $statusCode;
        }

        $lineNumber = $this->intValue($report, 'line-number', 'lineNumber');
        if ($lineNumber !== null && $lineNumber >= 0 && $lineNumber <= 10_000_000) {
            $result['line_number'] = $lineNumber;
        }

        $columnNumber = $this->intValue($report, 'column-number', 'columnNumber');
        if ($columnNumber !== null && $columnNumber >= 0 && $columnNumber <= 10_000_000) {
            $result['column_number'] = $columnNumber;
        }

        return $result;
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

    /** @param array<string, mixed> $parts */
    private function origin(array $parts): ?string
    {
        $scheme = strtolower(\is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');
        $host   = strtolower(\is_string($parts['host'] ?? null) ? $parts['host'] : '');
        if (
            !\in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || strlen($host) > 253
            || preg_match('/^[a-z0-9.:\[\]-]+$/D', $host) !== 1
        ) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && (!\is_int($port) || $port < 1 || $port > 65_535)) {
            return null;
        }

        $defaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);

        return $scheme . '://' . $host . ($port !== null && !$defaultPort ? ':' . $port : '');
    }

    private function normalizedDocumentUri(string $uri): ?string
    {
        $parts = parse_url(trim($uri));
        if (!\is_array($parts)) {
            return null;
        }

        $origin = $this->origin($parts);
        if ($origin === null) {
            return null;
        }

        $path = \is_string($parts['path'] ?? null) ? $parts['path'] : '/';
        if (strlen($path) > 2048) {
            return null;
        }

        return $origin . $path;
    }

    /** @param array<string, mixed> $report */
    private function stringValue(array $report, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (isset($report[$key]) && \is_string($report[$key])) {
                return $report[$key];
            }
        }

        return '';
    }

    /** @param array<string, mixed> $report */
    private function intValue(array $report, string ...$keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($report[$key]) && \is_int($report[$key])) {
                return $report[$key];
            }
        }

        return null;
    }

    private function write(string $line): bool
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the CSP report directory.');
        }

        s2_call_without_warnings(static fn(): bool => chmod($directory, 0700));

        if (is_link($this->filePath) || (file_exists($this->filePath) && !is_file($this->filePath))) {
            throw new \RuntimeException('The CSP report file must be a regular file.');
        }

        $handle = fopen($this->filePath, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the CSP report file.');
        }

        s2_call_without_warnings(fn(): bool => chmod($this->filePath, 0600));

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the CSP report file.');
            }

            try {
                $stat = fstat($handle);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                    throw new \RuntimeException('The CSP report target must be a regular file.');
                }

                if (fseek($handle, 0, SEEK_END) !== 0) {
                    throw new \RuntimeException('Unable to seek in the CSP report file.');
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
                        throw new \RuntimeException('Unable to append the CSP violation report.');
                    }

                    $offset += $written;
                }

                if (!fflush($handle)) {
                    throw new \RuntimeException('Unable to flush the CSP violation report.');
                }
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }

        s2_call_without_warnings(fn(): bool => chmod($this->filePath, 0600));

        return true;
    }
}
