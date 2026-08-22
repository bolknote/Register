<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class MaintenanceMode
{
    private const string FILENAME = '.register-maintenance.json';

    private const string LOCK_FILENAME = '.register-maintenance.lock';

    public function __construct(private string $applicationRoot)
    {
    }

    /** @param array<string, mixed> $server */
    public static function isUpdateRequest(array $server, mixed $query, mixed $request): bool
    {
        if (!\defined('S2_ADMIN_MODE')) {
            return false;
        }

        $script = $server['SCRIPT_NAME'] ?? null;
        if (!\is_string($script)) {
            return false;
        }

        $queryData   = \is_array($query) ? $query : [];
        $requestData = \is_array($request) ? $request : [];
        $action      = $queryData['action'] ?? $requestData['action'] ?? null;
        if (str_ends_with(str_replace('\\', '/', $script), '/_admin/index.php')) {
            return ($queryData['entity'] ?? null) === 'Update'
                || (\is_string($action) && \in_array($action, [
                    'login',
                    'webauthn_auth_options',
                    'webauthn_auth_finish',
                    'webauthn_recovery_login',
                ], true));
        }

        if (!str_ends_with(str_replace('\\', '/', $script), '/_admin/ajax.php')) {
            return false;
        }

        return \is_string($action) && str_starts_with($action, 'register_update_');
    }

    public function active(): bool
    {
        return is_file($this->filename()) && !is_link($this->filename());
    }

    public function enter(string $releaseId, string $sessionId): void
    {
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,95}$/D', $releaseId) !== 1) {
            throw new \InvalidArgumentException('The maintenance release ID is invalid.');
        }

        if (preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1) {
            throw new \InvalidArgumentException('The maintenance update session ID is invalid.');
        }

        $this->exclusive(function () use ($releaseId, $sessionId): void {
            if ($this->active()) {
                $state = $this->state();
                if (($state['release_id'] ?? null) === $releaseId
                    && ($state['session_id'] ?? null) === $sessionId
                ) {
                    return;
                }

                throw new \RuntimeException('The site is already in maintenance mode for another update session.');
            }

            $handle = fopen($this->filename(), 'xb');
            if ($handle === false) {
                throw new \RuntimeException('The site is already in maintenance mode.');
            }

            try {
                if (DIRECTORY_SEPARATOR !== '\\' && !chmod($this->filename(), 0600)) {
                    throw new \RuntimeException('Unable to secure the maintenance marker.');
                }

                $content = json_encode([
                    'release_id' => $releaseId,
                    'session_id' => $sessionId,
                    'started_at' => gmdate(\DateTimeInterface::ATOM),
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
                if (fwrite($handle, $content) !== \strlen($content) || !fflush($handle)) {
                    throw new \RuntimeException('Unable to write the maintenance marker.');
                }
            } catch (\Throwable $throwable) {
                fclose($handle);
                unlink($this->filename());
                throw $throwable;
            }

            fclose($handle);
        });
    }

    public function leave(string $sessionId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $sessionId) !== 1) {
            throw new \InvalidArgumentException('The maintenance update session ID is invalid.');
        }

        $this->exclusive(function () use ($sessionId): void {
            $filename = $this->filename();
            if (is_link($filename)) {
                throw new \RuntimeException('The maintenance marker must not be a symbolic link.');
            }

            if (!is_file($filename)) {
                return;
            }

            if (($this->state()['session_id'] ?? null) !== $sessionId) {
                throw new \RuntimeException('Maintenance mode belongs to another update session.');
            }

            if (!unlink($filename)) {
                throw new \RuntimeException('Unable to leave maintenance mode.');
            }
        });
    }

    /** @SuppressWarnings("PHPMD.ExitExpression") */
    public function enforce(bool $updateRequest): void
    {
        if ($updateRequest || !$this->active()) {
            return;
        }

        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('Retry-After: 60');
        header('X-Register-Maintenance: 1');
        header_remove('X-Powered-By');
        echo <<<'HTML'
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Maintenance</title></head>
<body><main><h1>Register is being updated</h1><p>Please try again in a minute.</p></main></body>
</html>
HTML;
        exit;
    }

    private function filename(): string
    {
        return rtrim($this->applicationRoot, '/\\') . '/' . self::FILENAME;
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $json = file_get_contents($this->filename());
        try {
            $state = \is_string($json) ? json_decode($json, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The maintenance marker is corrupt.', 0, $exception);
        }

        if (!\is_array($state)) {
            throw new \RuntimeException('The maintenance marker is invalid.');
        }

        return $state;
    }

    /** @param callable(): void $operation */
    private function exclusive(callable $operation): void
    {
        $filename = rtrim($this->applicationRoot, '/\\') . '/' . self::LOCK_FILENAME;
        if (is_link($filename)) {
            throw new \RuntimeException('The maintenance lock must not be a symbolic link.');
        }

        $handle = fopen($filename, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the maintenance lock.');
        }

        try {
            if ((DIRECTORY_SEPARATOR !== '\\' && !chmod($filename, 0600)) || !flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire the maintenance lock.');
            }

            $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
