<?php

declare(strict_types = 1);

namespace Register\Url;

use Register\Core\Pdo\DbLayer;

final readonly class TagUrlAliasRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function assertAvailable(string $slug, int $tagId): void
    {
        if ($slug === '' || mb_strlen($slug) > 191 || preg_match('/[\x00-\x1f\x7f]/u', $slug) !== 0) {
            throw new ContentUrlCollisionException('Invalid URL part');
        }

        foreach (['tags' => ['id', 'url'], TagUrlAliasSchema::TABLE_NAME => ['tag_id', 'slug']] as $table => [$id, $column]) {
            $owner = $this->dbLayer->select($id)->from($table)
                ->where($column . ' = :slug')->setParameter('slug', $slug)->execute()->result();
            if ($owner !== false && $owner !== null && (int)$owner !== $tagId) {
                throw new ContentUrlCollisionException('The entity with same parameters already exists.');
            }
        }
    }

    public function currentSlug(string $previousSlug): ?string
    {
        $slug = $this->dbLayer->select('t.url')->from(TagUrlAliasSchema::TABLE_NAME . ' AS a')
            ->innerJoin('tags AS t', 't.id = a.tag_id')
            ->where('a.slug = :slug')->setParameter('slug', $previousSlug)->execute()->result();

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    public function rememberChange(int $tagId, string $previousSlug, string $currentSlug): void
    {
        $this->assertAvailable($currentSlug, $tagId);
        $this->dbLayer->delete(TagUrlAliasSchema::TABLE_NAME)
            ->where('tag_id = :id')->setParameter('id', $tagId)
            ->andWhere('slug = :slug')->setParameter('slug', $currentSlug)->execute();
        if ($previousSlug === '' || $previousSlug === $currentSlug) {
            return;
        }

        $this->assertAvailable($previousSlug, $tagId);
        if ($this->currentSlug($previousSlug) === null) {
            $this->dbLayer->insert(TagUrlAliasSchema::TABLE_NAME)
                ->setValue('slug', ':slug')->setParameter('slug', $previousSlug)
                ->setValue('tag_id', ':id')->setParameter('id', $tagId)->execute();
        }
    }
}
