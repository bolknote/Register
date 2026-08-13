<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use S2\Cms\Pdo\DbLayer;

final readonly class TagRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function findBySlug(string $slug): ?Tag
    {
        $row = $this->dbLayer
            ->select('id, name, url, description')
            ->from('tags')
            ->where('url = :slug')->setParameter('slug', $slug)
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;

        return $row === false ? null : $this->hydrateTag($row);
    }

    /**
     * @param list<ContentId> $contentIds
     * @return array<string, list<Tag>> Tags indexed by the canonical page:<id>/post:<id> identity.
     */
    public function findForContent(array $contentIds): array
    {
        $result    = [];
        $idsByType = [];
        foreach ($contentIds as $contentId) {
            $key          = (string)$contentId;
            $result[$key] = [];
            $idsByType[$contentId->type->value][$contentId->value] = $contentId->value;
        }

        foreach (ContentType::cases() as $contentType) {
            $ids = array_values($idsByType[$contentType->value] ?? []);
            if ($ids === []) {
                continue;
            }

            $parameters   = ['content_type' => $contentType->value];
            $placeholders = [];
            foreach ($ids as $index => $id) {
                $parameter                 = 'content_id_' . $contentType->value . '_' . $index;
                $placeholders[]            = ':' . $parameter;
                $parameters[$parameter]    = $id;
            }

            $rows = $this->dbLayer
                ->select('ct.content_id, t.id, t.name, t.url, t.description')
                ->from(ContentTagSchema::TABLE_NAME . ' AS ct')
                ->innerJoin('tags AS t', 't.id = ct.tag_id')
                ->where('ct.content_type = :content_type')
                ->andWhere('ct.content_id IN (' . implode(', ', $placeholders) . ')')
                ->orderBy('ct.content_id', 'ct.id')
                ->execute($parameters)
                ->fetchAssocAll()
            ;

            foreach ($rows as $row) {
                $key            = (string)new ContentId($contentType, (int)$row['content_id']);
                $result[$key][] = $this->hydrateTag($row);
            }
        }

        return $result;
    }

    /**
     * Replaces all tag relations for one content item. A surrounding transaction, when needed,
     * belongs to the editorial operation that also updates the content row.
     *
     * @param list<int> $tagIds
     */
    public function replace(ContentId $contentId, array $tagIds): void
    {
        $normalizedTagIds = [];
        foreach ($tagIds as $tagId) {
            if ($tagId <= 0) {
                throw new \InvalidArgumentException('A tag identifier must be a positive integer.');
            }

            $normalizedTagIds[$tagId] = $tagId;
        }

        $this->dbLayer
            ->delete(ContentTagSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->execute()
        ;

        foreach ($normalizedTagIds as $tagId) {
            $this->dbLayer
                ->insert(ContentTagSchema::TABLE_NAME)
                ->values([
                    'content_type' => ':content_type',
                    'content_id'   => ':content_id',
                    'tag_id'       => ':tag_id',
                ])
                ->execute([
                    'content_type' => $contentId->type->value,
                    'content_id'   => $contentId->value,
                    'tag_id'       => $tagId,
                ])
            ;
        }
    }

    public function remove(ContentId $contentId): void
    {
        $this->dbLayer
            ->delete(ContentTagSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->execute()
        ;
    }

    /** @return list<ContentId> */
    public function findPublishedContentIds(int $tagId, ContentType $contentType): array
    {
        if ($tagId <= 0) {
            throw new \InvalidArgumentException('A tag identifier must be a positive integer.');
        }

        $rows  = $this->dbLayer
            ->select('ct.content_id')
            ->from(ContentTagSchema::TABLE_NAME . ' AS ct')
            ->innerJoin(ContentSchema::TABLE_NAME . ' AS c', 'c.id = ct.content_id')
            ->where('ct.content_type = :content_type')->setParameter('content_type', $contentType->value)
            ->andWhere('ct.tag_id = :tag_id')->setParameter('tag_id', $tagId)
            ->andWhere('c.published = 1')
            ->andWhere('c.content_type = :content_type')
            ->orderBy('c.published_at DESC', 'c.id DESC')
            ->execute()
            ->fetchColumn()
        ;

        return array_values(array_map(
            static fn(mixed $id): ContentId => new ContentId($contentType, (int)$id),
            $rows,
        ));
    }

    /** @return list<TagUsage> */
    public function findPublishedUsage(?ContentType $onlyType = null): array
    {
        return array_values(array_filter(
            $this->findAllUsage($onlyType),
            static fn(TagUsage $usage): bool => $usage->publishedContentCount > 0,
        ));
    }

    /** @return list<TagUsage> */
    public function findAllUsage(?ContentType $onlyType = null): array
    {
        $types       = $onlyType instanceof ContentType ? [$onlyType] : ContentType::cases();
        $countSql    = [];
        $parameters  = [];
        foreach ($types as $contentType) {
            $parameter                    = 'usage_type_' . $contentType->value;
            $parameters[$parameter]       = $contentType->value;
            $countSql[]                   = '(' . $this->dbLayer
                ->select('COUNT(*)')
                ->from(ContentTagSchema::TABLE_NAME . ' AS ct')
                ->innerJoin(ContentSchema::TABLE_NAME . ' AS c', 'c.id = ct.content_id')
                ->where('ct.content_type = :' . $parameter)
                ->andWhere('ct.tag_id = t.id')
                ->andWhere('c.published = 1')
                ->andWhere('c.content_type = :' . $parameter)
                ->getSql() . ')';
        }

        $rows = $this->dbLayer
            ->select('t.id, t.name, t.url, t.description', implode(' + ', $countSql) . ' AS content_count')
            ->from('tags AS t')
            ->execute($parameters)
            ->fetchAssocAll()
        ;

        $result = array_map(
            fn(array $row): TagUsage => new TagUsage($this->hydrateTag($row), (int)$row['content_count']),
            $rows,
        );
        usort(
            $result,
            static function (TagUsage $left, TagUsage $right): int {
                $countComparison = $right->publishedContentCount <=> $left->publishedContentCount;

                return $countComparison !== 0
                    ? $countComparison
                    : strcasecmp($left->tag->name, $right->tag->name);
            },
        );

        return $result;
    }

    /**
     * @param list<string> $words
     * @return list<Tag>
     */
    public function findPublishedMatching(array $words, ?ContentType $onlyType = null): array
    {
        $normalizedWords = [];
        foreach ($words as $word) {
            $word = mb_strtolower(trim($word));
            if ($word !== '') {
                $normalizedWords[$word] = $word;
            }
        }

        if ($normalizedWords === []) {
            return [];
        }

        $result = [];
        foreach ($this->findPublishedUsage($onlyType) as $usage) {
            $name = mb_strtolower($usage->tag->name);
            foreach ($normalizedWords as $word) {
                if (str_starts_with($name, $word) || str_contains($name, ' ' . $word)) {
                    $result[] = $usage->tag;
                    break;
                }
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function hydrateTag(array $row): Tag
    {
        return new Tag(
            (int)$row['id'],
            (string)$row['name'],
            (string)$row['url'],
            (string)$row['description'],
        );
    }
}
