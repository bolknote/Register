<?php

declare(strict_types = 1);

namespace unit\Register\Module\Blog;

use Codeception\Test\Unit;
use Register\Content\ContentMediaSchema;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Module\Blog\Inplace\PostMediaConflictException;
use Register\Module\Blog\Inplace\PostMediaRepository;

final class PostMediaSourceTest extends Unit
{
    public function testNativeAbsoluteSourcesRetainTheRegisteredMediaAndDimensions(): void
    {
        foreach ([
            ['https://example.test/blog', '/blog/media', ['https://example.test', 'https://EXAMPLE.test:443', '//example.test']],
            ['http://example.test:8080/blog', '/blog/media', ['http://example.test:8080', '//example.test:8080']],
        ] as [$base, $prefix, $origins]) {
            [$repository, $id] = $this->repository($prefix, $base);
            foreach ([$repository->url('/image.png'), $repository->url('/image.png', $id)] as $path) {
                foreach (['', ...$origins] as $origin) {
                    $image = '<img src="' . $origin . $path . '" data-post-media-id="' . $id . '" data-post-media-identity="1">';
                    self::assertSame([], $repository->syncPost(1, $image, [], 1));
                    self::assertSame(substr($image, 0, -1) . ' width="320" height="180">', $repository->completeImageDimensions($image));
                    $registered = $repository->find($id);
                    self::assertNotNull($registered);
                    self::assertSame(1, (int)$registered['usage_count']);
                }
            }
        }
    }

    public function testForeignOriginsAndDifferentFileIdentitiesRemainRejected(): void
    {
        [$repository, $id] = $this->repository('/media', 'https://example.test');
        $path = $repository->url('/image.png', $id);
        foreach ([
            'https://foreign.test' . $path,
            'https://example.test.foreign.test' . $path,
            'https://example.test@foreign.test' . $path,
            'https://user@example.test' . $path,
            'http://example.test' . $path,
            'https://example.test:444' . $path,
            '//foreign.test' . $path,
            'ftp://example.test' . $path,
            'https://example.test\\@foreign.test' . $path,
            'https://example.test' . $path . '#fragment',
            'https://example.test' . $path . '&extra=1',
            'https://example.test' . $repository->url('/image.png', $id + 1),
            'https://example.test' . $repository->url('/other.png', $id),
        ] as $source) {
            $image = '<img src="' . $source . '" data-post-media-id="' . $id . '">';
            self::assertSame($image, $repository->completeImageDimensions($image));
            $failure = null;
            try {
                $repository->syncPost(1, $image, [], 1);
            } catch (PostMediaConflictException $exception) {
                $failure = $exception;
            }

            self::assertInstanceOf(PostMediaConflictException::class, $failure, $source);
        }
    }

    public function testAbsoluteConfiguredMediaPrefixesKeepTheirExistingContract(): void
    {
        [$repository, $id] = $this->repository('https://images.example.test/media', 'https://example.test');
        $image = '<img src="' . $repository->url('/image.png', $id) . '" data-post-media-id="' . $id . '">';
        self::assertSame([], $repository->syncPost(1, $image, [], 1));
        self::assertSame(substr($image, 0, -1) . ' width="320" height="180">', $repository->completeImageDimensions($image));
    }

    /** @return array{PostMediaRepository, int} */
    private function repository(string $prefix, string $base): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec("CREATE TABLE content (id INTEGER PRIMARY KEY, body TEXT DEFAULT '', social_image TEXT DEFAULT '')");
        $pdo->exec('INSERT INTO users VALUES (1)');
        $pdo->exec('INSERT INTO content (id) VALUES (1)');

        $db = new DbLayerSqlite($pdo);
        ContentMediaSchema::create($db);
        $repository = new PostMediaRepository($db, $prefix, $base);
        $id = $repository->register([
            'original_name' => 'image.png', 'normalized_name' => 'image.png', 'storage_path' => '/image.png',
            'mime_type' => 'image/png', 'kind' => 'image', 'byte_size' => 100,
            'width' => 320, 'height' => 180, 'uploaded_by' => 1,
        ]);

        return [$repository, $id];
    }
}
