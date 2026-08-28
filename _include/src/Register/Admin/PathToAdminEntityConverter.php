<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin;

use Register\AdminYard\Config\FieldConfig;
use Register\Model\ArticleProvider;
use Register\Core\Pdo\DbLayerException;

readonly class PathToAdminEntityConverter
{
    public function __construct(
        private ArticleProvider $articleProvider,
    ) {
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>|null
     */
    public function getQueryParams(string $path): ?array
    {
        if ($path === '/') {
            return null;
        }

        $data = $this->articleProvider->articleFromPath($path, false);
        if ($data === null) {
            return null;
        }

        return ['entity' => 'Article', 'action' => FieldConfig::ACTION_EDIT, 'id' => $data['id']];
    }
}
