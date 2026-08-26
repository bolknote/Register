<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import;

use Register\Core\Pdo\DbLayer;

/** Read/write boundary for idempotent external-import identities. */
final readonly class ExternalImportMapRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * Numeric-string external IDs become integer array keys in PHP.
     *
     * @return array<array-key, array{
     *     target_type: string,
     *     target_id: int,
     *     source_hash: string,
     *     source_data: array<string, mixed>,
     *     created_at: int,
     *     updated_at: int
     * }>
     */
    public function forScope(string $source, string $scope, string $entityType): array
    {
        $this->validateIdentity($source, $scope, $entityType, '1');
        $rows = $this->dbLayer
            ->select('external_id, target_type, target_id, source_hash, source_data, created_at, updated_at')
            ->from(ExternalImportMapSchema::TABLE_NAME)
            ->where('source = :source')->setParameter('source', $source)
            ->andWhere('external_scope = :external_scope')->setParameter('external_scope', $scope)
            ->andWhere('entity_type = :entity_type')->setParameter('entity_type', $entityType)
            ->execute()
            ->fetchAssocAll()
        ;

        $result = [];
        foreach ($rows as $row) {
            $externalId = (string)$row['external_id'];
            try {
                $sourceData = json_decode((string)$row['source_data'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \UnexpectedValueException(
                    'An external import mapping contains invalid provenance JSON.',
                    0,
                    $exception,
                );
            }

            if (!\is_array($sourceData)) {
                throw new \UnexpectedValueException('An external import mapping contains invalid provenance data.');
            }

            $result[$externalId] = [
                'target_type' => (string)$row['target_type'],
                'target_id'   => (int)$row['target_id'],
                'source_hash' => (string)$row['source_hash'],
                'source_data' => $sourceData,
                'created_at'  => (int)$row['created_at'],
                'updated_at'  => (int)$row['updated_at'],
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $sourceData */
    public function store(
        string $source,
        string $scope,
        string $entityType,
        string $externalId,
        string $targetType,
        int    $targetId,
        string $sourceHash,
        array  $sourceData,
        int    $now,
        ?int   $createdAt = null,
    ): void {
        $this->validateIdentity($source, $scope, $entityType, $externalId);
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $targetType) !== 1
            || $targetId <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
            || $now < 0
        ) {
            throw new \InvalidArgumentException('An external import mapping target is invalid.');
        }

        $this->dbLayer
            ->upsert(ExternalImportMapSchema::TABLE_NAME)
            ->setKey('source', ':source')->setParameter('source', $source)
            ->setKey('external_scope', ':external_scope')->setParameter('external_scope', $scope)
            ->setKey('entity_type', ':entity_type')->setParameter('entity_type', $entityType)
            ->setKey('external_id', ':external_id')->setParameter('external_id', $externalId)
            ->setValue('target_type', ':target_type')->setParameter('target_type', $targetType)
            ->setValue('target_id', ':target_id')->setParameter('target_id', $targetId)
            ->setValue('source_hash', ':source_hash')->setParameter('source_hash', $sourceHash)
            ->setValue('source_data', ':source_data')->setParameter(
                'source_data',
                json_encode($sourceData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            )
            ->setValue('created_at', ':created_at')->setParameter('created_at', $createdAt ?? $now)
            ->setValue('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->execute()
        ;
    }

    public function count(string $source): int
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $source) !== 1) {
            throw new \InvalidArgumentException('An external import source is invalid.');
        }

        return (int)$this->dbLayer
            ->select('COUNT(*)')
            ->from(ExternalImportMapSchema::TABLE_NAME)
            ->where('source = :source')->setParameter('source', $source)
            ->execute()
            ->result()
        ;
    }

    private function validateIdentity(string $source, string $scope, string $entityType, string $externalId): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $source) !== 1
            || $scope === ''
            || \strlen($scope) > 64
            || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $entityType) !== 1
            || $externalId === ''
            || \strlen($externalId) > 128
        ) {
            throw new \InvalidArgumentException('An external import identity is invalid.');
        }
    }
}
