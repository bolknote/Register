<?php
/**
 * Sitemap for blog.
 *
 * @copyright 2022-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Controller;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use S2\Cms\Config\BoolProxy;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Template\Viewer;
use Register\Module\Blog\BlogUrlBuilder;

class Sitemap extends \S2\Cms\Controller\Sitemap
{
    public function __construct(
        DbLayer                  $dbLayer,
        protected BlogUrlBuilder $blogUrlBuilder,
        ArticleProvider          $articleProvider,
        UrlBuilder               $urlBuilder,
        Viewer                   $viewer,
        BoolProxy                $useHierarchy,
    ) {
        parent::__construct($dbLayer, $articleProvider, $urlBuilder, $viewer, $useHierarchy);
    }

    /**
     * {@inheritdoc}
     * @return array<mixed>
     */
    #[\Override]
    protected function getItems(): array
    {
        // Obtaining posts
        $result = $this->dbLayer
            ->select('p.published_at AS time, p.updated_at AS modify_time, p.slug AS url')
            ->from(ContentSchema::TABLE_NAME . ' AS p')
            ->where('p.content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('p.published = 1')
            ->execute()
        ;

        $posts = [];
        while ($row = $result->fetchAssoc()) {
            $row['rel_path'] = $this->blogUrlBuilder->post($row['url']);
            $posts[]         = $row;
        }

        if ($this->blogUrlBuilder->blogIsOnTheSiteRoot()) {
            return [...parent::getItems(), ...$posts];
        }

        return $posts;
    }
}
