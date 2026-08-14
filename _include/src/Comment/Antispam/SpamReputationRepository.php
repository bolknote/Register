<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

final readonly class SpamReputationRepository
{
    public const string LABEL_HAM = 'ham';

    public const string LABEL_SPAM = 'spam';

    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * @throws DbLayerException
     */
    public function get(string $keyType, string $keyHash): SpamReputation
    {
        $row = $this->dbLayer
            ->select('ham_count', 'spam_count')
            ->from('spam_reputation')
            ->where('key_type = :key_type')->setParameter('key_type', $keyType)
            ->andWhere('key_hash = :key_hash')->setParameter('key_hash', $keyHash)
            ->andWhere('expires_at >= :now')->setParameter('now', time())
            ->execute()
            ->fetchAssoc()
        ;

        if ($row === false) {
            return new SpamReputation();
        }

        return new SpamReputation((int)$row['ham_count'], (int)$row['spam_count']);
    }

    /**
     * @param array<string, list<string>> $keysByType
     * @throws DbLayerException
     */
    public function replaceLabel(array $keysByType, ?string $previousLabel, string $newLabel): void
    {
        if ($previousLabel === $newLabel) {
            return;
        }

        foreach ($keysByType as $keyType => $keyHashes) {
            foreach (array_unique($keyHashes) as $keyHash) {
                $this->replaceKeyLabel($keyType, $keyHash, $previousLabel, $newLabel);
            }
        }
    }

    /**
     * @throws DbLayerException
     */
    public function deleteExpired(int $now, ?int $limit = null): int
    {
        if ($limit === null) {
            return $this->dbLayer
                ->delete('spam_reputation')
                ->where('expires_at < :now')->setParameter('now', $now)
                ->execute()
                ->affectedRows()
            ;
        }

        if ($limit < 1) {
            throw new \InvalidArgumentException('Maintenance batch size must be positive.');
        }

        $keys = $this->dbLayer
            ->select('key_type', 'key_hash')
            ->from('spam_reputation')
            ->where('expires_at < :now')->setParameter('now', $now)
            ->orderBy('expires_at', 'key_type', 'key_hash')
            ->limit($limit)
            ->execute()
            ->fetchAssocAll()
        ;
        if ($keys === []) {
            return 0;
        }

        $delete     = $this->dbLayer
            ->delete('spam_reputation')
            ->where('expires_at < :now')->setParameter('now', $now)
        ;
        $conditions = [];
        foreach ($keys as $index => $key) {
            $keyType = $key['key_type'] ?? null;
            $keyHash = $key['key_hash'] ?? null;
            if (!\is_string($keyType) || !\is_string($keyHash)) {
                throw new \UnexpectedValueException('Invalid spam reputation maintenance key.');
            }

            $typeParameter = 'key_type_' . $index;
            $hashParameter = 'key_hash_' . $index;
            $conditions[]  = \sprintf('(key_type = :%s AND key_hash = :%s)', $typeParameter, $hashParameter);
            $delete
                ->setParameter($typeParameter, $keyType)
                ->setParameter($hashParameter, $keyHash)
            ;
        }

        return $delete
            ->andWhere('(' . implode(' OR ', $conditions) . ')')
            ->execute()
            ->affectedRows()
        ;
    }

    /**
     * @throws DbLayerException
     */
    private function replaceKeyLabel(string $keyType, string $keyHash, ?string $previousLabel, string $newLabel): void
    {
        $current   = $this->getIncludingExpired($keyType, $keyHash);
        $hamCount  = $current->hamCount;
        $spamCount = $current->spamCount;

        if ($previousLabel === self::LABEL_HAM) {
            $hamCount = max(0, $hamCount - 1);
        } elseif ($previousLabel === self::LABEL_SPAM) {
            $spamCount = max(0, $spamCount - 1);
        }

        if ($newLabel === self::LABEL_HAM) {
            ++$hamCount;
        } elseif ($newLabel === self::LABEL_SPAM) {
            ++$spamCount;
        } else {
            throw new \InvalidArgumentException(\sprintf('Unknown spam label "%s".', $newLabel));
        }

        $now = time();
        $this->dbLayer
            ->upsert('spam_reputation')
            ->setKey('key_type', ':key_type')->setParameter('key_type', $keyType)
            ->setKey('key_hash', ':key_hash')->setParameter('key_hash', $keyHash)
            ->setValue('ham_count', ':ham_count')->setParameter('ham_count', $hamCount)
            ->setValue('spam_count', ':spam_count')->setParameter('spam_count', $spamCount)
            ->setValue('last_seen', ':last_seen')->setParameter('last_seen', $now)
            ->setValue('expires_at', ':expires_at')->setParameter('expires_at', $now + $this->ttl($keyType))
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     */
    private function getIncludingExpired(string $keyType, string $keyHash): SpamReputation
    {
        $row = $this->dbLayer
            ->select('ham_count', 'spam_count')
            ->from('spam_reputation')
            ->where('key_type = :key_type')->setParameter('key_type', $keyType)
            ->andWhere('key_hash = :key_hash')->setParameter('key_hash', $keyHash)
            ->execute()
            ->fetchAssoc()
        ;

        return $row === false
            ? new SpamReputation()
            : new SpamReputation((int)$row['ham_count'], (int)$row['spam_count']);
    }

    private function ttl(string $keyType): int
    {
        return match ($keyType) {
            'ip' => 30 * 24 * 60 * 60,
            default => 180 * 24 * 60 * 60,
        };
    }
}
