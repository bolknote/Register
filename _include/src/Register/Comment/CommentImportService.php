<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Content\ContentId;

/** Public integration boundary for comments whose provenance lives in an optional module. */
final readonly class CommentImportService
{
    public function __construct(private CommentRepository $commentRepository)
    {
    }

    public function import(CommentImport $comment): int
    {
        return $this->commentRepository->save(
            $comment->contentId,
            $comment->name,
            '',
            false,
            $comment->text,
            '',
            $comment->parentId,
            $comment->userId,
            $comment->createdAt,
            CommentMutationSource::IMPORTED,
            null,
            $comment->modifiedAt,
        );
    }

    public function synchronize(int $commentId, CommentImport $comment): bool
    {
        return $this->commentRepository->synchronizeImported($commentId, $comment);
    }

    public function update(int $commentId, ContentId $contentId, string $text): bool
    {
        $this->validateMutation($commentId, $contentId, $text);

        return $this->commentRepository->edit(
            $commentId,
            $contentId->type,
            $text,
            CommentMutationSource::IMPORTED,
        );
    }

    public function publish(int $commentId, ContentId $contentId): bool
    {
        $comment = $this->matchingComment($commentId, $contentId);
        if (!$comment instanceof Comment) {
            return false;
        }

        if ($comment->deleted || $comment->shown) {
            return false;
        }

        $this->commentRepository->publish($commentId, $contentId->type, CommentMutationSource::IMPORTED);

        $published = $this->matchingComment($commentId, $contentId);

        if (!$published instanceof Comment) {
            return false;
        }

        return $published->shown;
    }

    public function tombstone(int $commentId, ContentId $contentId): bool
    {
        if (!$this->matchingComment($commentId, $contentId) instanceof Comment) {
            return false;
        }

        return $this->commentRepository->tombstone(
            $commentId,
            $contentId->type,
            CommentMutationSource::IMPORTED,
        );
    }

    private function validateMutation(int $commentId, ContentId $contentId, string $text): void
    {
        if ($commentId < 1 || $text === '' || \strlen($text) > 65_535) {
            throw new \InvalidArgumentException('An imported comment mutation is invalid.');
        }

        if (!$this->matchingComment($commentId, $contentId) instanceof Comment) {
            throw new \DomainException('The imported comment does not belong to the requested content item.');
        }
    }

    private function matchingComment(int $commentId, ContentId $contentId): ?Comment
    {
        $comment = $commentId > 0 ? $this->commentRepository->find($commentId) : null;

        if (!$comment instanceof Comment) {
            return null;
        }

        return $comment->contentId->equals($contentId) ? $comment : null;
    }
}
