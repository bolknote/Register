<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Controller\Comment\TargetDto;
use Register\Model\ArticleProvider;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerException;
use Symfony\Component\HttpFoundation\Request;

/** Resolves comment targets while keeping type-specific URL lookup in one place. */
final readonly class ContentCommentTargetResolver
{
    public function __construct(
        private DbLayer         $dbLayer,
        private ArticleProvider $articleProvider,
    ) {
    }

    /** @throws DbLayerException */
    public function fromRequest(ContentType $contentType, Request $request): ?TargetDto
    {
        return match ($contentType) {
            ContentType::PAGE => $this->pageFromRequest($request),
            ContentType::POST => $this->postFromRequest($request),
        };
    }

    /** @throws DbLayerException */
    public function fromId(ContentId $contentId): ?TargetDto
    {
        $content = $this->dbLayer
            ->select('id', 'title')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $contentId->value)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($content)
            ? new TargetDto((int)$content['id'], (string)$content['title'])
            : null;
    }

    /** @throws DbLayerException */
    private function pageFromRequest(Request $request): ?TargetDto
    {
        $page = $this->articleProvider->articleFromPath($request->getPathInfo(), true);
        if ($page === null || (int)$page['commented'] !== 1) {
            return null;
        }

        return new TargetDto((int)$page['id'], (string)$page['title']);
    }

    /** @throws DbLayerException */
    private function postFromRequest(Request $request): ?TargetDto
    {
        $post = $this->dbLayer
            ->select('id', 'title')
            ->from(ContentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('slug = :slug')->setParameter('slug', $request->attributes->getString('url'))
            ->andWhere('published = 1')
            ->andWhere('comments_enabled = 1')
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($post)
            ? new TargetDto((int)$post['id'], (string)$post['title'])
            : null;
    }
}
