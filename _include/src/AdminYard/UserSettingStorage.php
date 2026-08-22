<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\AdminYard;

use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Model\PermissionChecker;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;

class UserSettingStorage implements SettingStorageInterface, StatefulServiceInterface
{
    public const string TABLE_NAME = 'user_settings';

    /**
     * @var array<mixed>
     */
    private array $params = [];

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly DbLayer           $dbLayer,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function has(string $key): bool
    {
        $this->ensureParamsAreLoaded();
        $userId = $this->permissionChecker->getUserId();
        if ($userId === null) {
            throw new \RuntimeException('No authenticated user found.');
        }

        return isset($this->params[$userId][$key]);
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    #[\Override]
    public function get(string $key): array|string|int|float|bool|null
    {
        $this->ensureParamsAreLoaded();
        $userId = $this->permissionChecker->getUserId();
        if ($userId === null) {
            throw new \RuntimeException('No authenticated user found.');
        }

        return $this->params[$userId][$key] ?? null;
    }

    /**
     * @throws DbLayerException
     * @param array<mixed>|string|int|float|bool|null $data
     */
    #[\Override]
    public function set(string $key, array|string|int|float|bool|null $data): void
    {
        $this->ensureParamsAreLoaded();
        $userId = $this->permissionChecker->getUserId();
        if ($userId === null) {
            throw new \RuntimeException('No authenticated user found.');
        }

        if (($this->params[$userId][$key] ?? null) === $data) {
            return;
        }

        $this->params[$userId][$key] = $data;

        try {
            $this->dbLayer
                ->upsert(self::TABLE_NAME)
                ->setKey('user_id', ':user_id')->setParameter('user_id', $userId)
                ->setKey('name', ':name')->setParameter('name', $key)
                ->setValue('value', ':value')->setParameter('value', json_encode($data, JSON_THROW_ON_ERROR))
                ->execute()
            ;
        } catch (\JsonException $jsonException) {
            throw new \LogicException('Failed to encode user settings.', 0, $jsonException);
        }
    }

    /**
     * @throws DbLayerException
     */
    #[\Override]
    public function remove(string $key): void
    {
        $userId = $this->permissionChecker->getUserId();
        if ($userId === null) {
            throw new \RuntimeException('No authenticated user found.');
        }

        $this->dbLayer
            ->delete(self::TABLE_NAME)
            ->where('user_id = :user_id')
            ->setParameter('user_id', $userId)
            ->andWhere('name = :name')
            ->setParameter('name', $key)
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     */
    private function ensureParamsAreLoaded(): void
    {
        $userId = $this->permissionChecker->getUserId();
        if ($userId === null) {
            throw new \RuntimeException('No authenticated user found.');
        }

        if (isset($this->params[$userId])) {
            return;
        }

        $result = $this->dbLayer
            ->select('name, value')
            ->from(self::TABLE_NAME)
            ->where('user_id = :user_id')
            ->setParameter('user_id', $userId)
            ->execute()
        ;

        $this->params[$userId] = $result->fetchKeyPair();
        foreach ($this->params[$userId] as $key => $value) {
            if (!\is_string($value)) {
                throw new \UnexpectedValueException('A stored user setting must contain JSON text.');
            }

            try {
                $this->params[$userId][$key] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \LogicException('Failed to decode user settings.', 0, $e);
            }
        }
    }

    #[\Override]
    public function clearState(): void
    {
        $this->params = [];
    }
}
