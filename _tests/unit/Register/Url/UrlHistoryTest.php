<?php

declare(strict_types = 1);

namespace unit\Register\Url;

use PHPUnit\Framework\TestCase;
use Register\Admin\UrlHistoryDataProvider;
use Register\AdminYard\Database\Key;
use Register\AdminYard\Database\LogicalExpression;
use Register\AdminYard\Database\SafeDataProviderException;
use Register\AdminYard\Database\TypeTransformer;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentId;
use Register\Content\TagRepository;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Live\LiveUpdateRepository;
use Register\Schema\UrlHistorySchemaMigration;
use Register\Url\ContentUrlAliasController;
use Register\Url\ContentUrlAliasRepository;
use Register\Url\ContentUrlAliasSchema;
use Register\Url\ContentUrlGenerator;
use Register\Url\ContentUrlCollisionException;
use Register\Url\TagUrlAliasRepository;
use Register\Url\UrlHistoryService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

final class UrlHistoryTest extends TestCase
{
    private ?UrlHistoryFixture $fixture = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->fixture = new UrlHistoryFixture();
    }

    private function fixture(): UrlHistoryFixture
    {
        return $this->fixture ?? throw new \LogicException('The URL history fixture has not been initialized.');
    }

    public function testAdminRenameKeepsEveryDescendantAndRevertsWithoutChains(): void
    {
        $this->rename(2, 'second');
        $this->assertRedirect('/first/child?from=old', '/second/child?from=old');
        $this->assertRedirect('/first/', '/second/');
        $this->rename(2, 'third');
        $this->assertRedirect('/first/child', '/third/child');
        $this->assertRedirect('/second/child', '/third/child');
        $this->rename(2, 'first');
        $this->assertRedirect('/third/child', '/first/child');
        self::assertNull($this->fixture()->redirector->redirect(Request::create('/first/child')));
    }

    public function testMoveKeepsChildPathsAndHiddenAncestorsDoNotLeakTargets(): void
    {
        $this->fixture()->pdo->exec("INSERT INTO content(id, content_type, parent_id, slug_scope, slug) VALUES (5, 'page', 1, 'root', 'folder')");
        $this->fixture()->provider->updateEntity('content', ['id' => 'int', 'parent_id' => 'int', 'slug_scope' => 'string'], [], new Key(['id' => 2]), ['parent_id' => 5, 'slug_scope' => 'page:5']);
        $this->assertRedirect('/first/child', '/folder/first/child');
        $this->fixture()->pdo->exec('UPDATE content SET published = 0 WHERE id = 5');
        self::assertNull($this->fixture()->redirector->redirect(Request::create('/first/child')));
    }

    public function testAliasCollisionRollsBackTheCompletePageBranch(): void
    {
        $this->fixture()->aliases->add(ContentId::post(4), 'blocked/child');
        try {
            $this->rename(2, 'blocked');
            self::fail('A descendant must not overwrite an imported alias.');
        } catch (SafeDataProviderException) {
            self::assertSame('first', $this->value('SELECT slug FROM content WHERE id = 2'));
            self::assertNull($this->fixture()->redirector->redirect(Request::create('/first/child')));
            $this->assertRedirect('/blocked/child', '/post');
        }
    }

    public function testCanonicalNestedPostCollisionIsRejected(): void
    {
        $this->fixture()->pdo->exec("INSERT INTO content(id, content_type, slug_scope, slug) VALUES (5, 'post', 'root', 'occupied/child')");
        $this->expectException(SafeDataProviderException::class);
        $this->rename(2, 'occupied');
    }

    public function testDeniedAdminWriteDoesNotCreateAliases(): void
    {
        $this->fixture()->provider->updateEntity('content', ['id' => 'int', 'slug' => 'string'], [new LogicalExpression('id', 999)], new Key(['id' => 2]), ['slug' => 'forbidden']);
        self::assertSame('first', $this->value('SELECT slug FROM content WHERE id = 2'));
        self::assertSame(0, (int)$this->value('SELECT COUNT(*) FROM content_url_alias'));
    }

    public function testTagAdminRenamesReserveEveryOldAddressAndPermitRevert(): void
    {
        $this->fixture()->pdo->exec("INSERT INTO tags(id, name, url) VALUES (1, 'Tag', 'first')");
        foreach (['second', 'third', 'first'] as $slug) {
            $this->fixture()->provider->updateEntity('tags', ['id' => 'int', 'url' => 'string'], [], new Key(['id' => 1]), ['url' => $slug]);
        }

        self::assertSame('first', $this->fixture()->tagAliases->currentSlug('second'));
        self::assertSame('first', $this->fixture()->tagAliases->currentSlug('third'));
        self::assertNull($this->fixture()->tagAliases->currentSlug('first'));
        $this->expectException(SafeDataProviderException::class);
        $this->fixture()->provider->createEntity('tags', ['name' => 'string', 'url' => 'string'], ['name' => 'New', 'url' => 'third']);
    }

    public function testFailedRenameWithinOuterTransactionPreservesOtherWork(): void
    {
        $this->fixture()->pdo->beginTransaction();
        $this->fixture()->pdo->exec("INSERT INTO tags(id, name, url) VALUES (1, 'Tag', 'tag')");
        try {
            $this->fixture()->history->changeContent(ContentId::post(4), function (): never {
                $this->fixture()->pdo->exec("UPDATE content SET slug = 'changed' WHERE id = 4");
                throw new \RuntimeException('Simulated failure');
            });
        } catch (\RuntimeException) {
            self::assertTrue($this->fixture()->pdo->inTransaction());
            self::assertSame('post', $this->value('SELECT slug FROM content WHERE id = 4'));
            self::assertSame('tag', $this->value('SELECT url FROM tags WHERE id = 1'));
        }

        $this->fixture()->pdo->rollBack();
    }

    public function testRetryingMigrationPreservesExistingImportedAliases(): void
    {
        $this->fixture()->aliases->add(ContentId::post(4), '2004/07/19/~1004');
        (new UrlHistorySchemaMigration())->migrate($this->fixture()->db);
        $this->assertRedirect('/2004/07/19/~1004', '/post');
    }

    public function testAdminContentCreationCannotStealHistoricalAddresses(): void
    {
        $this->fixture()->aliases->add(ContentId::post(4), '/reserved');
        $dataTypes = ['content_type' => 'string', 'parent_id' => 'int', 'slug_scope' => 'string', 'slug' => 'string'];
        foreach (['post' => null, 'page' => 1] as $type => $parentId) {
            try {
                $this->fixture()->provider->createEntity('content', $dataTypes, ['content_type' => $type, 'parent_id' => $parentId, 'slug_scope' => 'root', 'slug' => 'reserved']);
                self::fail('An admin insert must not steal an existing alias.');
            } catch (SafeDataProviderException) {
                self::assertSame(0, (int)$this->value("SELECT COUNT(*) FROM content WHERE slug = 'reserved'"));
                $this->assertRedirect('/reserved', '/post');
            }
        }

        $this->fixture()->provider->createEntity('content', $dataTypes, ['content_type' => 'post', 'parent_id' => null, 'slug_scope' => 'root', 'slug' => 'fresh-post']);
        self::assertSame((string)$this->value("SELECT id FROM content WHERE slug = 'fresh-post'"), $this->fixture()->provider->lastInsertId());
    }

    public function testFlatPageHistoryAndCanonicalCollisions(): void
    {
        $config = self::createStub(DynamicConfigProvider::class);
        $config->method('get')->willReturn('0');
        $this->fixture()->configureUrlServices(new BoolProxy($config, 'REGISTER_USE_HIERARCHY'));
        $this->rename(3, 'new-child');
        $this->assertRedirect('/child', '/new-child');
        $this->rename(2, 'second');
        $this->assertRedirect('/first', '/second');
        $this->assertRedirect('/child', '/new-child');
        self::assertNull($this->fixture()->redirector->redirect(Request::create('/first/child')));

        try {
            $this->rename(3, 'post');
            self::fail('Flat nested pages share the post namespace.');
        } catch (SafeDataProviderException) {
            self::assertSame('new-child', $this->value('SELECT slug FROM content WHERE id = 3'));
        }

        $this->expectException(SafeDataProviderException::class);
        $this->rename(2, 'child');
    }

    public function testAutomaticallyCreatedTagsDoNotStealOldUrls(): void
    {
        $this->fixture()->pdo->exec("INSERT INTO tags(id, name, url) VALUES (1, 'Renamed', 'old-tag')");
        $this->fixture()->provider->updateEntity('tags', ['id' => 'int', 'url' => 'string'], [], new Key(['id' => 1]), ['url' => 'current']);
        $tags = new TagRepository($this->fixture()->db, $this->fixture()->history);
        $ids = $tags->findOrCreateIdsByNames(['old-tag']);
        self::assertCount(1, $ids);
        self::assertSame('old-tag-2', $this->value('SELECT url FROM tags WHERE id = ' . $ids[0]));
        self::assertSame('current', $this->fixture()->tagAliases->currentSlug('old-tag'));
        $this->expectException(\InvalidArgumentException::class);
        $tags->findOrCreateIdsByNames(["broken\x00name"]);
    }

    public function testUnpublishedAndFuturePostsDoNotExposeAliasTargets(): void
    {
        $this->rename(4, 'renamed-post');
        $this->fixture()->pdo->exec('UPDATE content SET published = 0 WHERE id = 4');
        self::assertNull($this->fixture()->redirector->redirect(Request::create('/post')));
        $this->fixture()->pdo->exec('UPDATE content SET published = 1, published_at = ' . (time() + 86400) . ' WHERE id = 4');
        self::assertNull($this->fixture()->redirector->redirect(Request::create('/post')));
    }

    public function testTooLongDescendantPathRollsBackRenameWithSafe422(): void
    {
        $longSlug = str_repeat('a', 252);
        try {
            $this->rename(2, $longSlug);
            self::fail('A parent rename cannot produce an untrackable descendant URL.');
        } catch (SafeDataProviderException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame(ContentUrlCollisionException::PATH_TOO_LONG, $exception->getMessage());
            self::assertSame('first', $this->value('SELECT slug FROM content WHERE id = 2'));
            self::assertSame(0, (int)$this->value('SELECT COUNT(*) FROM content_url_alias'));
        }

        $this->fixture()->pdo->exec("UPDATE content SET slug = '" . $longSlug . "' WHERE id = 2");
        try {
            $this->rename(2, 'short');
            self::fail('An existing overlong descendant path must not be silently lost.');
        } catch (SafeDataProviderException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame($longSlug, $this->value('SELECT slug FROM content WHERE id = 2'));
            self::assertSame(0, (int)$this->value('SELECT COUNT(*) FROM content_url_alias'));
        }
    }

    public function testContentAliasesDoNotRedirectMutationRequests(): void
    {
        $this->rename(4, 'renamed');
        self::assertNull($this->fixture()->redirector->redirect(Request::create('/post', 'POST')));
        $response = $this->fixture()->redirector->redirect(Request::create('/post', 'HEAD'));
        self::assertNotNull($response);
        self::assertSame(301, $response->getStatusCode());
    }

    public function testLiteralPercentSequencesAreDecodedOnlyOnce(): void
    {
        $this->fixture()->pdo->exec("UPDATE content SET slug = 'literal%41' WHERE id = 4");
        $this->rename(4, 'renamed');
        $this->assertRedirect('/literal%2541', '/renamed');
        self::assertNull($this->fixture()->redirector->redirect(Request::create('/literalA')));
        $this->fixture()->aliases->add(ContentId::post(4), '/import%2542');
        $this->assertRedirect('/import%2542', '/renamed');
        $this->expectException(ContentUrlCollisionException::class);
        $this->fixture()->aliases->assertAvailable('/import%2542', 3);
    }

    private function rename(int $id, string $slug): void
    {
        $this->fixture()->provider->updateEntity('content', ['id' => 'int', 'slug' => 'string'], [], new Key(['id' => $id]), ['slug' => $slug]);
    }

    private function value(string $sql): mixed
    {
        $statement = $this->fixture()->pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Unable to query the URL fixture.');
        }

        return $statement->fetchColumn();
    }

    private function assertRedirect(string $source, string $target): void
    {
        $response = $this->fixture()->redirector->redirect(Request::create($source));
        self::assertNotNull($response);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame($target, $response->headers->get('Location'));
    }
}

