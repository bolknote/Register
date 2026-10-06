<?php

declare(strict_types = 1);

namespace unit\Register\Module\Blog;

use Codeception\Test\Unit;
use Register\Content\ContentMediaSchema;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Module\Blog\Inplace\PostMediaRepository;

final class PostMediaDeletionTest extends Unit
{
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

    /** @return array{original_name: string, normalized_name: string, storage_path: string, mime_type: string, kind: string, byte_size: int, width: int, height: int, uploaded_by: int} */
    private function media(string $path): array
    {
        return ['original_name' => basename($path), 'normalized_name' => basename($path), 'storage_path' => $path,
            'mime_type' => 'image/png', 'kind' => 'image', 'byte_size' => 67, 'width' => 1, 'height' => 1, 'uploaded_by' => 1];
    }
}
