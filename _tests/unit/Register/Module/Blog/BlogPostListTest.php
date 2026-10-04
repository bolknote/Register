<?php

declare(strict_types = 1);

namespace unit\Register\Module\Blog;

use Codeception\Test\Unit;
use Register\AdminYard\Config\AdminConfig;
use Register\AdminYard\Config\DbColumnFieldType;
use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Database\TypeTransformer;
use Register\AdminYard\Form\FormControlFactory;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SessionSettingStorage;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentMediaSchema;
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Content\TagRepository;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Config\StringProxy;
use Register\Core\Model\PermissionChecker;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Live\LiveUpdateRepository;
use Register\Module\Blog\Admin\AdminConfigExtender;
use Register\Module\Blog\Admin\BlogPostListController;
use Register\Module\Blog\Admin\BlogPostListControllerFactory;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Url\ContentUrlGenerator;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class BlogPostListTest extends Unit
{
    public function testStatesHaveSeparateRowsCountsAndChronologicalOrder(): void
    {
        [$controller, $renderer] = $this->harness(true);
        $controller->listAction(Request::create('/_admin/?state=draft'));
        self::assertSame([6, 3], $this->rowIds($renderer));
        self::assertSame(['all' => 8, 'draft' => 2, 'scheduled' => 4, 'published' => 2], $renderer->listData['postListCounts']);

        $controller->listAction(Request::create('/_admin/?state=scheduled'));
        self::assertSame([8, 4, 5, 7], $this->rowIds($renderer));
        self::assertStringContainsString('Publication overdue', (string) $renderer->listData['rows'][0]['cells']['publication_state']['content']);

        $controller->listAction(Request::create('/_admin/?state=published'));
        self::assertSame([2, 1], $this->rowIds($renderer));
        self::assertSame(['title', 'publication_state', 'editorial_date', 'comments'], array_keys($renderer->listData['header']));
    }

    public function testOwnershipConstrainsCountsRowsAndEditLinks(): void
    {
        [$controller, $renderer] = $this->harness(false);
        $controller->listAction(Request::create('/_admin/?state=all'));
        self::assertSame(['all' => 5, 'draft' => 1, 'scheduled' => 2, 'published' => 2], $renderer->listData['postListCounts']);
        self::assertCount(5, $renderer->listData['rows']);
        foreach ($renderer->listData['rows'] as $row) {
            $title = $row['cells']['title']['content'];
            if ((int)$row['primary_key']['id'] === 2) {
                self::assertStringNotContainsString('editor=edit', (string) $title);
                self::assertStringContainsString('/blog/all/post-2', (string) $title);
            } else {
                self::assertStringContainsString('editor=edit', (string) $title);
            }
        }

        self::assertSame('/blog/?editor=new', $renderer->listData['listPrimaryActions'][0]['url']);
        $authorFilter = $renderer->listData['filterControls']['author_id']->getHtml();
        self::assertStringContainsString('Own author', (string) $authorFilter);
        self::assertStringContainsString('Other author', (string) $authorFilter);
        self::assertStringNotContainsString('Imported guest', (string) $authorFilter);
    }

    public function testViewOnlyUserCannotOpenAnEditorOrCreate(): void
    {
        [$controller, $renderer] = $this->harness(false, false);
        $controller->listAction(Request::create('/_admin/?state=all'));
        self::assertSame([], $renderer->listData['listPrimaryActions']);
        foreach ($renderer->listData['rows'] as $row) {
            self::assertStringNotContainsString('editor=edit', (string) $row['cells']['title']['content']);
        }
    }

    public function testOldUnpublishedLinksAndSearchRemainUseful(): void
    {
        [$controller, $renderer] = $this->harness(true);
        $controller->listAction(Request::create('/_admin/?is_active=0&apply_filter=1'));
        self::assertSame('unpublished', $renderer->listData['postListState']);
        self::assertSame([5, 4, 6, 3, 8], $this->rowIds($renderer));

        $controller->listAction(Request::create('/_admin/?state=all&search=Own+draft&apply_filter=1'));
        self::assertSame([3], $this->rowIds($renderer));
        self::assertSame(['all' => 1, 'draft' => 1, 'scheduled' => 0, 'published' => 0], $renderer->listData['postListCounts']);
    }

    public function testCommentCountsExcludeDeletedAndInaccessibleComments(): void
    {
        foreach ([[true, '2'], [false, '1']] as [$admin, $count]) {
            [$controller, $renderer] = $this->harness($admin);
            $controller->listAction(Request::create('/_admin/?state=published'));
            $html = $renderer->listData['rows'][0]['cells']['comments']['content'];
            self::assertStringContainsString('>' . $count . '</a>', (string) $html);
            self::assertStringContainsString('<span>0</span>', (string) $renderer->listData['rows'][1]['cells']['comments']['content']);
        }
    }

    public function testCommittedDeletionRemovesOnlyUnsharedMedia(): void
    {
        foreach ([null, 'page', 'post'] as $shared) {
            $directory = sys_get_temp_dir() . '/register-list-media-' . bin2hex(random_bytes(6));
            mkdir($directory);
            $file = $directory . '/1901.01.01.png';
            try {
                [$controller, $renderer, $pdo, $repository] = $this->harness(true, true, $directory);
                [$id, $body] = $this->attachMedia($pdo, $repository, $file);
                if ($shared !== null) {
                    $target = $shared === 'page' ? 9 : 1;
                    $update = $pdo->prepare('UPDATE content SET body = ? WHERE id = ?');
                    self::assertInstanceOf(\PDOStatement::class, $update);
                    $update->execute([$body, $target]);
                    if ($shared === 'post') {
                        $repository->syncPost($target, $body, [], 7);
                    }
                }

                $controller->listAction(Request::create('/_admin/?state=published'));
                $request = Request::create('/_admin/?id=2', 'POST', ['csrf_token' => $renderer->listData['rows'][0]['csrf_token']],
                    server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
                self::assertSame(200, $controller->deleteAction($request)->getStatusCode());
                self::assertSame(0, $this->countRows($pdo, 'SELECT COUNT(*) FROM content WHERE id = 2'));
                if ($shared === null) {
                    self::assertNull($repository->find($id));
                    self::assertFileDoesNotExist($file);
                } else {
                    $media = $repository->find($id);
                    self::assertNotNull($media);
                    self::assertSame($shared === 'post' ? 1 : 0, (int)$media['usage_count']);
                    self::assertFileExists($file);
                    self::assertFalse($repository->deleteUnused($id));
                }
            } finally {
                if (is_file($file)) {
                    unlink($file);
                }

                rmdir($directory);
            }
        }
    }

    public function testRejectedDeletionRollsBackMediaRelations(): void
    {
        foreach ([true, false] as $admin) {
            $directory = sys_get_temp_dir() . '/register-list-media-' . bin2hex(random_bytes(6));
            mkdir($directory);
            $file = $directory . '/1901.01.01.png';
            try {
                [$controller, $renderer, $pdo, $repository] = $this->harness($admin, true, $directory);
                [$id] = $this->attachMedia($pdo, $repository, $file);
                $controller->listAction(Request::create('/_admin/?state=published'));
                $request = Request::create('/_admin/?id=2', 'POST', ['csrf_token' => $admin ? 'invalid' : $renderer->listData['rows'][0]['csrf_token']],
                    server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
                self::assertSame($admin ? 422 : 500, $controller->deleteAction($request)->getStatusCode());
                self::assertSame(1, $this->countRows($pdo, 'SELECT COUNT(*) FROM content WHERE id = 2'));
                $media = $repository->find($id);
                self::assertNotNull($media);
                self::assertSame(1, (int)$media['usage_count']);
                self::assertSame(0, (int)$media['pending']);
                self::assertSame(1, $this->countRows($pdo, 'SELECT COUNT(*) FROM content_media_usage WHERE post_id = 2'));
                self::assertFileExists($file);
            } finally {
                if (is_file($file)) {
                    unlink($file);
                }

                rmdir($directory);
            }
        }
    }

    /** @return array{int, string} */
    private function attachMedia(\PDO $pdo, PostMediaRepository $repository, string $file): array
    {
        file_put_contents($file, 'Registered image fixture');
        $id = $repository->register(['original_name' => basename($file), 'normalized_name' => basename($file),
            'storage_path' => '/1901.01.01.png', 'mime_type' => 'image/png', 'kind' => 'image', 'byte_size' => 24,
            'width' => 1, 'height' => 1, 'uploaded_by' => 8]);
        $body = '<img src="/images/1901.01.01.png" data-post-media-id="' . $id . '" data-post-media-identity="1">';
        $update = $pdo->prepare('UPDATE content SET body = ? WHERE id = 2');
        self::assertInstanceOf(\PDOStatement::class, $update);
        $update->execute([$body]);
        $repository->syncPost(2, $body, [$id], 8);

        return [$id, $body];
    }

    private function countRows(\PDO $pdo, string $sql): int
    {
        $rows = $pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $rows);

        return (int)$rows->fetchColumn();
    }

    /** @return array{BlogPostListController, BlogPostListTestRenderer, \PDO, PostMediaRepository} */
    private function harness(bool $admin, bool $canWrite = true, ?string $mediaDirectory = null): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $db = new DbLayerSqlite($pdo);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, login TEXT)');
        $pdo->exec("INSERT INTO users VALUES (7, 'Own author', 'own'), (8, 'Other author', 'other'), (9, 'Imported guest', 'external_import')");
        $pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, content_type TEXT, content_id INTEGER, shown INTEGER DEFAULT 1, deleted INTEGER DEFAULT 0)');
        $pdo->exec("INSERT INTO comments VALUES (1, 'post', 2, 1, 0), (2, 'post', 2, 0, 0), (3, 'post', 2, 1, 1), (4, 'post', 2, 0, 1)");
        ContentSchema::create($db);
        ContentTagSchema::create($db);
        ContentMediaSchema::create($db);
        $now = time();
        $insert = $pdo->prepare('INSERT INTO content (id, content_type, slug_scope, slug, title, excerpt, body, author_id, published, published_at, scheduled_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        self::assertInstanceOf(\PDOStatement::class, $insert);
        foreach ([
            [1, 'post', 7, 1, $now - 400, 0, $now - 400, 'Old post'],
            [2, 'post', 8, 1, $now - 100, 0, $now - 100, 'New post'],
            [3, 'post', 7, 0, null, 0, $now - 5, 'Own draft'],
            [4, 'post', 7, 0, null, $now + 100, $now - 20, 'Own scheduled'],
            [5, 'post', 8, 0, null, $now + 200, $now - 20, 'Other scheduled'],
            [6, 'post', 8, 0, null, 0, $now - 1, 'Other draft'],
            [7, 'post', 8, 1, $now + 300, 0, $now - 20, 'Legacy future post'],
            [8, 'post', 7, 0, null, $now - 10, $now - 20, 'Overdue post'],
            [9, 'page', 8, 1, $now, 0, $now, 'Page'],
        ] as [$id, $type, $author, $published, $publishedAt, $scheduledAt, $updatedAt, $title]) {
            $slug = $type === 'post' ? 'all/post-' . $id : 'post-' . $id;
            $insert->execute([$id, $type, 'root', $slug, $title, '', '<p>Body</p>', $author, $published, $publishedAt, $scheduledAt, $now - 500, $updatedAt]);
        }

        $permissions = new PermissionChecker();
        $permissions->setUser(['id' => 7, 'view' => true, 'create_articles' => $canWrite, 'view_hidden' => $admin, 'edit_site' => $admin]);

        $configProvider = new DynamicConfigProvider();
        $urlBuilder = new UrlBuilder('/blog', 'https://example.test/blog', '');
        $blogUrls = new BlogUrlBuilder($urlBuilder, new StringProxy($configProvider, 'tags'), new StringProxy($configProvider, 'favorites'));
        $translator = new Translator([], 'en');
        $repository = new PostMediaRepository($db, '/images');
        $factory = new BlogPostListControllerFactory(
            new ContentUrlGenerator($db, $urlBuilder), $blogUrls, $permissions, $pdo,
            $repository,
            new \Register\Module\Blog\Inplace\PostInplaceMediaStorage(
                new \Register\Admin\Picture\PictureFileNameHelper($translator, 'png'),
                new \Register\Admin\Picture\PictureStorageQuota($translator, sys_get_temp_dir(), sys_get_temp_dir() . '/register-list-quota.lock', 1_000_000),
                $translator, $mediaDirectory ?? sys_get_temp_dir(), '', sys_get_temp_dir(),
            ),
        );
        $events = new EventDispatcher();
        $config = new AdminConfig();
        foreach (['User' => 'users', 'Comment' => 'comments', 'Tag' => 'tags'] as $name => $table) {
            $entity = new EntityConfig($name, $table);
            $entity->addField(new FieldConfig('id', type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT, true), useOnActions: []));
            $config->addEntity($entity);
        }

        (new AdminConfigExtender(
            $permissions,
            $translator,
            new TagRepository($db),
            new ContentChangeDispatcher($db, $events, new LiveUpdateRepository($db)),
            new BlogPageCache(new ArrayAdapter()),
            $factory,
            'sqlite',
            '',
        ))->extend($config);
        $entity = $config->findEntityByName('BlogPost');
        self::assertInstanceOf(EntityConfig::class, $entity);
        $dataProvider = new PdoDataProvider($pdo, new TypeTransformer());
        $renderer = new BlogPostListTestRenderer($translator);
        $controller = $factory->create(
            $entity,
            $events,
            $dataProvider,
            new ViewTransformer(),
            $translator,
            $renderer,
            new FormFactory(new FormControlFactory(), $translator, $dataProvider),
            new SessionSettingStorage(new Session(new MockArraySessionStorage())),
        );
        return [$controller, $renderer, $pdo, $repository];
    }

    /** @return list<int> */
    private function rowIds(BlogPostListTestRenderer $renderer): array
    {
        return array_values(array_map(static fn(array $row): int => (int)$row['primary_key']['id'], $renderer->listData['rows']));
    }
}

final class BlogPostListTestRenderer extends TemplateRenderer
{
    /** @var array<string, mixed> */
    public array $listData = [];

    /** @param array<mixed> $data */
    #[\Override]
    public function render(string $_template_path, array $data = []): string
    {
        if ($_template_path === '_admin/templates/blog-list.php.inc') {
            $this->listData = $data;
            return '';
        }

        return parent::render($_template_path, $data);
    }
}
