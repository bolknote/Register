<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Model;

use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Translator;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Model\AuthManager;
use Register\Core\Model\AuthTokenHasher;
use Register\Core\Model\LoginRateLimiter;
use Register\Core\Model\PermissionChecker;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PdoSqliteFactory;
use Register\Core\Pdo\QueryResult;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthManagerSqliteConcurrencyTest extends Unit
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    #[\Override]
    protected function _after(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testSessionRefreshReleasesReadSnapshotBeforeWriting(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'register_auth_sqlite_');
        self::assertIsString($path);
        unlink($path);

        $this->temporaryFiles = [$path, $path . '-shm', $path . '-wal'];
        $primaryPdo          = PdoSqliteFactory::create($path, false);
        $competingPdo        = PdoSqliteFactory::create($path, false);

        $primaryPdo->exec(
            'CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY, login TEXT NOT NULL, password TEXT NOT NULL, email TEXT NOT NULL, name TEXT NOT NULL, '
            . 'view INTEGER NOT NULL, view_hidden INTEGER NOT NULL, hide_comments INTEGER NOT NULL, '
            . 'edit_comments INTEGER NOT NULL, create_articles INTEGER NOT NULL, edit_site INTEGER NOT NULL, edit_users INTEGER NOT NULL)'
        );
        $primaryPdo->exec(
            'CREATE TABLE users_online ('
            . "challenge TEXT PRIMARY KEY, time INTEGER NOT NULL, login TEXT, audience TEXT NOT NULL DEFAULT 'admin', "
            . 'ip TEXT, ua TEXT, comment_cookie TEXT NOT NULL)'
        );
        $primaryPdo->exec('CREATE TABLE contention (value INTEGER NOT NULL)');
        $primaryPdo->exec("INSERT INTO contention (value) VALUES (0)");
        $primaryPdo->exec(
            "INSERT INTO users VALUES (1, 'admin', '', 'admin@example.test', 'Admin', 1, 1, 1, 1, 1, 1, 1)"
        );

        $sessionId   = 'p' . sprintf('%08x', time()) . str_repeat('a', 64);
        $sessionTime = time() - 10;
        $statement   = $primaryPdo->prepare(
            'INSERT INTO users_online (challenge, time, login, ip, ua, comment_cookie) VALUES (?, ?, ?, ?, ?, ?)'
        );
        self::assertNotFalse($statement);
        $statement->execute([
            AuthTokenHasher::session($sessionId),
            $sessionTime,
            'admin',
            '127.0.0.1',
            'test',
            AuthTokenHasher::comment(str_repeat('b', 64)),
        ]);

        $dbLayer = new ConcurrentWriteDbLayerSqlite($primaryPdo, $competingPdo);
        $authManager = new AuthManager(
            $dbLayer,
            new PermissionChecker(),
            new RequestStack(),
            self::createStub(TemplateRenderer::class),
            self::createStub(Translator::class),
            new LoginRateLimiter(
                $dbLayer,
                new SpamIdentityHasher(str_repeat('s', 32)),
                new NullLogger(),
            ),
            new SecurityAuditLogger('php://memory', new SpamIdentityHasher(str_repeat('a', 32))),
            '',
            'http://localhost',
            'register_session',
            false,
        );
        $request = Request::create(
            'http://localhost/_admin/index.php',
            cookies: ['register_session' => $sessionId],
        );

        self::assertNull($authManager->checkAuth($request));
        $contentionResult = $competingPdo->query('SELECT value FROM contention');
        self::assertNotFalse($contentionResult);
        self::assertSame(1, (int)$contentionResult->fetchColumn());

        $sessionResult = $primaryPdo->query('SELECT time FROM users_online');
        self::assertNotFalse($sessionResult);
        self::assertGreaterThan($sessionTime, (int)$sessionResult->fetchColumn());
    }
}

/** @internal */
final class ConcurrentWriteDbLayerSqlite extends DbLayerSqlite
{
    private bool $competingWriteTriggered = false;

    public function __construct(\PDO $pdo, private readonly \PDO $competingPdo)
    {
        parent::__construct($pdo);
    }

    /**
     * @param array<int|string, mixed> $params
     * @param array<int|string, int>   $types
     */
    #[\Override]
    public function query(string $sql, array $params = [], array $types = []): QueryResult
    {
        $result = parent::query($sql, $params, $types);
        if (!$this->competingWriteTriggered && str_starts_with($sql, 'SELECT login, time, audience FROM users_online')) {
            $this->competingWriteTriggered = true;
            $this->competingPdo->exec('UPDATE contention SET value = value + 1');
        }

        return $result;
    }
}
