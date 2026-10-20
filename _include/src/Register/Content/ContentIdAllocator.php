<?php

declare(strict_types = 1);

namespace Register\Content;

use Register\Core\Pdo\DbLayer;

final class ContentIdAllocator
{
    /** Call inside the same editorial transaction as the content insert. */
    public static function allocate(DbLayer $dbLayer): int
    {
        ContentIdentitySchema::reserveThrough($dbLayer);
        $dbLayer->insert(ContentIdentitySchema::TABLE_NAME)
            ->setValue('created_at', ':created_at')->setParameter('created_at', time())
            ->execute();

        $id = (int)$dbLayer->insertId();
        if ($id <= 0) {
            throw new \RuntimeException('Unable to allocate a content identifier.');
        }

        return $id;
    }
}
