<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use Register\Comment\CommentRepository;
use Register\Comment\CommentSubscriptionService;
use Register\Content\ContentId;
use Register\Content\ContentType;
use S2\Cms\Helper\StringHelper;
use S2\Cms\Mail\CommentMailer;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

/**
 * 1. Sends notifications on new comments:
 *    - Retrieves information about the comment and associated article.
 *    - Sends the comment to commentators who subscribed to this article.
 *    - Generates an unsubscribe link.
 *    - Marks the comment as sent.
 *
 * 2. Unsubscribes commentators by parameters from the unsubscribe links.
 */
readonly class CommentNotifier
{
    public function __construct(
        private DbLayer         $dbLayer,
        private CommentRepository $commentRepository,
        private CommentSubscriptionService $subscriptionService,
        private ArticleProvider $articleProvider,
        private UrlBuilder      $urlBuilder,
        private CommentMailer   $commentMailer,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function notify(int $commentId): void
    {
        $comment = $this->commentRepository->findOfType($commentId, ContentType::PAGE);
        if (!$comment instanceof \Register\Comment\Comment) {
            return;
        }

        if ($comment->shown || $comment->sent) {
            // Comment has already been checked by the moderator
            return;
        }

        /**
         * $comment['sent'] === 0 as pre-moderation was enabled when the comment was added.
         * We have to send the comment to subscribed commentators.
         */

        // Getting some info about the article commented
        $result = $this->dbLayer
            ->select('title, parent_id, url')
            ->from('articles')
            ->where('id = :article_id')
            ->setParameter('article_id', $comment->contentId->value)
            ->andWhere('published = 1')
            ->andWhere('commented = 1')
            ->execute()
        ;
        $article = $result->fetchAssoc();
        if ($article === false) {
            return;
        }

        $path = $this->articleProvider->pathFromId($article['parent_id'], true);
        if ($path === null) {
            // Article is hidden via parent sections.
            return;
        }

        $link = $this->urlBuilder->absLink($path . '/' . rawurlencode($article['url']));

        // Fetching receivers' names and addresses
        $receivers = $this->subscriptionService->receivers($comment->contentId, $comment->email);
        $message   = StringHelper::bbcodeToMail($comment->text);

        foreach ($receivers as $receiver) {
            $unsubscribeLink = $this->urlBuilder->rawAbsLink('/comment_unsubscribe', [
                'mail=' . urlencode($receiver->email),
                'id=' . $comment->contentId->value,
                'code=' . $receiver->unsubscribeToken,
            ]);

            $this->commentMailer->mailToSubscriber($receiver->name, $receiver->email, $message, $article['title'], $link, $comment->name, $unsubscribeLink);
        }

        $this->commentRepository->setSent($commentId, ContentType::PAGE, true);
    }

    /**
     * @throws DbLayerException
     */
    public function unsubscribe(int $articleId, string $email, string $code): bool
    {
        return $this->subscriptionService->unsubscribe(ContentId::page($articleId), $email, $code);
    }
}
