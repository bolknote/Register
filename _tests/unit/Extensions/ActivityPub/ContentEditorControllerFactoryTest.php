<?php

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Controller\EntityController;
use Register\Extension\activitypub\Admin\ActivityPubContentEditorControllerFactory;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
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

    private function factory(): ActivityPubContentEditorControllerFactory
    {
        return new ActivityPubContentEditorControllerFactory(new PortableDatabaseTransaction(new \PDO('sqlite::memory:')));
    }
}
