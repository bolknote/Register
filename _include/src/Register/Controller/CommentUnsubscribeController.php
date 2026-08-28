<?php
/**
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Controller;

use Register\Controller\Comment\CommentStrategyInterface;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Template\HtmlTemplateProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class CommentUnsubscribeController implements ControllerInterface
{
    /**
     * @var CommentStrategyInterface[]
     */
    private array $commentStrategies;

    public function __construct(
        private TranslatorInterface $translator,
        private HtmlTemplateProvider $templateProvider,
        CommentStrategyInterface    ...$commentStrategies
    ) {
        $this->commentStrategies = $commentStrategies;
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (
            $request->isMethod('POST')
            && $request->request->getString('List-Unsubscribe') !== 'One-Click'
        ) {
            return $this->privateResponse(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $id   = $request->query->get('id');
        $mail = $request->query->get('mail');
        $code = $request->query->get('code');

        $template = $this->templateProvider->getTemplate('service.php');

        if (is_numeric($id) && \is_string($mail) && \is_string($code)) {
            foreach ($this->commentStrategies as $commentStrategy) {
                if ($commentStrategy->unsubscribe((int)$id, $mail, $code)) {
                    $template
                        ->putInPlaceholder('head_title', $this->translator->trans('Unsubscribed OK'))
                        ->putInPlaceholder('title', $this->translator->trans('Unsubscribed OK'))
                        ->putInPlaceholder('text', $this->translator->trans('Unsubscribed OK info'))
                    ;

                    return $this->privateResponse($template->toHttpResponse());
                }
            }
        }

        $template
            ->putInPlaceholder('head_title', $this->translator->trans('Unsubscribed failed'))
            ->putInPlaceholder('title', $this->translator->trans('Unsubscribed failed'))
            ->putInPlaceholder('text', $this->translator->trans('Unsubscribed failed info'))
        ;

        return $this->privateResponse($template->toHttpResponse());
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
