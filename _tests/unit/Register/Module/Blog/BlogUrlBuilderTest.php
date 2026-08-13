<?php

declare(strict_types = 1);

namespace unit\Register\Module\Blog;

use Codeception\Test\Unit;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use Register\Module\Blog\BlogUrlBuilder;

final class BlogUrlBuilderTest extends Unit
{
    /**
     * @dataProvider pathPrefixProvider
     */
    public function testPathPrefixNormalization(string $value, string $expected): void
    {
        self::assertSame($expected, BlogUrlBuilder::normalizePathPrefix($value));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathPrefixProvider(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'root slash' => ['/', ''];
        yield 'several root slashes' => ['///', ''];
        yield 'without leading slash' => ['notes', '/notes'];
        yield 'with trailing slash' => ['/notes/', '/notes'];
        yield 'surrounding spaces' => [' /notes/ ', '/notes'];
    }

    public function testRootBlogUsesShortPostUrls(): void
    {
        [$builder, $configFile] = $this->createBuilder('/');

        try {
            self::assertSame('', $builder->pathPrefix());
            self::assertTrue($builder->blogIsOnTheSiteRoot());
            self::assertSame('/', $builder->main());
            self::assertSame('/hello%20world', $builder->post('hello world'));
            self::assertSame('https://example.test/hello%20world', $builder->absPost('hello world'));
            self::assertSame('/hello%20world', $builder->postWithoutPrefix('hello world'));
            self::assertTrue($builder->isReservedPostSlug('search'));
        } finally {
            if (file_exists($configFile)) {
                unlink($configFile);
            }
        }
    }

    public function testOptionalPrefixDoesNotAffectPermalinkShape(): void
    {
        [$builder, $configFile] = $this->createBuilder('/notes/');

        try {
            self::assertSame('/notes', $builder->pathPrefix());
            self::assertFalse($builder->blogIsOnTheSiteRoot());
            self::assertSame('/notes/', $builder->main());
            self::assertSame('/notes/hello', $builder->post('hello'));
            self::assertFalse($builder->isReservedPostSlug('search'));
        } finally {
            if (file_exists($configFile)) {
                unlink($configFile);
            }
        }
    }

    /**
     * @return array{BlogUrlBuilder, string}
     */
    private function createBuilder(string $blogUrl): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dbLayer = new DbLayer($pdo);
        $dbLayer->query('CREATE TABLE config (name TEXT PRIMARY KEY, value TEXT)');
        foreach ([
            'S2_BLOG_URL'     => $blogUrl,
            'S2_TAGS_URL'     => 'tags',
            'S2_FAVORITE_URL' => 'favorite',
        ] as $name => $value) {
            $dbLayer->query(
                'INSERT INTO config (name, value) VALUES (:name, :value)',
                [':name' => $name, ':value' => $value]
            );
        }

        $configFile = tempnam(sys_get_temp_dir(), 'register_blog_config_');
        if ($configFile === false) {
            throw new \RuntimeException('Unable to allocate a temporary configuration file.');
        }

        unlink($configFile);
        $provider = new DynamicConfigProvider($dbLayer, $configFile, true);

        return [
            new BlogUrlBuilder(
                new UrlBuilder('', 'https://example.test', ''),
                $provider->getStringProxy('S2_TAGS_URL'),
                $provider->getStringProxy('S2_FAVORITE_URL'),
                $provider->getStringProxy('S2_BLOG_URL'),
            ),
            $configFile,
        ];
    }
}
