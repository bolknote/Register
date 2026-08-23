<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Core\Controller\Comment\CommentDto;
use Register\Core\Controller\Comment\CommentStrategyInterface;
use Register\Core\Controller\Comment\TargetDto;
use Register\Core\Controller\CommentController;
use Register\Core\Pdo\DbLayerException;
use Symfony\Component\HttpFoundation\Request;

/** One comment workflow configured for either posts or permanent pages. */
final readonly class ContentCommentStrategy implements CommentStrategyInterface
{
    public const string PAGE_SERVICE_ID = self::class . '.page';

    public const string POST_SERVICE_ID = self::class . '.post';

    public function __construct(
        private ContentType                  $contentType,
        private CommentRepository            $commentRepository,
        private ContentCommentTargetResolver $targetResolver,
        private ContentCommentNotifier       $commentNotifier,
    ) {
    }

    #[\Override]
    public function getContentType(): ContentType
    {
        return $this->contentType;
    }

    /** @throws DbLayerException */
    #[\Override]
    public function getTargetByRequest(Request $request): ?TargetDto
    {
        return $this->targetResolver->fromRequest($this->contentType, $request);
    }

    /** @throws DbLayerException */
    #[\Override]
    public function getTargetById(int $targetId): ?TargetDto
    {
        return $this->targetResolver->fromId($this->contentId($targetId));
    }

    /** @throws DbLayerException */
    #[\Override]
    public function isValidParent(int $targetId, int $parentId): bool
    {
        return $this->commentRepository->isValidParent($this->contentId($targetId), $parentId);
    }

    /** @throws DbLayerException */
    #[\Override]
    public function save(
        int     $targetId,
        string  $name,
        string  $email,
        bool    $subscribed,
        string  $text,
        string  $ip,
        ?int    $parentId,
        ?int    $userId,
        ?string $visitorId,
    ): int {
        return $this->commentRepository->save(
            $this->contentId($targetId),
            $name,
            $email,
            $subscribed,
            $text,
            $ip,
            $parentId,
            $userId,
            visitorId: $visitorId,
        );
    }

    /** @throws DbLayerException */
    #[\Override]
    public function notifySubscribers(int $commentId): void
    {
        $this->commentNotifier->notify($commentId, $this->contentType);
    }

    /** @throws DbLayerException */
    #[\Override]
    public function getHashForPublishedComment(int $targetId): ?string
    {
        $count = $this->commentRepository->count($this->contentId($targetId));

        return $count > 0 ? (string)$count : null;
    }

    /** @throws DbLayerException */
    #[\Override]
    public function getRecentComment(string $hash, string $ip): ?CommentDto
    {
        foreach ($this->commentRepository->findRecentPending($this->contentType, $ip, time() - 5 * 60) as $comment) {
            $expectedHash = CommentController::commentHash(
                $comment->id,
                $comment->contentId->value,
                $comment->email,
                $ip,
                $this->contentType,
            );
            if (hash_equals($expectedHash, $hash)) {
                return new CommentDto(
                    $comment->id,
                    $comment->contentId->value,
                    $comment->name,
                    $comment->email,
                    $comment->text,
                );
            }
        }

        return null;
    }

    /** @throws DbLayerException */
    #[\Override]
    public function publishComment(int $commentId): void
    {
        $this->commentRepository->publish($commentId, $this->contentType);
    }

    /** @throws DbLayerException */
    #[\Override]
    public function unsubscribe(int $targetId, string $email, string $code): bool
    {
        return $this->commentNotifier->unsubscribe($this->contentId($targetId), $email, $code);
    }

    private function contentId(int $targetId): ContentId
    {
        return new ContentId($this->contentType, $targetId);
    }
}
