<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Register\Comment\Comment;
use Register\Comment\CommentImport;
use Register\Comment\CommentImportService;
use Register\Comment\CommentRepository;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Comment\CommentHtml;
use Register\Core\Pdo\DbLayer;
use Register\Import\ExternalImportMapRepository;
use Register\Module\Reactions\ReactionAggregate;
use Register\Module\Reactions\ReactionAggregateRepository;
use Register\Module\Reactions\ReactionAggregateSchema;
use Register\Module\Reactions\ReactionAggregateTargetType;
use Register\Url\ContentUrlAliasSchema;

/** Idempotently reconciles Telegram comments and aggregate reactions with live Register data. */
final readonly class TelegramImportService
{
    private const string SOURCE = 'telegram';

    private const string COMMENT_ENTITY = 'comment';

    public function __construct(
        private DbLayer                     $dbLayer,
        private \PDO                        $pdo,
        private CommentImportService        $commentImportService,
        private CommentRepository           $commentRepository,
        private ReactionAggregateRepository $reactionRepository,
        private ExternalImportMapRepository $mapRepository,
        private TelegramManagedMediaStorage $mediaStorage,
        private string                      $baseUrl,
    ) {
    }

    /**
     * @return array{
     *     dry_run: bool,
     *     source: array<string, mixed>,
     *     archive: array<string, int>,
     *     changes: array<string, int>,
     *     excluded_roots: list<array<string, mixed>>
     * }
     */
    public function importFile(
        string $path,
        ?int $siteAuthorUserId = null,
        bool $dryRun = false,
        string $clientOriginalName = '',
    ): array
    {
        $siteHost = strtolower((string)parse_url($this->baseUrl, PHP_URL_HOST));
        if ($siteHost === '') {
            throw new \LogicException('The configured site URL has no host.');
        }

        $postIndex = $this->postIndex();
        $package = TelegramExportPackage::fromFile($path, $clientOriginalName);
        $archive = $package->discussionArchive()->extract(
            static function (string $path) use ($postIndex): ?array {
                $post = $postIndex[$path] ?? null;
                return \is_array($post) ? $post : null;
            },
            [$siteHost],
        );
        $source = (array)$archive['source'];
        $chatId = $this->positiveInt($source['chat_id'] ?? null, 'chat ID');
        $scope = (string)$chatId;
        $owner = $this->siteAuthor($siteAuthorUserId);
        $maps = $this->mapRepository->forScope(self::SOURCE, $scope, self::COMMENT_ENTITY);
        $genericMapIds = array_fill_keys(array_keys($maps), true);
        foreach ($this->legacyCommentMaps($chatId) as $messageId => $legacyMap) {
            $maps[$messageId] ??= $legacyMap;
        }

        $reactionRows = $this->telegramReactions($chatId);
        $now = time();
        $changes = [
            'comments_inserted'              => 0,
            'comments_updated'               => 0,
            'comments_unchanged'             => 0,
            'comments_media_available'       => 0,
            'comments_media_unavailable'     => 0,
            'comments_media_repaired'        => 0,
            'comments_local_edits_preserved' => 0,
            'legacy_mappings_backfilled'     => 0,
            'provenance_updated'             => 0,
            'reaction_groups_inserted'       => 0,
            'reaction_groups_updated'        => 0,
            'reaction_groups_unchanged'      => 0,
            'reaction_groups_removed'        => 0,
        ];

        $startedTransaction = false;
        $createdMediaFiles = [];
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            foreach ((array)$archive['threads'] as $thread) {
                if (!\is_array($thread)) {
                    throw new \UnexpectedValueException('A Telegram thread is malformed.');
                }

                $contentId = ContentId::post($this->positiveInt($thread['content_id'] ?? null, 'post ID'));
                $rootMessageId = $this->positiveInt($thread['root_message_id'] ?? null, 'root message ID');
                $rootCreatedAt = $this->positiveInt($thread['root_date_unixtime'] ?? null, 'root message timestamp');
                $threadComments = $thread['comments'] ?? null;
                if (!\is_array($threadComments) || !array_is_list($threadComments)) {
                    throw new \UnexpectedValueException('A Telegram thread has no valid comment list.');
                }

                $orderedComments = $this->orderComments($threadComments, $rootMessageId);

                foreach ($orderedComments as $sourceComment) {
                    $messageId = $this->positiveInt($sourceComment['message_id'] ?? null, 'comment message ID');
                    $externalId = (string)$messageId;
                    $parentMessageId = $sourceComment['parent_message_id'] ?? null;
                    $parentId = null;
                    if ($parentMessageId !== null) {
                        $parentExternalId = (string)$this->positiveInt($parentMessageId, 'parent message ID');
                        $parentMap = $maps[$parentExternalId] ?? null;
                        if (!\is_array($parentMap)) {
                            throw new \UnexpectedValueException(
                                'Telegram comment ' . $messageId . ' has an unresolved parent.',
                            );
                        }

                        $parentId = (int)$parentMap['target_id'];
                    }

                    $author = \is_array($sourceComment['author'] ?? null)
                        ? $sourceComment['author']
                        : [];
                    $isSiteAuthor = (bool)($author['is_site_author'] ?? false);
                    $userId = $isSiteAuthor ? ($owner['id'] ?? null) : null;
                    $name = $isSiteAuthor && isset($owner['name'])
                        ? $owner['name']
                        : trim((string)($author['name'] ?? ''));
                    if ($name === '') {
                        $name = 'Telegram user';
                    }

                    $name = mb_substr($name, 0, 50);
                    $createdAt = $this->positiveInt($sourceComment['date_unixtime'] ?? null, 'comment timestamp');
                    $modifiedAt = (int)($sourceComment['edited_unixtime'] ?? 0);
                    $modifiedAt = $modifiedAt > $createdAt ? $modifiedAt : null;
                    $media = \is_array($sourceComment['media'] ?? null) ? $sourceComment['media'] : [];
                    $mediaResult = $this->commentText(
                        $sourceComment,
                        $package,
                        $chatId,
                        $messageId,
                        $dryRun,
                    );
                    $text = $mediaResult['text'];
                    foreach ($mediaResult['created_files'] as $createdMediaFile) {
                        $createdMediaFiles[] = $createdMediaFile;
                    }
                    $availableMedia = 0;
                    $unavailableMedia = 0;
                    foreach ($mediaResult['state'] as $mediaState) {
                        if ($mediaState['status'] === 'available') {
                            ++$availableMedia;
                        } else {
                            ++$unavailableMedia;
                        }
                    }
                    $changes['comments_media_available'] += $availableMedia;
                    $changes['comments_media_unavailable'] += $unavailableMedia;
                    $comment = new CommentImport(
                        $contentId,
                        $name,
                        $text,
                        $parentId,
                        $createdAt,
                        $userId,
                        $modifiedAt,
                    );
                    $sourceHash = (string)($sourceComment['source_hash'] ?? '');
                    if (preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1) {
                        $sourceHash = TelegramDiscussionArchive::commentHash($sourceComment);
                    }

                    $sourceData = $this->sourceData(
                        $source,
                        $thread,
                        $sourceComment,
                        $text,
                        $mediaResult['state'],
                    );
                    $existingMap = $maps[$externalId] ?? null;

                    if (!\is_array($existingMap)) {
                        $commentId = $this->commentImportService->import($comment);
                        if (!$this->commentImportService->publish($commentId, $contentId)) {
                            throw new \RuntimeException('A newly imported Telegram comment could not be published.');
                        }

                        ++$changes['comments_inserted'];
                        $createdMapAt = $now;
                    } else {
                        $commentId = $this->mappedCommentId($existingMap, $externalId);
                        $storedComment = $this->commentRepository->find($commentId);
                        if (!$storedComment instanceof Comment) {
                            throw new \UnexpectedValueException(
                                'Telegram comment ' . $messageId . ' is mapped to a different content item.',
                            );
                        }

                        if (!$storedComment->contentId->equals($contentId)) {
                            throw new \UnexpectedValueException(
                                'Telegram comment ' . $messageId . ' is mapped to a different content item.',
                            );
                        }

                        $previousSourceData = $existingMap['source_data'] ?? [];
                        $previousModifiedAt = $this->mappedModifiedAt($previousSourceData);
                        $previousRenderedHash = $this->mappedString(
                            $previousSourceData,
                            'rendered_text_sha256',
                        );
                        $previousMediaHash = $this->mappedString(
                            $previousSourceData,
                            'media_state_sha256',
                        );
                        $currentMediaHash = $this->mediaStateHash($mediaResult['state']);
                        $storedTextHash = hash('sha256', $storedComment->text);
                        $hasLocalEdit = $previousRenderedHash !== null
                            && !hash_equals($previousRenderedHash, $storedTextHash);
                        $hasSourceChange = (string)($existingMap['source_hash'] ?? '') !== $sourceHash;
                        $hasMediaChange = $previousMediaHash !== null
                            && !hash_equals($previousMediaHash, $currentMediaHash);
                        $repairsLegacyPlaceholder = $this->hasLegacyMediaPlaceholder($storedComment->text);
                        $needsSynchronization = ($modifiedAt !== null && $modifiedAt > $previousModifiedAt)
                            || $hasSourceChange
                            || $hasMediaChange
                            || $repairsLegacyPlaceholder;

                        if ($needsSynchronization && !$hasLocalEdit) {
                            if ($this->commentImportService->synchronize($commentId, $comment)) {
                                ++$changes['comments_updated'];
                                if ($media !== [] && ($hasMediaChange || $repairsLegacyPlaceholder)) {
                                    ++$changes['comments_media_repaired'];
                                }
                            } else {
                                ++$changes['comments_unchanged'];
                            }
                        } elseif ($hasLocalEdit && $needsSynchronization) {
                            ++$changes['comments_local_edits_preserved'];
                            ++$changes['comments_unchanged'];
                            $this->discardCreatedMedia($mediaResult['created_files'], $createdMediaFiles);
                        } else {
                            ++$changes['comments_unchanged'];
                        }

                        $createdMapAt = (int)($existingMap['created_at'] ?? $now);
                    }

                    $shouldStoreMap = !isset($genericMapIds[$externalId])
                        || !\is_array($existingMap)
                        || (string)($existingMap['source_hash'] ?? '') !== $sourceHash
                        || ($existingMap['source_data'] ?? null) !== $sourceData;
                    if ($shouldStoreMap) {
                        $this->mapRepository->store(
                            self::SOURCE,
                            $scope,
                            self::COMMENT_ENTITY,
                            $externalId,
                            'comment',
                            $commentId,
                            $sourceHash,
                            $sourceData,
                            $now,
                            $createdMapAt,
                        );
                        if (\is_array($existingMap)) {
                            if (!isset($genericMapIds[$externalId])) {
                                ++$changes['legacy_mappings_backfilled'];
                            } else {
                                ++$changes['provenance_updated'];
                            }
                        }
                    }

                    $maps[$externalId] = [
                        'target_type' => 'comment',
                        'target_id'   => $commentId,
                        'source_hash' => $sourceHash,
                        'source_data' => $sourceData,
                        'created_at'  => $createdMapAt,
                        'updated_at'  => $now,
                    ];
                    $genericMapIds[$externalId] = true;

                    $commentReactions = $sourceComment['reactions'] ?? null;
                    if (!\is_array($commentReactions) || !array_is_list($commentReactions)) {
                        throw new \UnexpectedValueException('A Telegram comment has an invalid reaction list.');
                    }

                    $this->syncReactions(
                        ReactionAggregateTargetType::COMMENT,
                        $commentId,
                        $contentId,
                        $chatId,
                        $messageId,
                        'comment',
                        $commentReactions,
                        $createdAt,
                        $reactionRows,
                        $changes,
                    );
                }

                $postReactions = $thread['post_reactions'] ?? null;
                if (!\is_array($postReactions) || !array_is_list($postReactions)) {
                    throw new \UnexpectedValueException('A Telegram post has an invalid reaction list.');
                }

                $this->syncReactions(
                    ReactionAggregateTargetType::POST,
                    $contentId->value,
                    $contentId,
                    $chatId,
                    $rootMessageId,
                    'post',
                    $postReactions,
                    $rootCreatedAt,
                    $reactionRows,
                    $changes,
                );
            }

            if ($startedTransaction) {
                if ($dryRun) {
                    $this->pdo->rollBack();
                    $this->removeMediaFiles($createdMediaFiles);
                } else {
                    $this->pdo->commit();
                }
            } elseif ($dryRun) {
                throw new \LogicException('A dry-run import cannot join an existing transaction.');
            }
        } catch (\Throwable $throwable) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->removeMediaFiles($createdMediaFiles);

            throw $throwable;
        }

        return [
            'dry_run'       => $dryRun,
            'source'        => $source,
            'archive'       => (array)$archive['stats'],
            'changes'       => $changes,
            'excluded_roots' => array_values((array)$archive['excluded_roots']),
        ];
    }

    /** @return array<string, array{content_id: int, canonical_path: string}> */
    private function postIndex(): array
    {
        $posts = [];
        $canonicalPaths = [];
        $rows = $this->dbLayer
            ->select('id, slug')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published = 1')
            ->execute()
            ->fetchAssocAll()
        ;
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $path = trim(rawurldecode((string)$row['slug']), '/');
            if ($id <= 0 || $path === '' || isset($posts[$path])) {
                throw new \UnexpectedValueException('Published post paths are missing or duplicated.');
            }

            $posts[$path] = ['content_id' => $id, 'canonical_path' => $path];
            $canonicalPaths[$id] = $path;
        }

        $aliases = $this->dbLayer
            ->select('path, content_id')
            ->from(ContentUrlAliasSchema::TABLE_NAME)
            ->execute()
            ->fetchAssocAll()
        ;
        foreach ($aliases as $alias) {
            $id = (int)$alias['content_id'];
            $canonicalPath = $canonicalPaths[$id] ?? null;
            $path = trim(rawurldecode((string)$alias['path']), '/');
            if ($canonicalPath !== null && $path !== '') {
                $posts[$path] = ['content_id' => $id, 'canonical_path' => $canonicalPath];
            }
        }

        return $posts;
    }

    /** @return array{id: int, name: string}|array{} */
    private function siteAuthor(?int $requestedUserId): array
    {
        $query = $this->dbLayer
            ->select('id, name, login')
            ->from('users')
        ;
        if ($requestedUserId !== null) {
            $query->where('id = :id')->setParameter('id', $requestedUserId);
        } else {
            $query->where('edit_site = 1')->orderBy('id')->limit(1);
        }

        $row = $query->execute()->fetchAssoc();
        if ($row === false) {
            if ($requestedUserId !== null) {
                throw new \InvalidArgumentException('The selected site-author user does not exist.');
            }

            return [];
        }

        return [
            'id'   => (int)$row['id'],
            'name' => trim((string)$row['name']) !== ''
                ? trim((string)$row['name'])
                : (string)$row['login'],
        ];
    }

    /**
     * Reads the one-off E2 migration ledger so the first recurring import does not duplicate
     * historical Telegram comments. The compatibility path is intentionally read-only.
     *
     * @return array<int, array<string, mixed>>
     */
    private function legacyCommentMaps(int $chatId): array
    {
        if (!$this->dbLayer->tableExists('e2_import_map') || $chatId >= 9_000_000_000) {
            return [];
        }

        $namespaceStart = $chatId * 1_000_000_000;
        $rows = $this->dbLayer
            ->select('source_id, target_id, source_data')
            ->from('e2_import_map')
            ->where('entity_type = :entity_type')->setParameter('entity_type', 'comment_telegram')
            ->execute()
            ->fetchAssocAll()
        ;

        $result = [];
        foreach ($rows as $row) {
            $sourceId = (int)$row['source_id'];
            if ($sourceId <= $namespaceStart || $sourceId >= $namespaceStart + 1_000_000_000) {
                continue;
            }

            $messageId = $sourceId - $namespaceStart;
            if ($messageId <= 0 || $messageId >= 1_000_000_000) {
                continue;
            }

            try {
                $sourceData = json_decode((string)$row['source_data'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $sourceData = [];
            }

            if (!\is_array($sourceData)) {
                $sourceData = [];
            }

            $legacyComment = \is_array($sourceData['TelegramComment'] ?? null)
                ? $sourceData['TelegramComment']
                : [];
            $sourceHash = $legacyComment === []
                ? hash('sha256', (string)$row['source_data'])
                : TelegramDiscussionArchive::commentHash($legacyComment);
            $createdAt = (int)($legacyComment['date_unixtime'] ?? time());
            $result[$messageId] = [
                'target_type' => 'comment',
                'target_id'   => (int)$row['target_id'],
                'source_hash' => $sourceHash,
                'source_data' => $sourceData,
                'created_at'  => max(0, $createdAt),
                'updated_at'  => max(0, (int)($legacyComment['edited_unixtime'] ?? $createdAt)),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $sourceComment
     * @return array{
     *     text: string,
     *     state: list<array{status: 'available'|'unavailable', kind: string, source_sha256: string}>,
     *     created_files: list<string>
     * }
     */
    private function commentText(
        array                 $sourceComment,
        TelegramExportPackage $package,
        int                   $chatId,
        int                   $messageId,
        bool                  $dryRun,
    ): array {
        $html = (string)($sourceComment['html'] ?? '');
        $state = [];
        $createdFiles = [];
        $unavailableKinds = [];
        foreach ((array)($sourceComment['media'] ?? []) as $position => $media) {
            if (!\is_array($media)) {
                continue;
            }

            $relativePath = trim((string)($media['path'] ?? ''));
            $sourceSha256 = hash('sha256', $relativePath);
            $storedMedia = $this->mediaStorage->import(
                $package,
                $relativePath,
                $chatId,
                $messageId,
                (int)$position + 1,
                $dryRun,
            );
            if ($storedMedia === null) {
                $kind = $this->missingMediaKind($media);
                $unavailableKinds[] = $kind;
                $state[] = [
                    'status'        => 'unavailable',
                    'kind'          => $kind,
                    'source_sha256' => $sourceSha256,
                ];
                continue;
            }

            $html .= $this->mediaHtml($storedMedia, $media);
            $state[] = [
                'status'        => 'available',
                'kind'          => $storedMedia['kind'],
                'source_sha256' => $sourceSha256 . ':' . $storedMedia['sha256'],
            ];
            if ($storedMedia['created_file'] !== null) {
                $createdFiles[] = $storedMedia['created_file'];
            }
        }

        if ($unavailableKinds !== []) {
            $kind = \count($unavailableKinds) === 1 ? $unavailableKinds[0] : 'attachment';
            $html .= '<span class="comment-media-missing" data-kind="' . $kind
                . '" data-count="' . \count($unavailableKinds)
                . '">Telegram attachment unavailable</span>';
        }

        $stored = CommentHtml::sanitizeImportedForStorage($html);
        if ($stored === '') {
            throw new \UnexpectedValueException(
                'Telegram comment ' . (int)($sourceComment['message_id'] ?? 0) . ' is empty.',
            );
        }

        return [
            'text'          => $stored,
            'state'         => $state,
            'created_files' => $createdFiles,
        ];
    }

    /**
     * @param array{url: string, kind: string, mime_type: string, sha256: string, created_file: ?string} $storedMedia
     * @param array<string, mixed> $sourceMedia
     */
    private function mediaHtml(array $storedMedia, array $sourceMedia): string
    {
        $url = htmlspecialchars($storedMedia['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return match ($storedMedia['kind']) {
            'image' => '<figure class="comment-media"><img src="' . $url
                . '" alt="" loading="lazy" decoding="async"></figure>',
            'video' => '<figure class="comment-media"><video src="' . $url
                . '" controls preload="metadata"></video></figure>',
            'audio' => '<span class="comment-media"><audio src="' . $url
                . '" controls preload="metadata"></audio></span>',
            default => '<a class="comment-media-file" href="' . $url . '">'
                . htmlspecialchars(
                    $this->mediaFileName($sourceMedia),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8',
                )
                . '</a>',
        };
    }

    /** @param array<string, mixed> $media */
    private function missingMediaKind(array $media): string
    {
        if (($media['kind'] ?? null) === 'photo') {
            return 'photo';
        }

        $mimeType = mb_strtolower(trim((string)($media['mime_type'] ?? '')));
        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        return 'attachment';
    }

    /** @param array<string, mixed> $media */
    private function mediaFileName(array $media): string
    {
        $name = basename(trim((string)($media['file_name'] ?? '')));

        return $name !== '' && $name !== '.' ? $name : 'Telegram attachment';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $thread
     * @param array<string, mixed> $comment
     * @param list<array{status: 'available'|'unavailable', kind: string, source_sha256: string}> $mediaState
     * @return array<string, mixed>
     */
    private function sourceData(
        array $source,
        array $thread,
        array $comment,
        string $renderedText,
        array $mediaState,
    ): array
    {
        return [
            'telegram' => [
                'chat_id'         => (int)$source['chat_id'],
                'chat_name'       => (string)($source['chat_name'] ?? ''),
                'root_message_id' => (int)$thread['root_message_id'],
                'message_id'      => (int)$comment['message_id'],
                'post_url'        => (string)($thread['post_url'] ?? ''),
                'comment'         => $comment,
                'rendered_text_sha256' => hash('sha256', $renderedText),
                'media_state_sha256' => $this->mediaStateHash($mediaState),
            ],
        ];
    }

    /** @param array<string, mixed> $mapping */
    private function mappedCommentId(array $mapping, string $externalId): int
    {
        $targetId = (int)($mapping['target_id'] ?? 0);
        if (($mapping['target_type'] ?? null) !== 'comment' || $targetId <= 0) {
            throw new \UnexpectedValueException('Telegram message ' . $externalId . ' has an invalid mapping.');
        }

        return $targetId;
    }

    private function mappedModifiedAt(mixed $sourceData): int
    {
        if (!\is_array($sourceData)) {
            return 0;
        }

        $comment = $sourceData['telegram']['comment']
            ?? $sourceData['TelegramComment']
            ?? null;
        return \is_array($comment) ? (int)($comment['edited_unixtime'] ?? 0) : 0;
    }

    private function mappedString(mixed $sourceData, string $key): ?string
    {
        if (!\is_array($sourceData) || !\is_array($sourceData['telegram'] ?? null)) {
            return null;
        }

        $value = $sourceData['telegram'][$key] ?? null;

        return \is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1
            ? $value
            : null;
    }

    /** @param list<array{status: 'available'|'unavailable', kind: string, source_sha256: string}> $state */
    private function mediaStateHash(array $state): string
    {
        return hash('sha256', json_encode(
            $state,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function hasLegacyMediaPlaceholder(string $storedText): bool
    {
        return str_contains($storedText, 'Telegram attachment is not contained in the JSON:');
    }

    /**
     * @param list<string> $files
     * @param list<string> $allCreatedFiles
     */
    private function discardCreatedMedia(array $files, array &$allCreatedFiles): void
    {
        $this->removeMediaFiles($files);
        if ($files === []) {
            return;
        }

        $discarded = array_fill_keys($files, true);
        $allCreatedFiles = array_values(array_filter(
            $allCreatedFiles,
            static fn(string $file): bool => !isset($discarded[$file]),
        ));
    }

    /** @param list<string> $files */
    private function removeMediaFiles(array $files): void
    {
        foreach (array_unique($files) as $file) {
            if (is_file($file) && !is_link($file)) {
                register_call_without_warnings(static fn(): bool => unlink($file));
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    private function orderComments(array $comments, int $rootMessageId): array
    {
        $pending = [];
        foreach ($comments as $comment) {
            $id = $this->positiveInt($comment['message_id'] ?? null, 'comment message ID');
            if (isset($pending[$id])) {
                throw new \UnexpectedValueException('A Telegram thread contains duplicate comments.');
            }

            $pending[$id] = $comment;
        }

        ksort($pending);

        $ordered = [];
        $resolved = [$rootMessageId => true];
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $id => $comment) {
                $parent = $comment['parent_message_id'] ?? null;
                if ($parent !== null && !isset($resolved[(int)$parent])) {
                    continue;
                }

                $ordered[] = $comment;
                $resolved[$id] = true;
                unset($pending[$id]);
                $progress = true;
            }

            if (!$progress) {
                throw new \UnexpectedValueException('A Telegram comment tree has missing or cyclic parents.');
            }
        }

        return $ordered;
    }

    /** @return array<string, array<string, mixed>> */
    private function telegramReactions(int $chatId): array
    {
        $rows = $this->dbLayer
            ->select('target_type, target_id, source_key, reaction, emoji, reaction_count, created_at, source_data')
            ->from(ReactionAggregateSchema::TABLE_NAME)
            ->where('source = :source')->setParameter('source', self::SOURCE)
            ->andWhere('source_key LIKE :prefix')->setParameter('prefix', $chatId . ':%')
            ->execute()
            ->fetchAssocAll()
        ;
        $result = [];
        foreach ($rows as $row) {
            $key = (string)$row['target_type'] . ':' . (int)$row['target_id'] . ':' . (string)$row['source_key'];
            try {
                $sourceData = json_decode((string)$row['source_data'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $sourceData = [];
            }

            $row['source_data'] = \is_array($sourceData) ? $sourceData : [];
            $result[$key] = $row;
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $reactions
     * @param array<string, array<string, mixed>> $storedRows
     * @param array<string, int> $changes
     */
    private function syncReactions(
        ReactionAggregateTargetType $targetType,
        int                         $targetId,
        ContentId                   $contentId,
        int                         $chatId,
        int                         $messageId,
        string                      $kind,
        array                       $reactions,
        int                         $createdAt,
        array                       &$storedRows,
        array                       &$changes,
    ): void {
        $prefix = $chatId . ':' . $messageId . ':' . $kind . ':';
        $incomingKeys = [];
        foreach ($reactions as $index => $reaction) {
            $count = $this->positiveInt($reaction['count'] ?? null, 'reaction count');
            $emoji = (string)($reaction['emoji'] ?? '');
            if ($emoji === '') {
                $emoji = '✦';
            }

            $sourceKey = $prefix . $index;
            $rowKey = $targetType->value . ':' . $targetId . ':' . $sourceKey;
            $incomingKeys[$rowKey] = true;
            $aggregate = new ReactionAggregate(
                $targetType,
                $targetId,
                self::SOURCE,
                $sourceKey,
                $this->canonicalReaction($emoji),
                $emoji,
                $count,
                $createdAt,
                $reaction,
            );
            $stored = $storedRows[$rowKey] ?? null;
            $matches = \is_array($stored)
                && (string)$stored['reaction'] === $aggregate->reaction
                && (string)$stored['emoji'] === $aggregate->emoji
                && (int)$stored['reaction_count'] === $aggregate->count
                && (int)$stored['created_at'] === $aggregate->createdAt
                && ($stored['source_data'] ?? []) === $aggregate->sourceData;
            if ($matches) {
                ++$changes['reaction_groups_unchanged'];
                continue;
            }

            $this->reactionRepository->store($aggregate, $contentId, deferUntilCommit: true);
            \is_array($stored)
                ? ++$changes['reaction_groups_updated']
                : ++$changes['reaction_groups_inserted'];
            $storedRows[$rowKey] = [
                'target_type'    => $targetType->value,
                'target_id'      => $targetId,
                'source_key'     => $sourceKey,
                'reaction'       => $aggregate->reaction,
                'emoji'          => $aggregate->emoji,
                'reaction_count' => $aggregate->count,
                'created_at'     => $aggregate->createdAt,
                'source_data'    => $aggregate->sourceData,
            ];
        }

        foreach ($storedRows as $rowKey => $stored) {
            if ((string)($stored['target_type'] ?? '') !== $targetType->value
                || (int)($stored['target_id'] ?? 0) !== $targetId
                || !str_starts_with((string)($stored['source_key'] ?? ''), $prefix)
                || isset($incomingKeys[$rowKey])
            ) {
                continue;
            }

            if ($this->reactionRepository->remove(
                $targetType,
                $targetId,
                self::SOURCE,
                (string)$stored['source_key'],
                $contentId,
                deferUntilCommit: true,
            )) {
                ++$changes['reaction_groups_removed'];
            }

            unset($storedRows[$rowKey]);
        }
    }

    private function canonicalReaction(string $emoji): string
    {
        return match ($emoji) {
            '👍' => 'like',
            '❤', '❤️' => 'love',
            '😁', '😂', '🤣' => 'haha',
            '😮', '😱' => 'wow',
            '😢', '😭' => 'sad',
            '😡', '🤬' => 'angry',
            default => '',
        };
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new \UnexpectedValueException('Invalid Telegram ' . $label . '.');
        }

        return $value;
    }
}
