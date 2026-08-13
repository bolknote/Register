<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Comment\CommentRepository;
use Register\Comment\CommentSubscriptionService;
use Register\Content\ContentId;
use Register\Content\ContentType;
use S2\Cms\Helper\StringHelper;
use S2\Cms\Mail\CommentMailer;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use Register\Module\Blog\BlogUrlBuilder;
use S2\Cms\Pdo\DbLayerException;

/**
 * 1. Sends notifications on new comments:
 *    - Retrieves information about the comment and associated post.
 *    - Sends the comment to commentators who subscribed to this post.
 *    - Generates an unsubscribe link.
 *    - Marks the comment as sent.
 *
 * 2. Unsubscribes commentators by parameters from the unsubscribe links.
 */
readonly class BlogCommentNotifier
{
    public function __construct(
        private DbLayer        $dbLayer,
        private CommentRepository $commentRepository,
        private CommentSubscriptionService $subscriptionService,
        private UrlBuilder     $urlBuilder,
        private BlogUrlBuilder $blogUrlBuilder,
        private CommentMailer  $commentMailer,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function notify(int $commentId): void
    {
        $comment = $this->commentRepository->findOfType($commentId, ContentType::POST);
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

        // Getting some info about the post commented
        $result = $this->dbLayer
            ->select('title, create_time, url')
            ->from('s2_blog_posts')
            ->where('id = :id')
            ->setParameter('id', $comment->contentId->value)
            ->andWhere('published = 1')
            ->andWhere('commented = 1')
            ->execute()
        ;
        $post   = $result->fetchAssoc();
        if ($post === false) {
            return;
        }

        $link = $this->blogUrlBuilder->absPost($post['url']);

        // Fetching receivers' names and addresses
        $receivers = $this->subscriptionService->receivers($comment->contentId, $comment->email);
        $message   = StringHelper::bbcodeToMail($comment->text);

        foreach ($receivers as $receiver) {
            $unsubscribeLink = $this->urlBuilder->rawAbsLink('/comment_unsubscribe', [
                'mail=' . urlencode($receiver->email),
                'id=' . $comment->contentId->value,
                'code=' . $receiver->unsubscribeToken,
            ]);

            $this->commentMailer->mailToSubscriber($receiver->name, $receiver->email, $message, $post['title'], $link, $comment->name, $unsubscribeLink);
        }

        $this->commentRepository->setSent($commentId, ContentType::POST, true);
    }

    /**
     * @throws DbLayerException
     */
    public function unsubscribe(int $postId, string $email, string $code): bool
    {
        return $this->subscriptionService->unsubscribe(ContentId::post($postId), $email, $code);
    }
}
