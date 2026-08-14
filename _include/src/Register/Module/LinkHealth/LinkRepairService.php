<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentRepository;
use Register\Content\ContentSchema;
use S2\Cms\Pdo\DbLayer;

final readonly class LinkRepairService
{
    public function __construct(
        private DbLayer                 $dbLayer,
        private \PDO                    $pdo,
        private ContentRepository       $contentRepository,
        private ContentChangeDispatcher $contentChangeDispatcher,
        private HtmlLinkRewriter        $rewriter,
        private LinkUrlNormalizer       $normalizer,
    ) {
    }

    public function repair(
        LinkRepairUsage $usage,
        int             $targetId,
        string          $targetUrl,
        string          $archiveUrl,
        ?int            $now = null,
    ): LinkRepairOutcome {
        $now     ??= time();
        $content = $this->contentRepository->find($usage->contentId);
        if (!$content instanceof \Register\Content\ContentItem) {
            $this->contentChangeDispatcher->dispatch($usage->contentId);
            return LinkRepairOutcome::MISSING;
        }

        $archiveParts = parse_url($archiveUrl);
        if (!\is_array($archiveParts)
            || ($archiveParts['scheme'] ?? null) !== 'https'
            || strtolower(trim($archiveParts['host'] ?? '', '[]')) !== 'web.archive.org'
            || !str_starts_with($archiveParts['path'] ?? '', '/web/')
            || isset($archiveParts['user'])
            || isset($archiveParts['pass'])
            || isset($archiveParts['port'])
            || isset($archiveParts['fragment'])
        ) {
            throw new \UnexpectedValueException('The stored archive replacement URL is invalid.');
        }

        $archive = $this->normalizer->normalize($archiveUrl, $content->path);
        if (!$archive instanceof NormalizedLink) {
            throw new \UnexpectedValueException('The stored archive replacement URL is invalid.');
        }

        if ($archive->kind !== LinkKind::ARCHIVE
            || $archive->url !== $archiveUrl
            || $archive->fragment !== ''
        ) {
            throw new \UnexpectedValueException('The stored archive replacement URL is invalid.');
        }

        $row = $this->dbLayer
            ->select('body, revision')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $usage->contentId->value)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $usage->contentId->type->value)
            ->andWhere('published = 1')
            ->execute()
            ->fetchAssoc()
        ;
        if ($row === false) {
            $this->contentChangeDispatcher->dispatch($usage->contentId);
            return LinkRepairOutcome::MISSING;
        }

        $revision = (int)$row['revision'];
        if ($revision !== $usage->expectedRevision) {
            $this->contentChangeDispatcher->dispatch($usage->contentId);
            return LinkRepairOutcome::STALE;
        }

        $oldBody = (string)$row['body'];
        $rewrite = $this->rewriter->rewrite($oldBody, $content->path, $targetUrl, $archiveUrl);
        if ($rewrite->replacementCount === 0) {
            $this->contentChangeDispatcher->dispatch($usage->contentId);
            return LinkRepairOutcome::NO_MATCH;
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $updated = $this->dbLayer->update(ContentSchema::TABLE_NAME)
                ->set('body', ':body')->setParameter('body', $rewrite->html)
                ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
                ->set('revision', 'revision + 1')
                ->where('id = :id')->setParameter('id', $usage->contentId->value)
                ->andWhere('content_type = :content_type')->setParameter('content_type', $usage->contentId->type->value)
                ->andWhere('published = 1')
                ->andWhere('revision = :revision')->setParameter('revision', $usage->expectedRevision)
                ->andWhere('body = :old_body')->setParameter('old_body', $oldBody)
                ->execute()
                ->affectedRows()
            ;
            if ($updated !== 1) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }

                $this->contentChangeDispatcher->dispatch($usage->contentId);
                return LinkRepairOutcome::STALE;
            }

            $this->dbLayer->insert(Manifest::REPAIR_TABLE)->values([
                'target_id'      => ':target_id',
                'content_id'     => ':content_id',
                'old_url'        => ':old_url',
                'new_url'        => ':new_url',
                'occurrence_count' => ':occurrence_count',
                'revision_before' => ':revision_before',
                'revision_after' => ':revision_after',
                'repaired_at'    => ':repaired_at',
            ])->execute([
                'target_id'        => $targetId,
                'content_id'       => $usage->contentId->value,
                'old_url'          => $targetUrl,
                'new_url'          => $archiveUrl,
                'occurrence_count' => $rewrite->replacementCount,
                'revision_before'  => $usage->expectedRevision,
                'revision_after'   => $usage->expectedRevision + 1,
                'repaired_at'      => $now,
            ]);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $throwable) {
            if ($ownsTransaction) {
                $this->rollBackIfActive();
            }

            throw $throwable;
        }

        $this->contentChangeDispatcher->dispatch($usage->contentId);
        return LinkRepairOutcome::REPAIRED;
    }

    private function rollBackIfActive(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
