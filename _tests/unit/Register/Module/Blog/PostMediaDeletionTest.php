<?php

declare(strict_types = 1);

namespace unit\Register\Module\Blog;

use Codeception\Test\Unit;
use Register\Content\ContentMediaSchema;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Module\Blog\Inplace\PostMediaConflictException;
use Register\Module\Blog\Inplace\PostMediaRepository;

final class PostMediaDeletionTest extends Unit
{
    public function testVersionedMediaUrlsPreserveLegacySourcesAndRejectOtherIdentities(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec("CREATE TABLE content (id INTEGER PRIMARY KEY, body TEXT DEFAULT '', social_image TEXT DEFAULT '')");
        $pdo->exec('INSERT INTO users VALUES (1)');
        $pdo->exec('INSERT INTO content (id) VALUES (1)');

        $db = new DbLayerSqlite($pdo);
        ContentMediaSchema::create($db);
        $repository = new PostMediaRepository($db, '/media');
        $path = '/uploaded/real.png';
        $id = $repository->register($this->media($path));
        foreach ([$repository->url($path), $repository->url($path, $id)] as $source) {
            $image = '<img src="' . $source . '" data-post-media-id="' . $id . '" alt="Example">';
            self::assertSame([], $repository->syncPost(1, $image, [$id], 1));
            $registered = $repository->find($id);
            self::assertNotNull($registered);
            self::assertSame(1, (int)$registered['usage_count']);
            self::assertSame(substr($image, 0, -1) . ' width="1" height="1">',
                $repository->completeImageDimensions($image));
        }

        foreach ([$repository->url($path, $id + 1), '/media/uploaded/other.png?media=' . $id] as $source) {
            $image = '<img src="' . $source . '" data-post-media-id="' . $id . '">';
            self::assertSame($image, $repository->completeImageDimensions($image));
            $failure = null;
            try {
                $repository->syncPost(1, $image, [$id], 1);
            } catch (PostMediaConflictException $exception) {
                $failure = $exception;
            }

            self::assertInstanceOf(PostMediaConflictException::class, $failure);
            $registered = $repository->find($id);
            self::assertNotNull($registered);
            self::assertSame(1, (int)$registered['usage_count']);
        }
    }

