<?php
/**
 * @copyright 2024-2025  Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   s2_blog
 */

declare(strict_types = 1);

namespace s2_extensions\s2_blog\Admin;

use S2\AdminYard\Config\FieldConfig;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;
use s2_extensions\s2_blog\BlogUrlBuilder;

readonly class PathToAdminEntityConverter
{
    public function __construct(
        private DbLayer        $dbLayer,
        private BlogUrlBuilder $blogUrlBuilder,
    ) {
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>|null
     */
    public function getQueryParams(string $path): ?array
    {
        $blogUrl = $this->blogUrlBuilder->pathPrefix();
        if ($blogUrl !== '' && $path !== $blogUrl && !str_starts_with($path, $blogUrl . '/')) {
            return null;
        }

        $relativePath = trim(substr($path, \strlen($blogUrl)), '/');
        if ($relativePath === '' || str_contains($relativePath, '/')) {
            return ['entity' => 'BlogPost', 'action' => FieldConfig::ACTION_LIST];
        }

        $result = $this->dbLayer
            ->select('id')
            ->from('s2_blog_posts')
            ->where('url = :url')->setParameter('url', rawurldecode($relativePath))
            ->execute()
        ;

        $row = $result->fetchAssoc();
        if ($row !== false && $result->fetchAssoc() === false) {
            return ['entity' => 'BlogPost', 'action' => FieldConfig::ACTION_EDIT, 'id' => $row['id']];
        }

        return ['entity' => 'BlogPost', 'action' => FieldConfig::ACTION_LIST];
    }
}
