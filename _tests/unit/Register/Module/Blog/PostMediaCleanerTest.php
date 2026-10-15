<?php

declare(strict_types = 1);

namespace unit\Register\Module\Blog;

use Codeception\Test\Unit;
use Register\Admin\Picture\PictureFileNameHelper;
use Register\Admin\Picture\PictureStorageQuota;
use Register\AdminYard\Translator;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentMediaSchema;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Live\LiveUpdateRepository;
use Register\Module\Blog\Inplace\PostInplaceMediaStorage;
use Register\Module\Blog\Inplace\PostMediaCleaner;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Register\Url\ContentUrlAliasRepository;
use Register\Url\ContentUrlGenerator;
use Register\Url\TagUrlAliasRepository;
use Register\Url\UrlHistoryService;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PostMediaCleanerTest extends Unit
{
    public function testRollbackRestoresThePostWithItsOriginalFile(): void
    {
        $this->withMedia(function (\PDO $pdo, PostMediaRepository $repository, PostMediaCleaner $cleaner, int $id, string $file): void {
            $body = '<img src="/media/1901.01.01.png" data-post-media-id="' . $id . '">';
            $update = $pdo->prepare('UPDATE content SET body = ? WHERE id = 1');
            self::assertInstanceOf(\PDOStatement::class, $update);
            $update->execute([$body]);
            $repository->syncPost(1, $body, [$id], 1);

            $pdo->beginTransaction();
            $repository->releasePost(1);
            $pdo->exec('DELETE FROM content WHERE id = 1');
            self::assertFalse($cleaner->deleteUnused($id));
            self::assertTrue($pdo->inTransaction());

            $pendingMedia = $repository->find($id);
            self::assertNotNull($pendingMedia);
            self::assertFileExists($file);
            $pdo->rollBack();

            $restored = $pdo->query('SELECT body FROM content WHERE id = 1');
            self::assertInstanceOf(\PDOStatement::class, $restored);
            self::assertSame($body, $restored->fetchColumn());

            $restoredMedia = $repository->find($id);
            self::assertNotNull($restoredMedia);
            self::assertSame(1, (int)$restoredMedia['usage_count']);
            self::assertFileExists($file);
        });
    }

    public function testCommittedOrphanCanBeCollectedAfterTheOuterTransaction(): void
    {
        $this->withMedia(function (\PDO $pdo, PostMediaRepository $repository, PostMediaCleaner $cleaner, int $id, string $file): void {
            $pdo->beginTransaction();
            self::assertFalse($cleaner->deleteUnused($id));
            self::assertNotNull($repository->find($id));
            self::assertFileExists($file);
            $pdo->commit();

            self::assertContains($id, array_column($repository->staleUnusedMedia(time() + 1), 'id'));
            self::assertTrue($cleaner->deleteUnused($id));
            self::assertNull($repository->find($id));
            self::assertFileDoesNotExist($file);
            self::assertFalse($pdo->inTransaction());
        });
    }

    public function testFailedUnlinkRollsBackTheRegistryAndCanBeRetried(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Requires POSIX directory permissions.');
        }

        $this->withMedia(function (\PDO $pdo, PostMediaRepository $repository, PostMediaCleaner $cleaner, int $id, string $file): void {
            $directory = dirname($file);
            self::assertTrue(chmod($directory, 0500));
            clearstatcache();
            if (is_writable($directory)) {
                self::markTestSkipped('The test user can override directory permissions.');
            }

            $failure = null;
            try {
                $cleaner->deleteUnused($id);
            } catch (\RuntimeException $exception) {
                $failure = $exception;
            }

            self::assertInstanceOf(\RuntimeException::class, $failure);
            self::assertNotNull($repository->find($id));
            self::assertFileExists($file);
            self::assertFalse($pdo->inTransaction());
            self::assertTrue(chmod($directory, 0700));
            clearstatcache();
            self::assertTrue($cleaner->deleteUnused($id));
            self::assertNull($repository->find($id));
            self::assertFileDoesNotExist($file);
        });
    }

    /** @param \Closure(\PDO, PostMediaRepository, PostMediaCleaner, int, string): void $test */
    private function withMedia(\Closure $test): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE config (name TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO config VALUES ('REGISTER_SCHEMA_GENERATION', '38')");
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO users VALUES (1)');
        $pdo->exec("CREATE TABLE content (id INTEGER PRIMARY KEY, body TEXT DEFAULT '', social_image TEXT DEFAULT '')");
        $pdo->exec('INSERT INTO content (id) VALUES (1)');

        $db = new DbLayerSqlite($pdo);
        ContentMediaSchema::create($db);
        $repository = new PostMediaRepository($db, '/media');
        $directory = sys_get_temp_dir() . '/register-cleaner-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $file = $directory . '/1901.01.01.png';
        file_put_contents($file, 'Registered image fixture');
        $translator = new Translator([], 'en');
        $urls = new ContentUrlGenerator($db, new UrlBuilder('', 'https://example.test', ''));
        $cleaner = new PostMediaCleaner(
            $repository,
            new PostInplaceMediaStorage(
                new PictureFileNameHelper($translator, 'png'),
                new PictureStorageQuota($translator, $directory, $directory . '/quota.lock', 1_000_000),
                $translator, $directory, '', $directory,
            ),
            new UrlHistoryService($pdo, $db, $urls, new ContentUrlAliasRepository($db), new TagUrlAliasRepository($db),
                new ContentChangeDispatcher($db, new EventDispatcher(), new LiveUpdateRepository($db))),
            $pdo,
        );
        $id = $repository->register(['original_name' => basename($file), 'normalized_name' => basename($file),
            'storage_path' => '/1901.01.01.png', 'mime_type' => 'image/png', 'kind' => 'image', 'byte_size' => 24,
            'width' => 1, 'height' => 1, 'uploaded_by' => 1]);
        try {
            $test($pdo, $repository, $cleaner, $id, $file);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            chmod($directory, 0700);
            foreach (new \DirectoryIterator($directory) as $entry) {
                if (!$entry->isDot()) {
                    unlink($entry->getPathname());
                }
            }

            rmdir($directory);
        }
    }
}