    public function testDimensionCompletionPreservesImageMarkupInsideHtmlText(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec("CREATE TABLE content (id INTEGER PRIMARY KEY, body TEXT DEFAULT '', social_image TEXT DEFAULT '')");
        $pdo->exec('INSERT INTO users VALUES (1)');

        $db = new DbLayerSqlite($pdo);
        ContentMediaSchema::create($db);
        $repository = new PostMediaRepository($db, '/media');
        $id = $repository->register($this->media('/uploaded/real.png'));
        $image = "<img src='/media/uploaded/real.png' data-post-media-id='" . $id . "' alt='Example'>";
        $completed = substr($image, 0, -1) . ' width="1" height="1">';
        foreach ([
            '<!-- ' . $image . ' -->',
            '<script>const example = "' . $image . '";</script>',
            '<style>.example::before {content: "' . $image . '";}</style>',
            '<textarea>' . $image . '</textarea>',
            '<title>' . $image . '</title>',
            '<div data-example="' . $image . '"></div>',
            '<div class="post-html-block" data-post-html-source="'
                . htmlspecialchars($image, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . '<script>const closing = "</div>";</script><div>' . $image . '</div></div>',
            '<![CDATA[' . $image . ']]>',
            '<!-- ' . str_repeat($image, 1001) . ' -->',
        ] as $opaque) {
            self::assertSame($opaque . $completed, $repository->completeImageDimensions($opaque . $image));
        }
    }

    public function testStaleMediaSkipsReferencesBeforeLimitWithoutChangingPublishedStatus(): void
    {
        foreach (['', 'cms_'] as $prefix) {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('CREATE TABLE ' . $prefix . 'users (id INTEGER PRIMARY KEY)');
            $pdo->exec('CREATE TABLE ' . $prefix . "content (id INTEGER PRIMARY KEY, body TEXT DEFAULT '', social_image TEXT DEFAULT '')");
            $pdo->exec('INSERT INTO ' . $prefix . 'users VALUES (1)');
            $pdo->exec('INSERT INTO ' . $prefix . 'content (id) VALUES (1)');
            $db = new DbLayerSqlite($pdo, $prefix);
            ContentMediaSchema::create($db);
            $repository = new PostMediaRepository($db, '/media');
            $body = $repository->register($this->media('/uploaded/body_1!%.png'));
            $social = $repository->register($this->media('/uploaded/social.png'));
            $orphan = $repository->register($this->media('/uploaded/published.png'));
            $linked = $repository->register($this->media('/uploaded/linked.png'));
            $pending = $repository->register($this->media('/uploaded/pending.png'));
            $db->update('content')->set('body', ':body')->set('social_image', ':social')
                ->setParameter('body', '<img src="/media/uploaded/body_1!%.png">')
                ->setParameter('social', '/media/uploaded/social.png')->where('id = 1')->execute();
            $db->update(ContentMediaSchema::FILE_TABLE)->set('created_at', '1')->execute();
            $db->update(ContentMediaSchema::FILE_TABLE)->set('pending', '0')
                ->where('id = :id')->setParameter('id', $orphan)->execute();
            $db->insert(ContentMediaSchema::USAGE_TABLE)->values(['post_id' => '1', 'media_id' => ':media'])
                ->execute(['media' => $linked]);

            self::assertTrue($repository->hasStoredReferences('/uploaded/body_1!%.png'));
            self::assertTrue($repository->hasStoredReferences('/uploaded/social.png'));
            self::assertFalse($repository->hasStoredReferences('/uploaded/bodyX1!Z.png'));
            self::assertSame([$orphan], array_column($repository->staleUnusedMedia(10, 1), 'id'));
            self::assertSame([$orphan, $pending], array_column($repository->staleUnusedMedia(10), 'id'));
            $media = $repository->find($orphan);
            self::assertNotNull($media);
            self::assertSame(0, (int)$media['pending']);
            self::assertNotNull($repository->find($body));
            self::assertNotNull($repository->find($social));
        }
    }

    public function testSharedPathsAndSocialMetadataProtectFilesWithAnyTablePrefix(): void
    {
        foreach (['', 'cms_'] as $prefix) {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('CREATE TABLE ' . $prefix . 'users (id INTEGER PRIMARY KEY)');
            $pdo->exec('CREATE TABLE ' . $prefix . "content (id INTEGER PRIMARY KEY, body TEXT DEFAULT '', social_image TEXT DEFAULT '')");
            $pdo->exec('INSERT INTO ' . $prefix . 'users VALUES (1)');
            $pdo->exec('INSERT INTO ' . $prefix . 'content (id) VALUES (1)');
            $db = new DbLayerSqlite($pdo, $prefix);
            ContentMediaSchema::create($db);
            $repository = new PostMediaRepository($db, '/media');
            $shared = $repository->register($this->media('/uploaded/shared_file.png'));
            $unused = $repository->register($this->media('/uploaded/unused_file.png'));
            $wildcards = $repository->register($this->media('/uploaded/file_1%.png'));
            $db->update('content')->set('body', ':body')->setParameter('body',
                '<img src="/media/uploaded/shared_file.png"><img src="/media/uploaded/fileX1Z.png">')
                ->where('id = 1')->execute();

            self::assertFalse($repository->deleteUnused($shared));
            self::assertTrue($repository->deleteUnused($unused));
            self::assertTrue($repository->deleteUnused($wildcards), 'SQL wildcard characters in a path must remain literal');
            $db->update('content')->set('body', "''")->set('social_image', ':image')
                ->setParameter('image', '/media/uploaded/shared_file.png')->where('id = 1')->execute();
            self::assertFalse($repository->deleteUnused($shared));
            $db->update('content')->set('social_image', "''")->where('id = 1')->execute();
            self::assertTrue($repository->deleteUnused($shared));
        }
    }

    public function testEncodedReferencesProtectMediaInBodiesAndSocialMetadata(): void
    {
        foreach (['', 'cms_'] as $prefix) {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE ' . $prefix . 'users (id INTEGER PRIMARY KEY)');
            $pdo->exec('CREATE TABLE ' . $prefix . "content (id INTEGER PRIMARY KEY, body TEXT DEFAULT '', social_image TEXT DEFAULT '')");
            $pdo->exec('INSERT INTO ' . $prefix . 'users VALUES (1)');
            $pdo->exec('INSERT INTO ' . $prefix . 'content (id) VALUES (1)');
            $db = new DbLayerSqlite($pdo, $prefix);
            ContentMediaSchema::create($db);
            $repository = new PostMediaRepository($db, '/media');
            $path = '/uploads/2026.01.02@2x.png';
            $shared = $repository->register($this->media($path));
            $orphan = $repository->register($this->media('/uploads/unused.png'));
            $db->update(ContentMediaSchema::FILE_TABLE)->set('created_at', '1')->execute();

            foreach (['body', 'social_image'] as $column) {
                foreach ([
                    str_replace('@', '%40', $path),
                    str_replace('.', '%2e', $path),
                    str_replace('/', '%2F', $path),
                    str_replace('@', '&#x40;', $path),
                    str_replace('.', '&#46;', $path),
                    str_replace('/', '&sol;', $path),
                    str_replace('@', '&#37;40', $path),
                    str_replace('/uploads', '&#47uploads', $path),
                    str_replace('.png', '&#46png', $path),
                    str_replace('.png', '&#x2Epng', $path),
                    str_replace('/uploads', '&#' . str_repeat('0', 64) . '47uploads', $path),
                    str_replace('/uploads', '&#x' . str_repeat('0', 64) . '2Fuploads', $path),
                ] as $encoded) {
                    $reference = '/media' . $encoded;
                    $db->update('content')->set('body', ':body')->set('social_image', ':social')
                        ->setParameter('body', $column === 'body' ? '<img src="' . $reference . '">' : '')
                        ->setParameter('social', $column === 'social_image' ? $reference : '')
                        ->where('id = 1')->execute();
                    self::assertTrue($repository->hasStoredReferences($path), $reference);
                    self::assertFalse($repository->hasStoredReferences('/uploads/2026.01.03@2x.png'));
                    self::assertFalse($repository->deleteUnused($shared), $reference);
                    self::assertSame([$orphan], array_column($repository->staleUnusedMedia(10, 1), 'id'));
                }
            }

            $db->update('content')->set('body', "''")->set('social_image', "''")->execute();
            self::assertTrue($repository->deleteUnused($shared));
        }
    }

    public function testNumericReferencesConsumeTheCompleteNumber(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec("CREATE TABLE content (id INTEGER PRIMARY KEY, body TEXT DEFAULT '', social_image TEXT DEFAULT '')");
        $pdo->exec('INSERT INTO users VALUES (1)');
        $pdo->exec('INSERT INTO content (id) VALUES (1)');

        $db = new DbLayerSqlite($pdo);
        ContentMediaSchema::create($db);
        $repository = new PostMediaRepository($db, '/media');
        $path = '/2026.01.02.png';
        $mediaId = $repository->register($this->media($path));
        foreach (['&#472026.01.02.png', '&#x2F2026.01.02.png'] as $encoded) {
            $db->update('content')->set('body', ':body')
                ->setParameter('body', '<img src="/media' . $encoded . '">')->where('id = 1')->execute();
            self::assertFalse($repository->hasStoredReferences($path), $encoded);
        }

        self::assertTrue($repository->deleteUnused($mediaId));
    }

    public function testEncodedReferencesCannotStarveStaleMediaCleanup(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec("CREATE TABLE content (id INTEGER PRIMARY KEY, body TEXT DEFAULT '', social_image TEXT DEFAULT '')");
        $pdo->exec('INSERT INTO users VALUES (1)');
        $pdo->exec('INSERT INTO content (id) VALUES (1)');

        $db = new DbLayerSqlite($pdo);
        ContentMediaSchema::create($db);
        $repository = new PostMediaRepository($db, '/media');
        for ($page = 2; $page <= 25; ++$page) {
            $db->insert('content')->values(['id' => ':id', 'body' => ':body'])
                ->execute(['id' => $page, 'body' => '<a href="/other?from=archive&amp;page=' . $page . '">Archive</a>']);
        }

        $images = [];
        for ($index = 0; $index < 120; ++$index) {
            $path = '/uploads/2026.01.02.' . $index . '@2x.png';
            $repository->register($this->media($path));
            $images[] = '<img src="/media' . str_replace('.', '%2e', $path) . '">';
        }

        $db->update('content')->set('body', ':body')->setParameter('body', implode('', $images))->where('id = 25')->execute();
        $db->update(ContentMediaSchema::FILE_TABLE)->set('created_at', '1')->execute();
        $orphan = $repository->register($this->media('/uploads/unused.png'));
        $db->update(ContentMediaSchema::FILE_TABLE)->set('created_at', '2')->where('id = :id')->setParameter('id', $orphan)->execute();

        self::assertSame([$orphan], array_column($repository->staleUnusedMedia(10, 1), 'id'));
        self::assertSame([$orphan], array_column($repository->staleUnusedMedia(10), 'id'));
    }

    /** @return array{original_name: string, normalized_name: string, storage_path: string, mime_type: string, kind: string, byte_size: int, width: int, height: int, uploaded_by: int} */
    private function media(string $path): array
    {
        return ['original_name' => basename($path), 'normalized_name' => basename($path), 'storage_path' => $path,
            'mime_type' => 'image/png', 'kind' => 'image', 'byte_size' => 67, 'width' => 1, 'height' => 1, 'uploaded_by' => 1];
    }
}
