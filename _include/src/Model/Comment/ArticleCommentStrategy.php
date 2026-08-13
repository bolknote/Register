<?php
/**
 * @copyright 2007-2024 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model\Comment;

use Register\Comment\CommentRepository;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use S2\Cms\Controller\Comment\CommentDto;
use S2\Cms\Controller\Comment\CommentStrategyInterface;
use S2\Cms\Controller\Comment\TargetDto;
use S2\Cms\Controller\CommentController;
use S2\Cms\Model\ArticleProvider;
use S2\Cms\Model\CommentNotifier;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\Request;
use S2\Cms\Pdo\DbLayerException;

readonly class ArticleCommentStrategy implements CommentStrategyInterface
{
    #[\Override]
    public function getContentType(): ContentType
    {
        return ContentType::PAGE;
    }

    public function __construct(
        private DbLayer         $dbLayer,
        private CommentRepository $commentRepository,
        private ArticleProvider $articleProvider,
        private CommentNotifier $commentNotifier,
    ) {
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function getTargetByRequest(Request $request): ?TargetDto
    {
        $path = $request->getPathInfo();

        $article = $this->articleProvider->articleFromPath($path, true);

        if ($article === null || $article['commented'] === 0) {
            return null;
        }

        return new TargetDto($article['id'], $article['title']);
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function getTargetById(int $targetId): ?TargetDto
    {
        $result = $this->dbLayer
            ->select('id', 'title')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $targetId)
            ->andWhere("content_type = '" . ContentType::PAGE->value . "'")
            ->execute()
        ;

        $article = $result->fetchAssoc();

        if (!\is_array($article)) {
            return null;
        }

        return new TargetDto($article['id'], $article['title']);
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function isValidParent(int $targetId, int $parentId): bool
    {
        return $this->commentRepository->isValidParent(ContentId::page($targetId), $parentId);
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function save(int $targetId, string $name, string $email, bool $showEmail, bool $subscribed, string $text, string $ip, ?int $parentId): int
    {
        return $this->commentRepository->save(
            ContentId::page($targetId),
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
        $num = $this->commentRepository->count(ContentId::page($targetId));

        return $num > 0 ? (string)$num : null;
    }

    /**
     * {@inheritdoc}
     * @throws DbLayerException
     */
    #[\Override]
    public function getRecentComment(string $hash, string $ip): ?CommentDto
    {
        foreach ($this->commentRepository->findRecentPending(ContentType::PAGE, $ip, time() - 5 * 60) as $comment) {
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
        $this->commentRepository->publish($commentId, ContentType::PAGE);
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
