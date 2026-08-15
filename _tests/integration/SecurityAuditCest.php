<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use S2\Cms\Admin\AdminRequestHandler;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Security\WebAuthn\RecoveryCodeRepository;
use Symfony\Component\HttpFoundation\Request;

final class SecurityAuditCest
{
    private string $auditFile;

    public function _before(): void
    {
        $this->auditFile = dirname(__DIR__, 2) . '/_cache/test/security-audit.jsonl';
        s2_call_without_warnings(fn(): bool => unlink($this->auditFile));
    }

    public function _after(): void
    {
        s2_call_without_warnings(fn(): bool => unlink($this->auditFile));
    }

    public function testPasswordAndRecoveryLoginsAreAuditedWithoutCredentials(\IntegrationTester $I): void
    {
        $I->login('admin', 'wrong-password-that-must-not-be-logged');
        $I->seeResponseCodeIs(401);
        $I->login('admin', 'admin');
        $I->seeResponseCodeIs(200);

        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabAdminService(DbLayer::class);
        $userId = (int)$dbLayer
            ->select('id')
            ->from('users')
            ->where('login = :login')->setParameter('login', 'admin')
            ->execute()
            ->result()
        ;

        /** @var RecoveryCodeRepository $repository */
        $repository = $I->grabAdminService(RecoveryCodeRepository::class);
        $recoveryCode = $repository->regenerate($userId)[0];

        /** @var AdminRequestHandler $handler */
        $handler = $I->grabAdminService(AdminRequestHandler::class);
        $response = $handler->handle(Request::create(
            'https://localhost/_admin/index.php?action=webauthn_recovery_login',
            Request::METHOD_POST,
            ['login' => 'admin', 'recovery_code' => $recoveryCode],
            server: ['REMOTE_ADDR' => '192.0.2.100', 'HTTP_USER_AGENT' => 'Recovery test browser'],
        ));
        $I->assertSame(200, $response->getStatusCode());

        $contents = file_get_contents($this->auditFile);
        $I->assertIsString($contents);
        $I->assertStringNotContainsString('admin', $contents);
        $I->assertStringNotContainsString('wrong-password-that-must-not-be-logged', $contents);
        $I->assertStringNotContainsString($recoveryCode, $contents);
        $I->assertStringNotContainsString('192.0.2.100', $contents);
        $I->assertStringNotContainsString('Recovery test browser', $contents);

        $records = array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $contents), static fn(string $line): bool => $line !== '')),
        );
        $I->assertSame(['failure', 'success', 'success'], array_column($records, 'outcome'));
        $I->assertSame(['password', 'password', 'recovery_code'], array_column($records, 'auth_method'));
        $I->assertArrayNotHasKey('actor_user_id', $records[0]);
        $I->assertSame($userId, $records[1]['actor_user_id']);
        $I->assertSame($userId, $records[2]['actor_user_id']);
    }
}
