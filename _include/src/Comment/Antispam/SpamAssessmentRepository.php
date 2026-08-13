<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use Register\Comment\CommentSchema;
use Register\Content\ContentType;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;
use S2\Cms\Pdo\QueryBuilder\DeleteBuilder;
use S2\Cms\Pdo\QueryBuilder\SelectBuilder;

final readonly class SpamAssessmentRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * @throws DbLayerException
     * @throws \JsonException
     */
    public function save(
        SpamAssessment $assessment,
        string         $status,
        string         $source = 'local',
        ?ContentType   $contentType = null,
        ?int           $commentId = null,
    ): int {
        $this->dbLayer
            ->insert('spam_assessments')
            ->setValue('target_type', ':target_type')->setParameter('target_type', $contentType?->value, $contentType instanceof \Register\Content\ContentType ? \PDO::PARAM_STR : \PDO::PARAM_NULL)
            ->setValue('comment_id', ':comment_id')->setParameter('comment_id', $commentId, $commentId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT)
            ->setValue('created_at', ':created_at')->setParameter('created_at', time())
            ->setValue('source', ':source')->setParameter('source', $source)
            ->setValue('score', ':score')->setParameter('score', $assessment->score)
            ->setValue('status', ':status')->setParameter('status', $status)
            ->setValue('shadow_status', 'NULL')
            ->setValue('reasons', ':reasons')->setParameter('reasons', json_encode($assessment->reasons, JSON_THROW_ON_ERROR))
            ->setValue('text_hash', ':text_hash')->setParameter('text_hash', $assessment->textHash)
            ->setValue('email_hash', ':email_hash')->setParameter('email_hash', $assessment->emailHash)
            ->setValue('ip_hash', ':ip_hash')->setParameter('ip_hash', $assessment->ipHash)
            ->setValue('moderator_label', 'NULL')
            ->setValue('model_version', ':model_version')->setParameter('model_version', SpamRiskScorer::VERSION)
            ->execute()
        ;

        return (int)$this->dbLayer->insertId();
    }

    /**
     * @throws DbLayerException
     */
    public function attachComment(int $assessmentId, ContentType $contentType, int $commentId): void
    {
        $this->dbLayer
            ->update('spam_assessments')
            ->set('target_type', ':target_type')->setParameter('target_type', $contentType->value)
            ->set('comment_id', ':comment_id')->setParameter('comment_id', $commentId)
            ->where('id = :id')->setParameter('id', $assessmentId)
            ->andWhere('target_type IS NULL')
            ->andWhere('comment_id IS NULL')
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     */
    public function setShadowStatus(int $assessmentId, string $status): void
    {
        $this->dbLayer
            ->update('spam_assessments')
            ->set('shadow_status', ':status')->setParameter('status', $status)
            ->where('id = :id')->setParameter('id', $assessmentId)
            ->execute()
        ;
    }

    /**
     * Sets the explicit moderator label on the latest assessment.
     *
     * @return string|null Previous label.
     * @throws DbLayerException
     * @throws \JsonException
     */
    public function labelComment(
        int            $commentId,
        string         $label,
        SpamAssessment $fallbackAssessment,
        ContentType    $contentType,
    ): ?string
    {
        $row = $this->dbLayer
            ->select('id', 'moderator_label')
            ->from('spam_assessments')
            ->where('target_type = :target_type')->setParameter('target_type', $contentType->value)
            ->andWhere('comment_id = :comment_id')->setParameter('comment_id', $commentId)
            ->orderBy('id DESC')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;

        if ($row === false) {
            $assessmentId = $this->save($fallbackAssessment, $label, 'moderator', $contentType, $commentId);
            $previousLabel = null;
        } else {
            $assessmentId  = (int)$row['id'];
            $previousLabel = \is_string($row['moderator_label']) && $row['moderator_label'] !== ''
                ? $row['moderator_label']
                : null;
        }

        $this->dbLayer
            ->update('spam_assessments')
            ->set('moderator_label', ':label')->setParameter('label', $label)
            ->where('id = :id')->setParameter('id', $assessmentId)
            ->execute()
        ;

        return $previousLabel;
    }

    /**
     * @throws DbLayerException
     */
    public function deleteUnattachedOlderThan(int $timestamp, ?int $limit = null): int
    {
        $delete = $this->dbLayer
            ->delete('spam_assessments')
            ->where('(target_type IS NULL OR comment_id IS NULL)')
            ->andWhere('created_at < :timestamp')->setParameter('timestamp', $timestamp)
        ;
        if ($limit === null) {
            return $delete->execute()->affectedRows();
        }

        $select = $this->dbLayer
            ->select('id')
            ->from('spam_assessments')
            ->where('(target_type IS NULL OR comment_id IS NULL)')
            ->andWhere('created_at < :timestamp')->setParameter('timestamp', $timestamp)
        ;

        return $this->deleteIdBatch($select, $delete, $limit);
    }

    /**
     * @throws DbLayerException
     */
    public function deleteUnlabelledOlderThan(int $timestamp, ?int $limit = null): int
    {
        $delete = $this->dbLayer
            ->delete('spam_assessments')
            ->where('moderator_label IS NULL')
            ->andWhere('created_at < :timestamp')->setParameter('timestamp', $timestamp)
        ;
        if ($limit === null) {
            return $delete->execute()->affectedRows();
        }

        $select = $this->dbLayer
            ->select('id')
            ->from('spam_assessments')
            ->where('moderator_label IS NULL')
            ->andWhere('created_at < :timestamp')->setParameter('timestamp', $timestamp)
        ;

        return $this->deleteIdBatch($select, $delete, $limit);
    }

    /**
     * @throws DbLayerException
     */
    public function deleteOrphans(?int $limit = null): int
    {
        $condition = 'comment_id NOT IN (SELECT id FROM ' . $this->dbLayer->getPrefix() . CommentSchema::TABLE_NAME . ')';
        $delete    = $this->dbLayer
            ->delete('spam_assessments')
            ->where('target_type IS NOT NULL')
            ->andWhere('comment_id IS NOT NULL')
            ->andWhere($condition)
        ;
        if ($limit === null) {
            return $delete->execute()->affectedRows();
        }

        $select = $this->dbLayer
            ->select('id')
            ->from('spam_assessments')
            ->where('target_type IS NOT NULL')
            ->andWhere('comment_id IS NOT NULL')
            ->andWhere($condition)
        ;

        return $this->deleteIdBatch($select, $delete, $limit);
    }

    /**
     * @throws DbLayerException
     */
    private function deleteIdBatch(SelectBuilder $select, DeleteBuilder $delete, int $limit): int
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Maintenance batch size must be positive.');
        }

        $ids = $select
            ->orderBy('id')
            ->limit($limit)
            ->execute()
            ->fetchColumn()
        ;
        if ($ids === []) {
            return 0;
        }

        $placeholders = [];
        foreach ($ids as $index => $id) {
            $parameter      = 'id_' . $index;
            $placeholders[] = ':' . $parameter;
            $delete->setParameter($parameter, (int)$id, \PDO::PARAM_INT);
        }

        return $delete
            ->andWhere('id IN (' . implode(', ', $placeholders) . ')')
            ->execute()
            ->affectedRows()
        ;
    }

}
