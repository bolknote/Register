<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Security;

use Codeception\Test\Unit;
use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use S2\Cms\Security\Audit\SecurityAuditLogger;
use Symfony\Component\HttpFoundation\Request;

final class SecurityAuditLoggerTest extends Unit
{
    private string $directory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->directory = sys_get_temp_dir() . '/register_audit_' . bin2hex(random_bytes(8));
    }

    #[\Override]
    protected function _after(): void
    {
        $file = $this->directory . '/security-audit.jsonl';
        if (is_file($file) || is_link($file)) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAuthenticationRecordContainsOnlyFingerprints(): void
    {
        $logger = $this->logger();
        $request = Request::create(
            'https://example.test/_admin/index.php?action=login',
            Request::METHOD_POST,
            ['login' => 'Administrator', 'pass' => 'never-log-this-password', 'csrf_token' => 'never-log-this-token'],
            ['register_session' => 'never-log-this-session'],
            server: ['REMOTE_ADDR' => '192.0.2.42', 'HTTP_USER_AGENT' => 'Audit browser'],
        );

        $logger->authentication(
            $request,
            SecurityAuditLogger::AUTH_PASSWORD,
            SecurityAuditLogger::OUTCOME_FAILURE,
            login: 'Administrator',
        );

        $contents = $this->contents();
        self::assertStringNotContainsString('Administrator', $contents);
        self::assertStringNotContainsString('192.0.2.42', $contents);
        self::assertStringNotContainsString('Audit browser', $contents);
        self::assertStringNotContainsString('never-log-this', $contents);

        $record = json_decode(trim($contents), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $record['schema_version']);
        self::assertSame('authentication', $record['event']);
        self::assertSame('failure', $record['outcome']);
        self::assertSame('password', $record['auth_method']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $record['login_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $record['remote_ip_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $record['user_agent_hash']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $record['occurred_at']);
        $permissions = fileperms($this->directory . '/security-audit.jsonl');
        self::assertNotFalse($permissions);
        self::assertSame(0600, $permissions & 0777);
    }

    public function testCriticalOperationsAreStructuredWithoutValues(): void
    {
        $logger = $this->logger();
        $logger->userChanged(7, 12, 'update', ['edit_users', 'password', 'edit_users']);
        $logger->configurationChanged(7, 'S2_AI_API_KEY', true);
        $logger->extensionChanged(7, 'example_extension', 'install', SecurityAuditLogger::OUTCOME_SUCCESS);
        $logger->backupOperation(7, 'download', 'manual', SecurityAuditLogger::OUTCOME_SUCCESS);
        $logger->credentialChanged(7, 'recovery_codes_regenerate', SecurityAuditLogger::OUTCOME_SUCCESS);

        $records = array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $this->contents()), static fn(string $line): bool => $line !== '')),
        );

        self::assertSame([
            'user_security_changed',
            'configuration_changed',
            'extension_changed',
            'backup_operation',
            'authentication_credential_changed',
        ], array_column($records, 'event'));
        self::assertSame(['edit_users', 'password'], $records[0]['changed_fields']);
        self::assertSame('S2_AI_API_KEY', $records[1]['parameter']);
        self::assertTrue($records[1]['secret']);
        self::assertArrayNotHasKey('value', $records[1]);
    }

    public function testSymbolicLinkIsNotFollowed(): void
    {
        mkdir($this->directory, 0700, true);
        $target = $this->directory . '/target';
        file_put_contents($target, 'unchanged');
        symlink($target, $this->directory . '/security-audit.jsonl');

        $this->logger()->backupOperation(null, 'create', 'scheduled', SecurityAuditLogger::OUTCOME_SUCCESS);

        self::assertSame('unchanged', file_get_contents($target));
        unlink($target);
    }

    private function logger(): SecurityAuditLogger
    {
        return new SecurityAuditLogger(
            $this->directory . '/security-audit.jsonl',
            new SpamIdentityHasher(str_repeat('a', 32)),
        );
    }

    private function contents(): string
    {
        $contents = file_get_contents($this->directory . '/security-audit.jsonl');
        self::assertIsString($contents);

        return $contents;
    }
}
