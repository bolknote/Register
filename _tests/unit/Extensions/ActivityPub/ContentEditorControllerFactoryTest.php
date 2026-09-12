<?php

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use Register\AdminYard\Config\DbColumnFieldType;
use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Controller\EntityController;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Database\TypeTransformer;
use Register\AdminYard\Form\FormFactory;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\AdminYard\TemplateRenderer;
use Register\AdminYard\Transformer\ViewTransformer;
use Register\AdminYard\Translator;
use Register\Extension\activitypub\Admin\ActivityPubContentEditorControllerFactory;
use Register\Content\ContentChangeDispatcher;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PDO as TrackedPDO;
use Register\Live\LiveUpdateRepository;
use Register\Url\ContentUrlAliasRepository;
use Register\Url\ContentUrlGenerator;
use Register\Url\TagUrlAliasRepository;
use Register\Url\UrlHistoryService;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Register\Module\Blog\Admin\BlogPostListController;

final class ContentEditorControllerFactoryTest extends Unit
{
    public function testReadOnlyPostListKeepsItsOwnController(): void
    {
        $entity = (new EntityConfig('BlogPost'))
            ->setEnabledActions([FieldConfig::ACTION_LIST, FieldConfig::ACTION_DELETE])
            ->setControllerClassOrFactory(BlogPostListController::class);
        $this->factory()->configure($entity);
        self::assertSame(BlogPostListController::class, $entity->getControllerClassOrFactory());
    }

    public function testEditableContentStillGetsTransactionalWrites(): void
    {
        $entity = (new EntityConfig('Article'))->setEnabledActions([FieldConfig::ACTION_LIST, FieldConfig::ACTION_EDIT]);
        $factory = $this->factory();
        $factory->configure($entity);
        self::assertSame($factory, $entity->getControllerClassOrFactory());
    }

    public function testExistingWriteControllerIsNotSilentlyReplaced(): void
    {
        $entity = (new EntityConfig('Article'))
            ->setEnabledActions([FieldConfig::ACTION_EDIT])
            ->setControllerClassOrFactory(EntityController::class);
        $this->expectException(\LogicException::class);
        $this->factory()->configure($entity);
    }

    public function testPostLocksBeforeFormReadsAndRollsBackProjection(): void
    {
        foreach (['editAction', 'newAction'] as $action) {
            $pdo = new TrackedPDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE config (name TEXT PRIMARY KEY, value TEXT)');
            $pdo->exec("INSERT INTO config VALUES ('REGISTER_SCHEMA_GENERATION', '31')");
            $pdo->exec('CREATE TABLE projection_probe (id INTEGER PRIMARY KEY)');
            $pdo->cleanLogs();
            $formFactory = $this->createMock(FormFactory::class);
            $formFactory->expects(self::once())->method('createEntityForm')->willReturnCallback(static function () use ($pdo): never {
                self::assertTrue($pdo->inTransaction());
                $queries = array_column($pdo->getQueryLog(), 'template');
                self::assertNotEmpty(array_filter($queries, static fn(string $sql): bool => preg_match('/UPDATE\s+config\s+SET\s+value\s*=\s*value/i', $sql) === 1));
                // A form's label/old-content query must never establish the snapshot before that lock.
                self::query($pdo, 'SELECT value FROM config')->fetchColumn();
                $pdo->exec('INSERT INTO projection_probe VALUES (1)');
                throw new \RuntimeException('Projection failed');
            });
            $controller = $this->controller($pdo, $formFactory);
            try {
                if ($action === 'editAction') {
                    $controller->editAction(Request::create('/?id=1', 'POST'));
                } else {
                    $controller->newAction(Request::create('/', 'POST'));
                }

                self::fail('The simulated projection must fail.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Projection failed', $exception->getMessage());
            }

            self::assertFalse($pdo->inTransaction());
            self::assertSame(0, (int)self::query($pdo, 'SELECT COUNT(*) FROM projection_probe')->fetchColumn());
        }
    }

    public function testFailedEditorialSavePreservesAnOuterTransaction(): void
    {
        $pdo = new TrackedPDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE config (name TEXT PRIMARY KEY, value TEXT)');
        $pdo->exec("INSERT INTO config VALUES ('REGISTER_SCHEMA_GENERATION', '31')");
        $pdo->exec('CREATE TABLE projection_probe (id INTEGER PRIMARY KEY)');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO projection_probe VALUES (1)');

        $formFactory = $this->createMock(FormFactory::class);
        $formFactory->method('createEntityForm')->willReturnCallback(static function () use ($pdo): never {
            $pdo->exec('INSERT INTO projection_probe VALUES (2)');
            throw new \RuntimeException('Projection failed');
        });
        try {
            $this->controller($pdo, $formFactory)->newAction(Request::create('/', 'POST'));
            self::fail('The simulated projection must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Projection failed', $exception->getMessage());
        }

        self::assertTrue($pdo->inTransaction());
        self::assertSame([1], self::query($pdo, 'SELECT id FROM projection_probe')->fetchAll(\PDO::FETCH_COLUMN));
        $pdo->rollBack();
    }

    private function controller(\PDO $pdo, FormFactory $formFactory): \Register\Extension\activitypub\Admin\ActivityPubContentEditorController
    {
        $entity = new EntityConfig('Article', 'content');
        $entity->addField(new FieldConfig('id', type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT, true), useOnActions: []));

        $translator = new Translator([], 'en');
        return $this->factory($pdo)->create(
            $entity, new EventDispatcher(), new PdoDataProvider($pdo, new TypeTransformer()), new ViewTransformer(),
            $translator, new TemplateRenderer($translator), $formFactory, self::createStub(SettingStorageInterface::class),
        );
    }

    private function factory(?\PDO $pdo = null): ActivityPubContentEditorControllerFactory
    {
        $pdo ??= new \PDO('sqlite::memory:');
        $db = new DbLayerSqlite($pdo);
        return new ActivityPubContentEditorControllerFactory(new UrlHistoryService(
            $pdo, $db, new ContentUrlGenerator($db, new UrlBuilder('', 'https://example.test', '')),
            new ContentUrlAliasRepository($db), new TagUrlAliasRepository($db),
            new ContentChangeDispatcher($db, new EventDispatcher(), new LiveUpdateRepository($db)),
        ));
    }

    private static function query(\PDO $pdo, string $sql): \PDOStatement
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Unable to query the editorial fixture.');
        }

        return $statement;
    }
}
