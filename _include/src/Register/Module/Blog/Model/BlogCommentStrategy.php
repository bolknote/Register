<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Comment\CommentRepository;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use S2\Cms\Controller\Comment\CommentDto;
use S2\Cms\Controller\Comment\CommentStrategyInterface;
use S2\Cms\Controller\Comment\TargetDto;
use S2\Cms\Controller\CommentController;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;
use S2\Cms\Pdo\DbLayerException;

readonly class BlogCommentStrategy implements CommentStrategyInterface
{
    #[\Override]
    public function getContentType(): ContentType
    {
        return ContentType::POST;
    }

    public function __construct(
        private DbLayer             $dbLayer,
        private CommentRepository   $commentRepository,
        private BlogCommentNotifier $commentNotifier,
    ) {
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function getTargetByRequest(Request $request): ?TargetDto
    {
        $url = $request->attributes->getString('url');

        $result = $this->dbLayer
            ->select('id', 'title')
            ->from(ContentSchema::TABLE_NAME . ' AS p')
            ->where('content_type = :content_type')
            ->setParameter('content_type', ContentType::POST->value)
            ->andWhere('slug = :url')
            ->setParameter('url', $url)
            ->andWhere('published = 1')
            ->andWhere('comments_enabled = 1')
            ->execute()
        ;

        $post = $result->fetchAssoc();

        return \is_array($post) ? new TargetDto($post['id'], $post['title']) : null;
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function getTargetById(int $targetId): ?TargetDto
    {
        $post = $this->dbLayer
            ->select('id', 'title')
            ->from(ContentSchema::TABLE_NAME . ' AS p')
            ->where('id = :id')
            ->setParameter('id', $targetId)
            ->andWhere('content_type = :content_type')
            ->setParameter('content_type', ContentType::POST->value)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($post) ? new TargetDto($post['id'], $post['title']) : null;
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function isValidParent(int $targetId, int $parentId): bool
    {
        return $this->commentRepository->isValidParent(ContentId::post($targetId), $parentId);
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function save(int $targetId, string $name, string $email, bool $showEmail, bool $subscribed, string $text, string $ip, ?int $parentId): int
    {
        return $this->commentRepository->save(
            ContentId::post($targetId),
            $name,
            $email,
            $showEmail,
            $subscribed,
            $text,
            $ip,
            $parentId,
        );
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function notifySubscribers(int $commentId): void
    {
        $this->commentNotifier->notify($commentId);
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function getHashForPublishedComment(int $targetId): ?string
    {
        $num = $this->commentRepository->count(ContentId::post($targetId));

        return $num > 0 ? (string)$num : null;
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function getRecentComment(string $hash, string $ip): ?CommentDto
    {
        foreach ($this->commentRepository->findRecentPending(ContentType::POST, $ip, time() - 5 * 60) as $comment) {
            if ($hash === CommentController::commentHash($comment->id, $comment->contentId->value, $comment->email, $ip, static::class)) {
                return new CommentDto($comment->id, $comment->contentId->value, $comment->name, $comment->email, $comment->text);
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function publishComment(int $commentId): void
    {
        $this->commentRepository->publish($commentId, ContentType::POST);
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function unsubscribe(int $targetId, string $email, string $code): bool
    {
        return $this->commentNotifier->unsubscribe($targetId, $email, $code);
    }
}
