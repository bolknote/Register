<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;

final class SpamTextModelRepository
{
    public const string CONFIG_KEY = 'REGISTER_ANTISPAM_TEXT_MODEL';

    private bool $loaded = false;

    private ?SpamTextModel $model = null;

    public function __construct(private readonly DbLayer $dbLayer)
    {
    }

    /** @throws DbLayerException */
    public function get(): ?SpamTextModel
    {
        if ($this->loaded) {
            return $this->model;
        }

        $value = $this->dbLayer
            ->select('value')
            ->from('config')
            ->where('name = :name')->setParameter('name', self::CONFIG_KEY)
            ->execute()
            ->result()
        ;
        $this->model = \is_string($value) && $value !== '' ? SpamTextModel::fromJson($value) : null;
        $this->loaded = true;

        return $this->model;
    }

    /** @throws DbLayerException */
    public function save(SpamTextModel $model): void
    {
        $this->dbLayer
            ->upsert('config')
            ->setKey('name', ':name')->setParameter('name', self::CONFIG_KEY)
            ->setValue('value', ':value')->setParameter('value', $model->toJson())
            ->execute()
        ;
        $this->model = $model;
        $this->loaded = true;
    }

    /** @throws DbLayerException */
    public function clear(): void
    {
        $this->dbLayer
            ->delete('config')
            ->where('name = :name')->setParameter('name', self::CONFIG_KEY)
            ->execute()
        ;
        $this->model = null;
        $this->loaded = true;
    }
}
