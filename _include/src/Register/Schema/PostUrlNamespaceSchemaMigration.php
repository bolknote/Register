<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Schema;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Pdo\DbLayer;
use Register\Url\ContentUrlAliasSchema;
use Register\Url\PostUrlNamespace;

/** Moves root-level post URLs below /all/ and retains every previous path as a redirect. */
final readonly class PostUrlNamespaceSchemaMigration implements SchemaMigrationInterface
{
    private const string SAVEPOINT = 'register_post_url_namespace';

    /** @param \Closure(list<ContentId>): void $afterChange */
    public function __construct(
        private \PDO $pdo,
        private \Closure $afterChange,
    ) {
    }

    #[\Override]
    public function fromGeneration(): int
    {
        return 34;
    }

    #[\Override]
    public function toGeneration(): int
    {
        return 35;
    }

    #[\Override]
    public function migrate(DbLayer $dbLayer): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }

        try {
            $posts = $dbLayer
                ->select('id, slug')
                ->from(ContentSchema::TABLE_NAME)
                ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
                ->execute()
                ->fetchAssocAll()
            ;

            /** @var list<array{id: int, previous: string, current: string}> $changes */
            $changes = [];
            foreach ($posts as $post) {
                $previous = (string)$post['slug'];
                if (PostUrlNamespace::isCanonical($previous)) {
                    continue;
                }

                if ($previous === '') {
                    throw new \LogicException('A post with an empty slug cannot be moved below /all/.');
                }

                $current = PostUrlNamespace::canonicalSlug($previous);
                if (strlen($current) > 255) {
                    throw new \LogicException(sprintf(
                        'Post URL "%s" is too long for the /all/ namespace.',
                        $previous,
                    ));
                }

                $id = (int)$post['id'];
                $this->assertCanonicalPathAvailable($dbLayer, $id, $current);
                $this->assertAliasPathAvailable($dbLayer, $id, $previous);
                $changes[] = ['id' => $id, 'previous' => $previous, 'current' => $current];
            }

            foreach ($changes as $change) {
                // A path can be promoted from a same-content alias to the canonical URL.
                $dbLayer
                    ->delete(ContentUrlAliasSchema::TABLE_NAME)
                    ->where('path = :path')->setParameter('path', $change['current'])
                    ->andWhere('content_id = :content_id')->setParameter('content_id', $change['id'])
                    ->execute()
                ;
                $dbLayer
                    ->insert(ContentUrlAliasSchema::TABLE_NAME)
                    ->setValue('path', ':path')->setParameter('path', $change['previous'])
                    ->setValue('content_id', ':content_id')->setParameter('content_id', $change['id'])
                    ->onConflictDoNothing('path')
                    ->execute()
                ;
                $dbLayer
                    ->update(ContentSchema::TABLE_NAME)
                    ->set('slug', ':current')->setParameter('current', $change['current'])
                    ->where('id = :id')->setParameter('id', $change['id'])
                    ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
                    ->andWhere('slug = :previous')->setParameter('previous', $change['previous'])
                    ->execute()
                ;
            }

            if ($changes !== []) {
                ($this->afterChange)(array_map(
                    static fn(array $change): ContentId => ContentId::post($change['id']),
                    $changes,
                ));
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
        } catch (\Throwable $throwable) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            throw $throwable;
        }
    }

    private function assertCanonicalPathAvailable(DbLayer $dbLayer, int $contentId, string $path): void
    {
        $canonicalOwner = $dbLayer
            ->select('id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('slug_scope = :scope')->setParameter('scope', 'root')
            ->andWhere('slug = :slug')->setParameter('slug', $path)
            ->andWhere('id <> :id')->setParameter('id', $contentId)
            ->execute()
            ->result()
        ;
        if ($canonicalOwner !== false && $canonicalOwner !== null) {
            throw new \LogicException(sprintf('Post URL "%s" is already canonical for another item.', $path));
        }

        $this->assertAliasPathAvailable($dbLayer, $contentId, $path);
    }

    private function assertAliasPathAvailable(DbLayer $dbLayer, int $contentId, string $path): void
    {
        $aliasOwner = $dbLayer
            ->select('content_id')
            ->from(ContentUrlAliasSchema::TABLE_NAME)
            ->where('path = :path')->setParameter('path', $path)
            ->execute()
            ->result()
        ;
        if ($aliasOwner !== false && $aliasOwner !== null && (int)$aliasOwner !== $contentId) {
            throw new \LogicException(sprintf('Post URL "%s" is already an alias for another item.', $path));
        }
    }
}
