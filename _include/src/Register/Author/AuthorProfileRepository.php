<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Author;

use S2\Cms\Pdo\DbLayer;

/** Exposes author presentation data without leaking authentication fields to integrations. */
final readonly class AuthorProfileRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function find(int $authorId): ?AuthorProfile
    {
        if ($authorId <= 0) {
            throw new \InvalidArgumentException('An author identifier must be positive.');
        }

        return $this->findMany([$authorId])[$authorId] ?? null;
    }

    /** @return list<AuthorProfile> */
    public function publishers(): array
    {
        $rows = $this->baseQuery()
            ->where('create_articles = 1 OR edit_site = 1')
            ->orderBy('name', 'id')
            ->execute()
            ->fetchAssocAll()
        ;

        return array_values(array_map($this->hydrate(...), $rows));
    }

    /**
     * @param list<int> $authorIds
     * @return array<int, AuthorProfile>
     */
    public function findMany(array $authorIds): array
    {
        $normalizedIds = [];
        foreach ($authorIds as $authorId) {
            if ($authorId <= 0) {
                throw new \InvalidArgumentException('An author identifier must be positive.');
            }

            $normalizedIds[$authorId] = $authorId;
        }

        if ($normalizedIds === []) {
            return [];
        }

        $parameters   = [];
        $placeholders = [];
        foreach ($normalizedIds as $index => $normalizedAuthorId) {
            $parameter                 = 'author_id_' . $index;
            $parameters[$parameter]    = $normalizedAuthorId;
            $placeholders[]            = ':' . $parameter;
        }

        $rows = $this->baseQuery()
            ->where('id IN (' . implode(', ', $placeholders) . ')')
            ->execute($parameters)
            ->fetchAssocAll()
        ;

        $profiles = [];
        foreach ($rows as $row) {
            $profile                = $this->hydrate($row);
            $profiles[$profile->id] = $profile;
        }

        return $profiles;
    }

    private function baseQuery(): \S2\Cms\Pdo\QueryBuilder\SelectBuilder
    {
        return $this->dbLayer
            ->select('id, name, create_articles, edit_site')
            ->from('users')
        ;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AuthorProfile
    {
        return new AuthorProfile(
            (int)$row['id'],
            (string)$row['name'],
            (bool)$row['create_articles'] || (bool)$row['edit_site'],
        );
    }
}
