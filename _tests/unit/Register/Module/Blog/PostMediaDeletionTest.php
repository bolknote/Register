<?php

declare(strict_types = 1);

namespace unit\Register\Module\Blog;

use Codeception\Test\Unit;
use Register\Content\ContentMediaSchema;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Module\Blog\Inplace\PostMediaRepository;

final class PostMediaDeletionTest extends Unit
{
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
