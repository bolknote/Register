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

    /**
     * Adds intrinsic dimensions to registered images without rewriting the rest of the post HTML.
     */
    public function completeImageDimensions(string $body): string
    {
        /** @var array<int, array<string, mixed>|null> $mediaById */
        $mediaById = [];
        $imageCount = 0;
        $completed = preg_replace_callback(
            '/<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/iu',
            function (array $matches) use (&$mediaById, &$imageCount): string {
                $tag = $matches[0];
                if (++$imageCount > self::MAX_MEDIA_PER_POST) {
                    return $tag;
                }

                $image = $this->imageElement($tag);
                if (!$image instanceof \DOMElement) {
                    return $tag;
                }

                $width = $this->positiveImageDimension($image, 'width');
                $height = $this->positiveImageDimension($image, 'height');
                if ($width !== null && $height !== null) {
                    return $tag;
                }

                $mediaId = $image->getAttribute('data-post-media-id');
                if (preg_match('/^[1-9][0-9]*$/D', $mediaId) !== 1) {
                    return $tag;
                }

                $id = (int)$mediaId;
                if (!array_key_exists($id, $mediaById)) {
                    $mediaById[$id] = $this->find($id);
                }
                $media = $mediaById[$id];
                if (
                    $media === null
                    || (string)$media['kind'] !== 'image'
                    || $image->getAttribute('src') !== $this->url((string)$media['storage_path'])
                ) {
                    return $tag;
                }

                $mediaWidth = (int)($media['width'] ?? 0);
                $mediaHeight = (int)($media['height'] ?? 0);
                if ($mediaWidth <= 0 || $mediaHeight <= 0) {
                    return $tag;
                }

                if (preg_match('/@2x\.[a-z0-9]+$/D', (string)$media['storage_path']) === 1) {
                    $mediaWidth = max(1, (int)floor($mediaWidth / 2));
                    $mediaHeight = max(1, (int)floor($mediaHeight / 2));
                }

                if ($width === null) {
                    $width = $height === null
                        ? $mediaWidth
                        : max(1, (int)round($height * (float)$mediaWidth / (float)$mediaHeight));
                    $tag = $this->setTagAttribute($tag, 'width', $width);
                }
                if ($height === null) {
                    $height = max(1, (int)round((float)$width * (float)$mediaHeight / (float)$mediaWidth));
                    $tag = $this->setTagAttribute($tag, 'height', $height);
                }

                return $tag;
            },
            $body,
        );

        return $completed ?? $body;
    }

    private function imageElement(string $tag): ?\DOMElement
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div>' . $tag . '</div>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            return null;
        }

        $image = $document->getElementsByTagName('img')->item(0);

        return $image instanceof \DOMElement ? $image : null;
    }

    private function positiveImageDimension(\DOMElement $image, string $attribute): ?float
    {
        $value = $image->getAttribute($attribute);
        if (preg_match('/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/D', $value) !== 1) {
            return null;
        }

        $dimension = (float)$value;

        return $dimension > 0 && $dimension <= 1_000_000 ? $dimension : null;
    }

    private function setTagAttribute(string $tag, string $attribute, int $value): string
    {
        $replacement = $attribute . '="' . $value . '"';
        $offset = 4;
        $length = \strlen($tag);
        while ($offset < $length) {
            while ($offset < $length && ctype_space($tag[$offset])) {
                ++$offset;
            }
            if ($offset >= $length || $tag[$offset] === '>' || $tag[$offset] === '/') {
                break;
            }
            if (preg_match(
                '/\G([^\s"\'=<>`\/>]+)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/Au',
                $tag,
                $match,
                PREG_OFFSET_CAPTURE,
                $offset,
            ) !== 1) {
                break;
            }

            $fullAttribute = $match[0];
            $attributeName = $match[1];
            if (mb_strtolower($attributeName[0]) === $attribute) {
                return substr_replace($tag, $replacement, $fullAttribute[1], \strlen($fullAttribute[0]));
            }
            $offset = $fullAttribute[1] + \strlen($fullAttribute[0]);
        }

        $closingBracket = strrpos($tag, '>');
        if ($closingBracket === false) {
            return $tag;
        }
        $insertAt = $closingBracket;
        while ($insertAt > 0 && ctype_space($tag[$insertAt - 1])) {
            --$insertAt;
        }
        if ($insertAt > 0 && $tag[$insertAt - 1] === '/') {
            --$insertAt;
        }

        return substr($tag, 0, $insertAt) . ' ' . $replacement . substr($tag, $insertAt);
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
