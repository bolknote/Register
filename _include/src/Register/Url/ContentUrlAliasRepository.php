<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Url;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Config\BoolProxy;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;

/** Keeps historical paths unique and resolves them to the current content identity. */
final readonly class ContentUrlAliasRepository
{
    public function __construct(private DbLayer $dbLayer, private ?BoolProxy $useHierarchy = null)
    {
    }

    /**
     * Converts a request path to its storage form: decoded and without surrounding slashes.
     *
     * @throws \InvalidArgumentException
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '?') || str_contains($path, '#')) {
            throw new \InvalidArgumentException('A URL alias must contain a path only.');
        }

        $path = rawurldecode(trim($path, '/'));
        if (
            $path === ''
            || strlen($path) > 255
            || !mb_check_encoding($path, 'UTF-8')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || str_contains($path, '\\')
            || str_contains($path, '//')
            || preg_match('/[\x00-\x1f\x7f]/u', $path) === 1
            || preg_match('~(?:^|/)\.\.?(/|$)~D', $path) === 1
        ) {
            throw new \InvalidArgumentException('Invalid URL alias path.');
        }

        return $path;
    }

    public static function assertHistoryPathLength(string $path): void
    {
        if (strlen(rawurldecode(trim($path, '/'))) > 255) {
            throw new ContentUrlCollisionException(ContentUrlCollisionException::PATH_TOO_LONG);
        }
    }

    /** @throws DbLayerException */
    public function add(ContentId $contentId, string $path): void
    {
        $path = self::normalizePath($path);
        $content = $this->dbLayer
            ->select('content_type, slug_scope, slug')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $contentId->value)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->execute()
            ->fetchAssoc()
        ;
        if ($content === false) {
            throw new \InvalidArgumentException('The aliased content does not exist.');
        }

        if (
            $contentId->type === ContentType::POST
            && (string)$content['slug_scope'] === 'root'
            && (string)$content['slug'] === $path
        ) {
            $this->remove($contentId, $path);
            return;
        }

        $canonicalOwner = $this->canonicalOwner($path, $contentId->value);
        if ($canonicalOwner !== null && $canonicalOwner !== $contentId->value) {
            throw new ContentUrlCollisionException(sprintf(
                'URL alias "%s" is already a canonical URL.',
                $path,
            ));
        }

        $aliasOwner = $this->owner($path);
        if ($aliasOwner === $contentId->value) {
            return;
        }

        if ($aliasOwner !== null) {
            throw new ContentUrlCollisionException(sprintf(
                'URL alias "%s" already belongs to another content item.',
                $path,
            ));
        }

        $this->dbLayer
            ->insert(ContentUrlAliasSchema::TABLE_NAME)
            ->setValue('path', ':path')->setParameter('path', $path)
            ->setValue('content_id', ':content_id')->setParameter('content_id', $contentId->value)
            ->execute()
        ;
    }

    /** @throws DbLayerException */
    public function rememberCanonicalChange(ContentId $contentId, string $previousPath, string $currentPath): void
    {
        $encodedPreviousPath = $previousPath;
        $previousPath = self::normalizePath($previousPath);
        $currentPath  = self::normalizePath($currentPath);

        // Reverting to an earlier slug promotes that path back to canonical status.
        $this->remove($contentId, $currentPath);
        if ($previousPath !== $currentPath) {
            $this->add($contentId, $encodedPreviousPath);
        }
    }

    /** @throws DbLayerException */
    public function belongsToOtherContent(string $path, int $contentId): bool
    {
        $owner = $this->owner(self::normalizePath($path));

        return $owner !== null && $owner !== $contentId;
    }

    public function assertAvailable(string $path, int $contentId): void
    {
        self::assertHistoryPathLength($path);
        $path = self::normalizePath($path);
        $canonicalOwner = $this->canonicalOwner($path, $contentId);
        $aliasOwner = $this->owner($path);
        if (($aliasOwner !== null && $aliasOwner !== $contentId)
            || ($canonicalOwner !== null && $canonicalOwner !== $contentId)) {
            throw new ContentUrlCollisionException('The entity with same parameters already exists.');
        }
    }

    public function content(string $path): ?ContentId
    {
        $row = $this->dbLayer->select('c.id, c.content_type')->from(ContentUrlAliasSchema::TABLE_NAME . ' AS a')
            ->innerJoin(ContentSchema::TABLE_NAME . ' AS c', 'c.id = a.content_id')
            ->where('a.path = :path')->setParameter('path', self::normalizePath($path))
            ->andWhere('c.published = 1')
            ->andWhere("(c.content_type = 'page' OR c.published_at <= :now)")->setParameter('now', time())
            ->execute()->fetchAssoc();

        return $row === false ? null : new ContentId(ContentType::from((string)$row['content_type']), (int)$row['id']);
    }

    /** @throws DbLayerException */
    public function publishedPostSlug(string $path): ?string
    {
        $path = self::normalizePath($path);
        $slug = $this->dbLayer
            ->select('content.slug')
            ->from(ContentUrlAliasSchema::TABLE_NAME . ' AS alias')
            ->innerJoin(ContentSchema::TABLE_NAME . ' AS content', 'content.id = alias.content_id')
            ->where('alias.path = :path')->setParameter('path', $path)
            ->andWhere('content.content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('content.published = 1')
            ->execute()
            ->result()
        ;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /** @throws DbLayerException */
    private function remove(ContentId $contentId, string $path): void
    {
        $this->dbLayer
            ->delete(ContentUrlAliasSchema::TABLE_NAME)
            ->where('path = :path')->setParameter('path', $path)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $contentId->value)
            ->execute()
        ;
    }

    /** @throws DbLayerException */
    private function owner(string $path): ?int
    {
        $owner = $this->dbLayer
            ->select('content_id')
            ->from(ContentUrlAliasSchema::TABLE_NAME)
            ->where('path = :path')->setParameter('path', $path)
            ->execute()
            ->result()
        ;

        return $owner === false || $owner === null ? null : (int)$owner;
    }

    /** @throws DbLayerException */
    private function canonicalOwner(string $path, int $excludedId): ?int
    {
        if ($this->useHierarchy !== null && !$this->useHierarchy->get()) {
            $owner = $this->dbLayer->select('id')->from(ContentSchema::TABLE_NAME)
                ->where('slug = :slug')->setParameter('slug', $path)
                ->andWhere('id <> :id')->setParameter('id', $excludedId)->execute()->result();

            return $owner === false || $owner === null ? null : (int)$owner;
        }

        $owner = $this->dbLayer
            ->select('id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('slug_scope = :slug_scope')->setParameter('slug_scope', 'root')
            ->andWhere('slug = :slug')->setParameter('slug', $path)
            ->andWhere('id <> :id')->setParameter('id', $excludedId)
            ->execute()
            ->result()
        ;

        if ($owner !== false && $owner !== null) {
            return (int)$owner;
        }

        $parent = $this->dbLayer->select('id')->from(ContentSchema::TABLE_NAME)
            ->where("content_type = 'page'")->andWhere('parent_id IS NULL')->execute()->result();
        if ($parent === false || $parent === null) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            $parent = $this->dbLayer->select('id')->from(ContentSchema::TABLE_NAME)
                ->where("content_type = 'page'")
                ->andWhere('parent_id = :parent')->setParameter('parent', (int)$parent)
                ->andWhere('slug = :slug')->setParameter('slug', $segment)->execute()->result();
            if ($parent === false || $parent === null) {
                return null;
            }
        }

        return (int)$parent;
    }
}
