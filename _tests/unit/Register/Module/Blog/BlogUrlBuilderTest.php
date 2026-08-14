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
    public function testRegisterUsesCanonicalRootUrls(): void
    {
        [$builder, $configFile] = $this->createBuilder();

        try {
            self::assertSame('/', $builder->main());
            self::assertSame('/all/', $builder->all());
            self::assertSame('/archive/2023/', $builder->year(2023));
            self::assertSame('/archive/2023/08/', $builder->month(2023, 8));
            self::assertSame('/archive/2023/08/12/', $builder->day(2023, 8, 12));
        } finally {
            if (file_exists($configFile)) {
                unlink($configFile);
            }
        }
    }

    /**
     * @return array{BlogUrlBuilder, string}
     */
    private function createBuilder(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $dbLayer = new DbLayer($pdo);
        $dbLayer->query('CREATE TABLE config (name TEXT PRIMARY KEY, value TEXT)');
        foreach ([
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
            ),
            $configFile,
        ];
    }
}
