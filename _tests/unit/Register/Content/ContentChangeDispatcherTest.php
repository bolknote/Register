<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Content;

use Codeception\Test\Unit;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentChangedEvent;
use Register\Content\ContentId;
use S2\Cms\Pdo\DbLayerSqlite;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ContentChangeDispatcherTest extends Unit
{
    public function testDispatchesTypedChangesOnce(): void
    {
        $collector  = new ChangedContentCollector();
        $dispatcher = $this->createDispatcher($collector);
        $dispatcher->dispatch(ContentId::post(5), ContentId::post(5), ContentId::page(4));

        self::assertSame(['post:5', 'page:4'], $collector->all());
    }

    public function testCapturesAndDispatchesPageBranch(): void
    {
        $collector  = new ChangedContentCollector();
        $dispatcher = $this->createDispatcher($collector);
        self::assertSame(
            ['page:2', 'page:3'],
            array_map(strval(...), $dispatcher->pageBranch(2)),
        );

        $dispatcher->dispatchPageBranch(1);

        self::assertSame(['page:1', 'page:2', 'page:4', 'page:3'], $collector->all());
        self::assertSame([], $dispatcher->pageBranch(999));
    }

    public function testDefersAndFlushesDeletionChanges(): void
    {
        $collector  = new ChangedContentCollector();
        $dispatcher = $this->createDispatcher($collector);
        $dispatcher->defer(ContentId::post(5), ContentId::post(5));
        self::assertSame([], $collector->all());

        $dispatcher->flush();
        self::assertSame(['post:5'], $collector->all());

        $dispatcher->flush();
        self::assertSame(['post:5'], $collector->all());
    }

    public function testClearingRequestStateDropsDeferredChanges(): void
    {
        $collector  = new ChangedContentCollector();
        $dispatcher = $this->createDispatcher($collector);
        $dispatcher->defer(ContentId::page(2));
        $dispatcher->clearState();
        $dispatcher->flush();

        self::assertSame([], $collector->all());
    }

    private function createDispatcher(ChangedContentCollector $collector): ContentChangeDispatcher
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec(<<<'SQL'
CREATE TABLE content (
    id INTEGER PRIMARY KEY,
    content_type VARCHAR(8) NOT NULL,
    parent_id INTEGER NULL
)
SQL);
        $pdo->exec(<<<'SQL'
INSERT INTO content (id, content_type, parent_id) VALUES
    (1, 'page', NULL),
    (2, 'page', 1),
    (3, 'page', 2),
    (4, 'page', 1),
    (5, 'post', NULL)
SQL);

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(ContentChangedEvent::class, $collector);

        return new ContentChangeDispatcher(new DbLayerSqlite($pdo), $eventDispatcher);
    }
}

/** @internal */
final class ChangedContentCollector
{
    /** @var list<string> */
    private array $changed = [];

    public function __invoke(ContentChangedEvent $event): void
    {
        $this->changed[] = (string)$event->contentId;
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->changed;
    }
}