/** An isolated in-memory database and initialized services for each test. */
final class UrlHistoryFixture
{
    public \PDO $pdo;

    public DbLayerSqlite $db;

    public UrlHistoryDataProvider $provider;

    public UrlHistoryService $history;

    public ContentUrlAliasRepository $aliases;

    public ContentUrlAliasController $redirector;

    public TagUrlAliasRepository $tagAliases;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->db = new DbLayerSqlite($this->pdo);
        $this->pdo->exec('CREATE TABLE config (name TEXT PRIMARY KEY, value TEXT)');
        $this->pdo->exec("INSERT INTO config VALUES ('REGISTER_SCHEMA_GENERATION', '30')");
        $this->pdo->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, content_type TEXT, parent_id INTEGER, slug_scope TEXT, slug TEXT, published INTEGER DEFAULT 1, published_at INTEGER DEFAULT 1)');
        $this->pdo->exec('CREATE UNIQUE INDEX canonical_scope ON content(slug_scope, slug)');
        $this->pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT, url TEXT UNIQUE, description TEXT DEFAULT "", modify_time INTEGER DEFAULT 0)');
        ContentUrlAliasSchema::create($this->db);
        (new UrlHistorySchemaMigration())->migrate($this->db);
        $this->configureUrlServices();
        $this->pdo->exec("INSERT INTO content(id, content_type, parent_id, slug_scope, slug) VALUES (1, 'page', NULL, 'root', ''), (2, 'page', 1, 'root', 'first'), (3, 'page', 2, 'page:2', 'child'), (4, 'post', NULL, 'root', 'post')");
    }

    public function configureUrlServices(?BoolProxy $useHierarchy = null): void
    {
        $urls = new ContentUrlGenerator($this->db, new UrlBuilder('', 'https://example.com', ''), $useHierarchy);
        $this->aliases = new ContentUrlAliasRepository($this->db, $useHierarchy);
        $this->tagAliases = new TagUrlAliasRepository($this->db);
        $this->history = new UrlHistoryService($this->pdo, $this->db, $urls, $this->aliases, $this->tagAliases,
            new ContentChangeDispatcher($this->db, new EventDispatcher(), new LiveUpdateRepository($this->db)));
        $this->provider = new UrlHistoryDataProvider($this->pdo, new TypeTransformer(), $this->history, $this->tagAliases, '');
        $this->redirector = new ContentUrlAliasController($this->aliases, $urls);
    }
}
