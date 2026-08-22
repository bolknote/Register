<?php
/**
 * @copyright 2024-2025  Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\AdminYard\Config\FieldConfig;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;

readonly class PathToAdminEntityConverter
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>|null
     */
    public function getQueryParams(string $path): ?array
    {
        $relativePath = trim($path, '/');
        if ($relativePath === '' || str_contains($relativePath, '/')) {
            return ['entity' => 'BlogPost', 'action' => FieldConfig::ACTION_LIST];
        }

        $result = $this->dbLayer
            ->select('id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('slug = :slug')->setParameter('slug', rawurldecode($relativePath))
            ->execute()
        ;

        $row = $result->fetchAssoc();
        if ($row !== false && $result->fetchAssoc() === false) {
            return ['entity' => 'BlogPost', 'action' => FieldConfig::ACTION_EDIT, 'id' => $row['id']];
        }

        return ['entity' => 'BlogPost', 'action' => FieldConfig::ACTION_LIST];
    }
}
