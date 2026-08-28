<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Model;

use Register\Comment\CommentRepository;
use Register\Comment\CommentSchema;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Config\BoolProxy;
use Register\Core\Model\UrlBuilder;
use Register\Core\Pdo\DbLayer;
use Register\Core\Template\Viewer;
use Register\Core\Pdo\DbLayerException;

readonly class CommentProvider
{
    public function __construct(
        private DbLayer         $dbLayer,
        private CommentRepository $commentRepository,
        private ArticleProvider $articleProvider,
        private UrlBuilder      $urlBuilder,
        private Viewer          $viewer,
        private BoolProxy       $showComments
    ) {
    }

    /**
     * Fetching last comments (for template placeholders)
     *
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function lastArticleComments(): array
    {
        if (!$this->showComments->get()) {
            return [];
        }

        // Ordinal number of the comment to be selected. Used in the hash of the comment link.
        $countRawQuery = $this->dbLayer
            ->select('COUNT(*) + 1')
            ->from(CommentSchema::TABLE_NAME . ' AS c1')
            ->where('c1.shown = 1')
            ->andWhere('c1.content_type = c.content_type')
            ->andWhere('c1.content_id = c.content_id')
            ->andWhere('c1.time < c.time')
            ->getSql()
        ;

        $result = $this->dbLayer
            ->select('c.time, a.slug AS url, a.title, c.nick, a.parent_id, (' . $countRawQuery . ') AS count')
            ->from(ContentSchema::TABLE_NAME . ' AS a')
            ->innerJoin(CommentSchema::TABLE_NAME . ' AS c', 'c.content_id = a.id')
            ->where("a.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a.published = 1')
            ->andWhere('a.comments_enabled = 1')
            ->andWhere('c.content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->andWhere('c.shown = 1')
            ->orderBy('time DESC')
            ->limit(5)
            ->execute()
        ;
        $nickNames = [];
        $titles = [];
        $parentIds = [];
        $urls = [];
        $counts = [];
        while ($row = $result->fetchAssoc()) {
            $nickNames[] = $row['nick'];
            $titles[]    = $row['title'];
            $parentIds[] = $row['parent_id'];
            $urls[]      = rawurlencode($row['url']);
            $counts[]    = $row['count'];
        }

        $urls = $this->articleProvider->getFullUrlsForArticles($parentIds, $urls);

        $output = [];
        foreach ($urls as $k => $url) {
            $output[] = [
                'title'  => $titles[$k],
                'link'   => $this->urlBuilder->link($url) . '#' . $counts[$k],
                'author' => $nickNames[$k],
            ];
        }

        return $output;
    }

    /**
     * Displaying last discussions (for template placeholders).
     *
     * Last discussions are the articles with the highest number of comments that were created in the last month.
     *
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function lastDiscussions(): array
    {
        if (!$this->showComments->get()) {
            return [];
        }

        $activeArticlesRawQuery = $this->dbLayer
            ->select('c.content_id AS article_id, COUNT(c.content_id) AS comment_num, MAX(c.id) AS max_id')
            ->from(CommentSchema::TABLE_NAME . ' AS c')
            ->where('c.content_type = :content_type')
            ->andWhere('c.shown = 1')
            ->andWhere('c.time > :time')
            ->groupBy('c.content_id')
            ->orderBy('comment_num DESC')
            ->getSql()
        ;

        // NOTE: no sorting is specified, random order is used. What order should be used?
        $result = $this->dbLayer
            ->select('a.slug AS url, a.title, a.parent_id, c2.nick, c2.time')
            ->from(ContentSchema::TABLE_NAME . ' AS a, (' . $activeArticlesRawQuery . ') AS c1')
            ->innerJoin(CommentSchema::TABLE_NAME . ' AS c2', 'c2.id = c1.max_id')
            ->where('c1.article_id = a.id')
            ->andWhere("a.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a.comments_enabled = 1')
            ->andWhere('a.published = 1')
            ->limit(10)
            ->setParameter('content_type', ContentType::PAGE->value)
            ->setParameter('time', strtotime('-1 month midnight'))
            ->execute()
        ;
        $titles = [];
        $parent_ids = [];
        $urls = [];
        $nicks = [];
        $time = [];
        while ($row = $result->fetchAssoc()) {
            $titles[]     = $row['title'];
            $parent_ids[] = $row['parent_id'];
            $urls[]       = rawurlencode($row['url']);
            $nicks[]      = $row['nick'];
            $time[]       = $row['time'];
        }

        $urls = $this->articleProvider->getFullUrlsForArticles($parent_ids, $urls);

        $output = [];
        foreach ($urls as $k => $url) {
            $output[] = [
                'title' => $titles[$k],
                'link'  => $this->urlBuilder->link($url),
                'hint'  => $nicks[$k] . ' (' . $this->viewer->dateAndTime($time[$k]) . ')',
            ];
        }

        return $output;
    }

    /**
     * @throws DbLayerException
     */
    public function getPendingCommentsCount(): int
    {
        return $this->commentRepository->countPending(ContentType::PAGE);
    }
}
