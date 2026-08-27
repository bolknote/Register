<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use Register\Content\ContentMediaSchema;
use Register\Core\Pdo\DbLayer;

/** Keeps the media registry in sync with media ids embedded by the post editor. */
final readonly class PostMediaRepository
{
    private const int MAX_MEDIA_PER_POST = 1000;

    private string $mediaUrlPrefix;

    public function __construct(
        private DbLayer $dbLayer,
        string          $mediaUrlPrefix,
    ) {
        $this->mediaUrlPrefix = rtrim($mediaUrlPrefix, '/');
    }

    /** @param array{original_name: string, normalized_name: string, storage_path: string, mime_type: string, kind: string, byte_size: int, width: int|null, height: int|null, uploaded_by: int} $media */
    public function register(array $media): int
    {
        $this->dbLayer
            ->insert(ContentMediaSchema::FILE_TABLE)
            ->values([
                'original_name'   => ':original_name',
                'normalized_name' => ':normalized_name',
                'storage_path'    => ':storage_path',
                'mime_type'       => ':mime_type',
                'kind'            => ':kind',
                'byte_size'       => ':byte_size',
                'width'           => ':width',
                'height'          => ':height',
                'uploaded_by'     => ':uploaded_by',
                'usage_count'     => '0',
                'pending'         => '1',
                'created_at'      => ':created_at',
            ])
            ->execute([
                ...$media,
                'created_at' => time(),
            ])
        ;

        return (int)$this->dbLayer->insertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $mediaId): ?array
    {
        if ($mediaId <= 0) {
            return null;
        }

        $row = $this->dbLayer
            ->select('*')
            ->from(ContentMediaSchema::FILE_TABLE)
            ->where('id = :id')->setParameter('id', $mediaId)
            ->execute()
            ->fetchAssoc()
        ;

        return $row === false ? null : $row;
    }

    public function relocate(int $mediaId, string $storagePath, string $canonicalName): void
    {
        $this->dbLayer
            ->update(ContentMediaSchema::FILE_TABLE)
            ->set('storage_path', ':storage_path')->setParameter('storage_path', $storagePath)
            ->set('original_name', ':canonical_name')->setParameter('canonical_name', $canonicalName)
            ->set('normalized_name', ':canonical_name')
            ->where('id = :id')->setParameter('id', $mediaId)
            ->execute()
        ;
    }

    /**
     * Replaces post-media relations and returns registry rows that became unused.
     *
     * @param list<int> $uploadedMediaIds
     * @return list<array<string, mixed>>
     */
    public function syncPost(int $postId, string $body, array $uploadedMediaIds, int $editorId): array
    {
        $currentIds = $this->postMediaIds($postId);
        $usedIds    = $this->mediaIdsFromBody($body);
        $validIds   = [];
        foreach ($usedIds as $mediaId => $source) {
            $media = $this->find($mediaId);
            if (
                $media === null
                || $source !== $this->url((string)$media['storage_path'])
                || ((bool)$media['pending'] && (int)$media['uploaded_by'] !== $editorId)
            ) {
                continue;
            }

            $validIds[] = $mediaId;
        }

        $this->dbLayer
            ->delete(ContentMediaSchema::USAGE_TABLE)
            ->where('post_id = :post_id')->setParameter('post_id', $postId)
            ->execute()
        ;
        foreach ($validIds as $mediaId) {
            $this->dbLayer
                ->insert(ContentMediaSchema::USAGE_TABLE)
                ->values(['post_id' => ':post_id', 'media_id' => ':media_id'])
                ->execute(['post_id' => $postId, 'media_id' => $mediaId])
            ;
            $this->dbLayer
                ->update(ContentMediaSchema::FILE_TABLE)
                ->set('pending', '0')
                ->where('id = :id')->setParameter('id', $mediaId)
                ->execute()
            ;
        }

        $affectedIds = array_values(array_unique([...$currentIds, ...$validIds, ...$uploadedMediaIds]));
        $this->refreshUsageCounts($affectedIds);

        $removedRows = $this->unusedRows(array_values(array_diff($currentIds, $validIds)));
        $unusedUploads = $this->unusedOwnedRows($uploadedMediaIds, $editorId);

        $unused = [];
        foreach ([...$removedRows, ...$unusedUploads] as $media) {
            $unused[(int)$media['id']] = $media;
        }

        return array_values($unused);
    }

    /** @return list<array<string, mixed>> */
    public function releasePost(int $postId): array
    {
        $mediaIds = $this->postMediaIds($postId);
        $this->dbLayer
            ->delete(ContentMediaSchema::USAGE_TABLE)
            ->where('post_id = :post_id')->setParameter('post_id', $postId)
            ->execute()
        ;
        $this->refreshUsageCounts($mediaIds);

        return $this->unusedRows($mediaIds);
    }

    /**
     * @param list<int> $mediaIds
     *
     * @return list<array<string, mixed>>
     */
    public function releasableUploads(array $mediaIds, int $editorId): array
    {
        return $this->unusedOwnedRows(array_values(array_unique($mediaIds)), $editorId, true);
    }

    public function deleteUnused(int $mediaId): bool
    {
        return $this->dbLayer
            ->delete(ContentMediaSchema::FILE_TABLE)
            ->where('id = :id')->setParameter('id', $mediaId)
            ->andWhere('usage_count = 0')
            ->andWhere(
                'NOT EXISTS (SELECT 1 FROM ' . ContentMediaSchema::USAGE_TABLE
                . ' WHERE ' . ContentMediaSchema::USAGE_TABLE . '.media_id = '
                . ContentMediaSchema::FILE_TABLE . '.id)',
            )
            ->execute()
            ->affectedRows() === 1
        ;
    }

    /** @return list<array<string, mixed>> */
    public function stalePendingUploads(int $createdBefore, int $limit = 100): array
    {
        if ($createdBefore <= 0 || $limit <= 0) {
            return [];
        }

        $result = $this->dbLayer
            ->select('*')
            ->from(ContentMediaSchema::FILE_TABLE)
            ->where('usage_count = 0')
            ->andWhere('pending = 1')
            ->andWhere('created_at < :created_before')->setParameter('created_before', $createdBefore)
            ->orderBy('created_at ASC, id ASC')
            ->limit(min($limit, 1000))
            ->execute()
        ;
        $rows = [];
        while (($row = $result->fetchAssoc()) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function url(string $storagePath): string
    {
        return $this->mediaUrlPrefix . $storagePath;
    }

    /** @return list<int> */
    private function postMediaIds(int $postId): array
    {
        return array_values(array_map(intval(...), $this->dbLayer
            ->select('media_id')
            ->from(ContentMediaSchema::USAGE_TABLE)
            ->where('post_id = :post_id')->setParameter('post_id', $postId)
            ->execute()
            ->fetchColumn()));
    }

    /** @return array<int, string> */
    private function mediaIdsFromBody(string $body): array
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div id="register-media-root">' . $body . '</div>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            return [];
        }

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[@data-post-media-id and @src]');
        if (!$nodes instanceof \DOMNodeList) {
            return [];
        }

        $media = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $id = $node->getAttribute('data-post-media-id');
            if (preg_match('/^[1-9][0-9]*$/D', $id) === 1) {
                $media[(int)$id] = $node->getAttribute('src');
                if (\count($media) >= self::MAX_MEDIA_PER_POST) {
                    break;
                }
            }
        }

        return $media;
    }

    /** @param list<int> $mediaIds */
    private function refreshUsageCounts(array $mediaIds): void
    {
        foreach ($mediaIds as $mediaId) {
            if ($mediaId <= 0) {
                continue;
            }

            $count = (int)$this->dbLayer
                ->select('COUNT(*)')
                ->from(ContentMediaSchema::USAGE_TABLE)
                ->where('media_id = :media_id')->setParameter('media_id', $mediaId)
                ->execute()
                ->result()
            ;
            $this->dbLayer
                ->update(ContentMediaSchema::FILE_TABLE)
                ->set('usage_count', ':usage_count')->setParameter('usage_count', $count)
                ->where('id = :id')->setParameter('id', $mediaId)
                ->execute()
            ;
        }
    }

    /**
     * @param list<int> $mediaIds
     *
     * @return list<array<string, mixed>>
     */
    private function unusedRows(array $mediaIds): array
    {
        $rows = [];
        foreach ($mediaIds as $mediaId) {
            $media = $this->find($mediaId);
            if ($media !== null && (int)$media['usage_count'] === 0) {
                $rows[] = $media;
            }
        }

        return $rows;
    }

    /**
     * @param list<int> $mediaIds
     *
     * @return list<array<string, mixed>>
     */
    private function unusedOwnedRows(array $mediaIds, int $editorId, bool $pendingOnly = false): array
    {
        return array_values(array_filter(
            $this->unusedRows($mediaIds),
            static fn(array $media): bool => (int)$media['uploaded_by'] === $editorId
                && (!$pendingOnly || (bool)$media['pending']),
        ));
    }
}
