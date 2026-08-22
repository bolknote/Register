<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Core\Model\PasswordHasher;

final class ReauthenticationCest
{
    public function testPasswordAndPermissionChangesRequireTheActorPassword(\IntegrationTester $I): void
    {
        /** @var \PDO $pdo */
        $pdo = $I->grabAdminService(\PDO::class);
        $this->deleteUser($pdo, 'reauth-edit-target');
        $originalHash = PasswordHasher::hash('original target passphrase');
        $targetId = $this->insertUser($pdo, 'reauth-edit-target', $originalHash);

        try {
            $I->login('admin', 'admin');
            $url = 'https://localhost/_admin/index.php?entity=User&action=edit&id=' . $targetId;
            $I->amOnPage($url);
            $I->seeElement('input[name="current_password"][autocomplete="current-password"]');
            $I->assertSame('', $I->grabValueFrom('input[name="current_password"]'));

            $formData = $this->userFormData(
                'reauth-edit-target',
                'violet glacier meadow passphrase',
                createArticles: true,
            );
            $I->submitForm('.edit-content > form', [
                ...$formData,
                'current_password' => 'incorrect-current-password',
            ]);

            $I->seeResponseCodeIs(200);
            $I->see('The current password is incorrect.', '.error-message-box');
            $pageSource = $I->grabResponse();
            $I->assertStringNotContainsString('incorrect-current-password', $pageSource);
            $I->assertStringNotContainsString('violet glacier meadow passphrase', $pageSource);
            $rejectedRow = $this->userSecurityRow($pdo, $targetId);
            $I->assertSame(0, (int)$rejectedRow['create_articles']);
            $I->assertSame($originalHash, $rejectedRow['password']);

            $I->submitForm('.edit-content > form', [
                ...$formData,
                'current_password' => 'admin',
            ]);

            $I->seeResponseCodeIs(302);
            $savedRow = $this->userSecurityRow($pdo, $targetId);
            $I->assertSame(1, (int)$savedRow['create_articles']);
            $I->assertTrue(password_verify('violet glacier meadow passphrase', $savedRow['password']));
        } finally {
            $this->deleteUser($pdo, 'reauth-edit-target');
        }
    }

    public function testCreatingAnAdministratorRequiresTheActorPassword(\IntegrationTester $I): void
    {
        /** @var \PDO $pdo */
        $pdo = $I->grabAdminService(\PDO::class);
        $login = 'reauth-new-target';
        $this->deleteUser($pdo, $login);

        try {
            $I->login('admin', 'admin');
            $I->amOnPage('https://localhost/_admin/index.php?entity=User&action=new');
            $I->seeElement('input[name="current_password"][autocomplete="current-password"]');
            $formData = $this->userFormData(
                $login,
                'copper forest lantern passphrase',
                createArticles: true,
                editUsers: true,
            );

            $I->submitForm('.new-content > form', $formData);
            $I->seeResponseCodeIs(200);
            $I->see('The current password is incorrect.', '.error-message-box');
            $I->assertSame(0, $this->userCount($pdo, $login));

            $I->submitForm('.new-content > form', [
                ...$formData,
                'current_password' => 'admin',
            ]);
            $I->seeResponseCodeIs(302);
            $I->assertSame(1, $this->userCount($pdo, $login));
        } finally {
            $this->deleteUser($pdo, $login);
        }
    }

    /** @return array<string, bool|string> */
    private function userFormData(
        string $login,
        string $password,
        bool $createArticles,
        bool $editUsers = false,
    ): array {
        return [
            'login'           => $login,
            'password'        => $password,
            'name'            => 'Reauthentication target',
            'email'           => 'reauth-target@example.test',
            'view'            => true,
            'view_hidden'     => true,
            'hide_comments'   => false,
            'edit_comments'   => false,
            'create_articles' => $createArticles,
            'edit_site'       => false,
            'edit_users'      => $editUsers,
        ];
    }

    private function insertUser(\PDO $pdo, string $login, string $passwordHash): int
    {
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO users (
    login, password, email, name, view, view_hidden, hide_comments,
    edit_comments, create_articles, edit_site, edit_users
) VALUES (
    :login, :password, :email, :name, 1, 1, 0, 0, 0, 0, 0
)
SQL);
        if ($statement === false || !$statement->execute([
            'login'    => $login,
            'password' => $passwordHash,
            'email'    => 'reauth-target@example.test',
            'name'     => 'Reauthentication target',
        ])) {
            throw new \RuntimeException('Unable to create the reauthentication test user.');
        }

        return (int)$pdo->lastInsertId();
    }

    /** @return array{password: string, create_articles: int|string} */
    private function userSecurityRow(\PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare('SELECT password, create_articles FROM users WHERE id = :id');
        if ($statement === false || !$statement->execute(['id' => $userId])) {
            throw new \RuntimeException('Unable to read the reauthentication test user.');
        }

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!\is_array($row) || !\is_string($row['password'] ?? null)) {
            throw new \RuntimeException('The reauthentication test user is missing.');
        }

        return [
            'password'        => $row['password'],
            'create_articles' => $row['create_articles'],
        ];
    }

    private function userCount(\PDO $pdo, string $login): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE login = :login');
        if ($statement === false || !$statement->execute(['login' => $login])) {
            throw new \RuntimeException('Unable to inspect the reauthentication test user.');
        }

        return (int)$statement->fetchColumn();
    }

    private function deleteUser(\PDO $pdo, string $login): void
    {
        $statement = $pdo->prepare('DELETE FROM users WHERE login = :login');
        if ($statement === false || !$statement->execute(['login' => $login])) {
            throw new \RuntimeException('Unable to remove the reauthentication test user.');
        }
    }
}
