<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Model;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Config\BoolProxy;
use Register\Core\Config\StringProxy;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\Viewer;

/**
 * Renders favorite permanent pages so page and post controllers can share one collection.
 */
final readonly class FavoriteArticleProvider
{
    public function __construct(
        private DbLayer         $dbLayer,
        private ArticleProvider $articleProvider,
        private UrlBuilder      $urlBuilder,
        private Viewer          $viewer,
        private StringProxy     $favoriteUrl,
        private BoolProxy       $useHierarchy,
    ) {
    }

    /** @return array{articles: string, sections: string} */
    public function render(): array
    {
        $rawQuery = $this->dbLayer
            ->select('1')
            ->from(ContentSchema::TABLE_NAME . ' AS a1')
            ->where('a1.parent_id = a.id')
            ->andWhere("a1.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a1.published = 1')
            ->limit(1)
            ->getSql()
        ;

        $result = $this->dbLayer
            ->select('a.title, a.slug AS url, (' . $rawQuery . ') IS NOT NULL AS children_exist')
            ->addSelect('a.id, a.excerpt, 2 AS favorite, a.published_at AS create_time, a.parent_id')
            ->from(ContentSchema::TABLE_NAME . ' AS a')
            ->where("a.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a.featured = 1')
            ->andWhere('a.published = 1')
            ->execute()
        ;

        $rows      = [];
        $urls      = [];
        $parentIds = [];
        while ($row = $result->fetchAssoc()) {
            $rows[]      = $row;
            $urls[]      = rawurlencode($row['url']);
            $parentIds[] = $row['parent_id'];
        }

        $urls                     = $this->articleProvider->getFullUrlsForArticles($parentIds, $urls);
        $articles                 = [];
        $sections                 = [];
        $articleSortingTimestamps = [];
        $sectionSortingTimestamps = [];
        $favoriteLink             = $this->urlBuilder->link('/' . rawurlencode($this->favoriteUrl->get()) . '/');
        $useHierarchy             = $this->useHierarchy->get();

        foreach ($urls as $index => $url) {
            $row  = $rows[$index];
            $item = [
                'id'            => $row['id'],
                'title'         => $row['title'],
                'link'          => $this->urlBuilder->link($url . ($useHierarchy && (bool)$row['children_exist'] ? '/' : '')),
                'favorite_link' => $favoriteLink,
                'date'          => $this->viewer->date($row['create_time']),
                'excerpt'       => $row['excerpt'],
                'favorite'      => $row['favorite'],
            ];

            if ((bool)$row['children_exist']) {
                $sections[]                 = $item;
                $sectionSortingTimestamps[] = $row['create_time'];
            } else {
                $articles[]                 = $item;
                $articleSortingTimestamps[] = $row['create_time'];
            }
        }

        array_multisort($articleSortingTimestamps, SORT_DESC, $articles);
        array_multisort($sectionSortingTimestamps, SORT_DESC, $sections);

        return [
            'articles' => implode('', array_map(
                fn(array $item): string => $this->viewer->render('subarticles_item', $item),
                $articles
            )),
            'sections' => implode('', array_map(
                fn(array $item): string => $this->viewer->render('subarticles_item', $item),
                $sections
            )),
        ];
    }

    public function renderList(): string
    {
        return $this->viewer->render('list_text', [
            'description' => '',
            ...$this->render(),
        ]);
    }
}
