<?php

declare(strict_types = 1);

namespace Register\Admin;

use Register\AdminYard\Database\Key;
use Register\AdminYard\Database\PdoDataProvider;
use Register\AdminYard\Database\SafeDataProviderException;
use Register\AdminYard\Database\TypeTransformerInterface;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Url\ContentUrlCollisionException;
use Register\Url\TagUrlAliasRepository;
use Register\Url\UrlHistoryService;

/** Preserves the URLs of actual authorized AdminYard writes, including inline patches. */
final readonly class UrlHistoryDataProvider extends PdoDataProvider
{
    private UrlHistoryInsertState $insertState;

    public function __construct(
        \PDO $pdo,
        TypeTransformerInterface $typeTransformer,
        private UrlHistoryService $history,
        private TagUrlAliasRepository $tagAliases,
        private string $prefix,
    ) {
        parent::__construct($pdo, $typeTransformer);
        $this->insertState = new UrlHistoryInsertState();
    }

    /**
     * @param array<mixed> $dataTypes
     * @param array<\Register\AdminYard\Database\LogicalExpression> $conditions
     * @param array<mixed> $data
     */
    #[\Override]
    public function updateEntity(string $tableName, array $dataTypes, array $conditions, Key $primaryKey, array $data): void
    {
        try {
            $write = function () use ($tableName, $dataTypes, $conditions, $primaryKey, $data): void {
                parent::updateEntity($tableName, $dataTypes, $conditions, $primaryKey, $data);
            };
            if ($tableName === $this->prefix . ContentSchema::TABLE_NAME && (isset($data['slug']) || isset($data['parent_id']))) {
                $row = $this->getEntity($tableName, ['id' => 'int', 'content_type' => 'string'], [], $conditions, $primaryKey);
                if ($row !== null) {
                    $this->history->changeContent(new ContentId(ContentType::from((string)$row['column_content_type']), $primaryKey->getIntId()), $write);
                    return;
                }
            }

            if ($tableName === $this->prefix . 'tags' && isset($data['url'])) {
                $this->history->changeTag($primaryKey->getIntId(), $write);
                return;
            }

            $write();
        } catch (ContentUrlCollisionException $exception) {
            throw $this->safeCollision($exception);
        }
    }

    /**
     * @param array<mixed> $dataTypes
     * @param array<mixed> $data
     */
    #[\Override]
    public function createEntity(string $tableName, array $dataTypes, array $data): void
    {
        $this->insertState->id = null;
        $isContent = $tableName === $this->prefix . ContentSchema::TABLE_NAME;
        $isTag = $tableName === $this->prefix . 'tags';
        if (!$isContent && !$isTag) {
            parent::createEntity($tableName, $dataTypes, $data);
            return;
        }

        try {
            $this->history->run(function () use ($tableName, $dataTypes, $data, $isContent, $isTag): void {
                if ($isTag) {
                    $this->tagAliases->assertAvailable((string)($data['url'] ?? ''), 0);
                }

                parent::createEntity($tableName, $dataTypes, $data);
                // MySQL clears PDO::lastInsertId() on commit; keep the AdminYard contract intact.
                $this->insertState->id = parent::lastInsertId();
                if ($isContent) {
                    $this->history->assertCreatedContentAvailable((int)$this->insertState->id);
                }
            });
        } catch (ContentUrlCollisionException $exception) {
            throw $this->safeCollision($exception);
        }
    }

    #[\Override]
    public function lastInsertId(): ?string
    {
        return $this->insertState->id ?? parent::lastInsertId();
    }

    private function safeCollision(ContentUrlCollisionException $exception): SafeDataProviderException
    {
        return new SafeDataProviderException(
            $exception->getMessage() === ContentUrlCollisionException::PATH_TOO_LONG
                ? ContentUrlCollisionException::PATH_TOO_LONG
                : 'The entity with same parameters already exists.',
            422,
            $exception,
        );
    }
}
