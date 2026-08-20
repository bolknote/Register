<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Security\Audit;

use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use Symfony\Component\HttpFoundation\Request;

/**
 * Writes a deliberately small, append-only JSONL security trail.
 *
 * The public API accepts identifiers and classifications only. It has no generic
 * context argument, so passwords, tokens, API keys and secret values cannot be
 * accidentally forwarded by callers.
 */
final readonly class SecurityAuditLogger
{
    public const string OUTCOME_SUCCESS      = 'success';

    public const string OUTCOME_FAILURE      = 'failure';

    public const string OUTCOME_DENIED       = 'denied';

    public const string OUTCOME_RATE_LIMITED = 'rate_limited';

    public const string AUTH_PASSWORD      = 'password';

    public const string AUTH_PASSKEY       = 'passkey';

    public const string AUTH_RECOVERY_CODE = 'recovery_code';

    private const array OUTCOMES = [
        self::OUTCOME_SUCCESS,
        self::OUTCOME_FAILURE,
        self::OUTCOME_DENIED,
        self::OUTCOME_RATE_LIMITED,
    ];

    private const array AUTH_METHODS = [
        self::AUTH_PASSWORD,
        self::AUTH_PASSKEY,
        self::AUTH_RECOVERY_CODE,
    ];

    private const array USER_ACTIONS = ['create', 'update'];

    private const array USER_FIELDS = [
        'login',
        'password',
        'view',
        'view_hidden',
        'hide_comments',
        'edit_comments',
        'create_articles',
        'edit_site',
        'edit_users',
    ];

    private const array EXTENSION_ACTIONS = ['install', 'uninstall', 'toggle'];

    private const array BACKUP_ACTIONS = ['create', 'download', 'prune'];

    private const array BACKUP_SOURCES = ['manual', 'scheduled', 'retention'];

    private const array CREDENTIAL_ACTIONS = ['passkey_register', 'passkey_delete', 'recovery_codes_regenerate'];

    public function __construct(
        private string             $filePath,
        private SpamIdentityHasher $identifierHasher,
    ) {
    }

    public function authentication(
        Request $request,
        string $method,
        string $outcome,
        ?int $actorUserId = null,
        string $login = '',
    ): void {
        $this->requireOneOf($method, self::AUTH_METHODS, 'authentication method');
        $this->requireOutcome($outcome);

        try {
            $record = [
                'event'       => 'authentication',
                'outcome'     => $outcome,
                'auth_method' => $method,
                ...$this->actor($actorUserId),
                ...$this->requestFingerprint($request),
            ];
            $normalizedLogin = mb_strtolower(trim($login));
            if ($normalizedLogin !== '') {
                $record['login_hash'] = $this->identifierHasher->rateBucket('security-audit-login', $normalizedLogin);
            }

            $this->write($record);
        } catch (\Throwable) {
            $this->reportFailure();
        }
    }

    /** @param list<string> $changedFields */
    public function userChanged(int $actorUserId, int $subjectUserId, string $action, array $changedFields): void
    {
        $this->requireOneOf($action, self::USER_ACTIONS, 'user action');
        $changedFields = array_values(array_unique($changedFields));
        foreach ($changedFields as $field) {
            $this->requireOneOf($field, self::USER_FIELDS, 'user field');
        }

        sort($changedFields);

        $this->write([
            'event'           => 'user_security_changed',
            'outcome'         => self::OUTCOME_SUCCESS,
            'actor_user_id'   => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'action'          => $action,
            'changed_fields'  => $changedFields,
        ]);
    }

    public function configurationChanged(int $actorUserId, string $parameter, bool $secret): void
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $parameter) !== 1) {
            throw new \InvalidArgumentException('Invalid configuration parameter name for security audit.');
        }

        $this->write([
            'event'         => 'configuration_changed',
            'outcome'       => self::OUTCOME_SUCCESS,
            'actor_user_id' => $actorUserId,
            'parameter'     => $parameter,
            'secret'        => $secret,
        ]);
    }

    public function extensionChanged(int $actorUserId, string $extensionId, string $action, string $outcome): void
    {
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $extensionId) !== 1) {
            throw new \InvalidArgumentException('Invalid extension identifier for security audit.');
        }

        $this->requireOneOf($action, self::EXTENSION_ACTIONS, 'extension action');
        $this->requireOutcome($outcome);

        $this->write([
            'event'         => 'extension_changed',
            'outcome'       => $outcome,
            'actor_user_id' => $actorUserId,
            'extension_id'  => $extensionId,
            'action'        => $action,
        ]);
    }

    public function backupOperation(?int $actorUserId, string $action, string $source, string $outcome): void
    {
        $this->requireOneOf($action, self::BACKUP_ACTIONS, 'backup action');
        $this->requireOneOf($source, self::BACKUP_SOURCES, 'backup source');
        $this->requireOutcome($outcome);

        $this->write([
            'event'   => 'backup_operation',
            'outcome' => $outcome,
            ...$this->actor($actorUserId),
            'action'  => $action,
            'source'  => $source,
        ]);
    }

    public function credentialChanged(int $actorUserId, string $action, string $outcome): void
    {
        $this->requireOneOf($action, self::CREDENTIAL_ACTIONS, 'credential action');
        $this->requireOutcome($outcome);

        $this->write([
            'event'         => 'authentication_credential_changed',
            'outcome'       => $outcome,
            'actor_user_id' => $actorUserId,
            'action'        => $action,
        ]);
    }

    /** @param array<string, mixed> $record */
    private function write(array $record): void
    {
        try {
            $line = json_encode([
                'schema_version' => 1,
                'occurred_at'    => gmdate('Y-m-d\TH:i:s\Z'),
                ...$record,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

            $directory = dirname($this->filePath);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create the security audit directory.');
            }

            if (is_link($this->filePath)) {
                throw new \RuntimeException('The security audit file must not be a symbolic link.');
            }

            $handle = fopen($this->filePath, 'ab');
            if ($handle === false) {
                throw new \RuntimeException('Unable to open the security audit file.');
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    throw new \RuntimeException('Unable to lock the security audit file.');
                }

                try {
                    $length = strlen($line);
                    $offset = 0;
                    while ($offset < $length) {
                        $written = fwrite($handle, substr($line, $offset));
                        if ($written === false || $written === 0) {
                            throw new \RuntimeException('Unable to append the security audit event.');
                        }

                        $offset += $written;
                    }

                    if (!fflush($handle)) {
                        throw new \RuntimeException('Unable to flush the security audit event.');
                    }
                } finally {
                    flock($handle, LOCK_UN);
                }
            } finally {
                fclose($handle);
            }

            s2_call_without_warnings(fn(): bool => chmod($this->filePath, 0600));
        } catch (\Throwable) {
            $this->reportFailure();
        }
    }

    /** @return array{actor_user_id:int}|array{} */
    private function actor(?int $actorUserId): array
    {
        return $actorUserId === null ? [] : ['actor_user_id' => $actorUserId];
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

    private function requireOutcome(string $outcome): void
    {
        $this->requireOneOf($outcome, self::OUTCOMES, 'security audit outcome');
    }

    /** @param list<string> $allowed */
    private function requireOneOf(string $value, array $allowed, string $label): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid %s.', $label));
        }
    }

    private function reportFailure(): void
    {
        /** @noinspection ForgottenDebugOutputInspection */
        error_log('Unable to write a security audit event.');
    }
}
