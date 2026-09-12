<?php

declare(strict_types = 1);

namespace Register\Url;

use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Schema\SchemaManager;

/** Serializes editorial URL changes and stores their aliases in the same transaction. */
final readonly class UrlHistoryService
{
    public function __construct(
        private \PDO $pdo,
        private DbLayer $dbLayer,
        private ContentUrlGenerator $urls,
        private ContentUrlAliasRepository $contentAliases,
        private TagUrlAliasRepository $tagAliases,
        private ContentChangeDispatcher $changes,
    ) {
    }

    /**
     * Wrap the whole editorial operation, including its initial reads. When joining an
     * external MySQL REPEATABLE READ transaction, this mutex must be acquired before
     * its first consistent read: a later savepoint/lock cannot refresh that snapshot.
     *
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public function run(callable $write): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        $savepoint = 'url_history_' . bin2hex(random_bytes(6));
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            // A stable row provides a portable lock across URL namespaces and DB drivers.
            $this->dbLayer->update('config')->set('value', 'value')
                ->where('name = :name')->setParameter('name', SchemaManager::CONFIG_KEY)->execute();
            $result = $write();
            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->transactionActive()) {
                $this->pdo->rollBack();
            } elseif ($this->transactionActive()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            throw $exception;
        }
    }

    /**
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public function changeContent(ContentId $contentId, callable $write): mixed
    {
        return $this->run(function () use ($contentId, $write): mixed {
            $ids = $contentId->type === ContentType::PAGE ? $this->changes->pageBranch($contentId->value) : [$contentId];
            $previousPaths = [];
            foreach ($ids as $id) {
                $previousPaths[(string)$id] = $this->urls->path($id);
            }

            $result = $write();
            foreach ($ids as $id) {
                $path = $this->urls->path($id);
                $previous = $previousPaths[(string)$id];
                if ($path === null || $path === '/' || $previous === $path) {
                    continue;
                }

                $this->contentAliases->assertAvailable($path, $id->value);
                if ($previous !== null && $previous !== '/') {
                    ContentUrlAliasRepository::assertHistoryPathLength($previous);
                    $this->contentAliases->rememberCanonicalChange($id, $previous, $path);
                }
            }

            return $result;
        });
    }

    /**
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public function changeTag(int $tagId, callable $write): mixed
    {
        return $this->run(function () use ($tagId, $write): mixed {
            $previous = $this->tagSlug($tagId);
            $result = $write();
            $current = $this->tagSlug($tagId);
            if ($previous !== null && $current !== null && $previous !== $current) {
                $this->tagAliases->rememberChange($tagId, $previous, $current);
            }

            return $result;
        });
    }

    /** Checks a freshly inserted row before the editorial transaction can commit. */
    public function assertCreatedContentAvailable(int $id): void
    {
        $type = $this->dbLayer->select('content_type')->from('content')
            ->where('id = :id')->setParameter('id', $id)->execute()->result();
        if (!is_string($type)) {
            throw new \LogicException('The newly created content row is missing.');
        }

        $path = $this->urls->path(new ContentId(ContentType::from($type), $id));
        if ($path !== null && $path !== '/') {
            $this->contentAliases->assertAvailable($path, $id);
        }
    }

    private function tagSlug(int $tagId): ?string
    {
        $slug = $this->dbLayer->select('url')->from('tags')
            ->where('id = :id')->setParameter('id', $tagId)->execute()->result();

        return is_string($slug) ? $slug : null;
    }

    private function transactionActive(): bool
    {
        return $this->pdo->inTransaction();
    }
}
