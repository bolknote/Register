<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Controller\Comment\CommentStrategyInterface;
use Register\Core\Config\BoolProxy;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Selects an addressee without submitting a comment or creating crawlable reply URLs. */
final readonly class CommentReplyForm
{
    public function __construct(
        private CommentRepository $comments,
        private HtmlTemplateProvider $templates,
        private TranslatorInterface $translator,
        private BoolProxy $commentsEnabled,
        private UrlBuilder $urlBuilder,
    ) {
    }

    public function response(Request $request, CommentStrategyInterface $strategy): Response
    {
        $parentId = filter_var($request->request->getString('comment_reply'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if (!\is_int($parentId)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $parent = $this->comments->find($parentId);
        if ($parent === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if ($parent->contentId->type !== $strategy->getContentType()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $target = $strategy->getTargetByRequest($request);
        if ($target === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if ($target->id !== $parent->contentId->value || !$strategy->isValidParent($target->id, $parentId)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->commentsEnabled->get()) {
            return new Response($this->translator->trans('disabled'), Response::HTTP_FORBIDDEN);
        }

        if (!$target->commentsAllowed) {
            return new Response($this->translator->trans('Comments closed by age'), Response::HTTP_FORBIDDEN);
        }

        $number = filter_var($request->request->getString('reply_number'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        $template = $this->templates->getTemplate('service.php');
        $template
            ->putInPlaceholder('head_title', $this->translator->trans('Reply'))
            ->putInPlaceholder('title', $this->translator->trans('Reply'))
            ->putInPlaceholder('text', '')
            ->putInPlaceholder('id', '')
            ->putInPlaceholder('commented', true)
            ->putInPlaceholder('comment_form', [
                'parent_id' => $parentId,
                'reply_number' => \is_int($number) ? $number : 0,
                'reply_name' => $parent->name,
                'reply_target_url' => $this->urlBuilder->link($request->getPathInfo()) . '#'
                    . (\is_int($number) && $number > 0 ? $number : 'comments-title'),
            ]);

        $response = $template->toHttpResponse();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
