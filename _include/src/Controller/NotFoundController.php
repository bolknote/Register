<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Controller;

use Register\Core\Framework\ControllerInterface;
use Register\Core\Model\ArticleProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\HtmlTemplateProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class NotFoundController implements ControllerInterface
{
    public function __construct(
        private ArticleProvider      $articleProvider,
        private UrlBuilder           $urlBuilder,
        private TranslatorInterface  $translator,
        private HtmlTemplateProvider $htmlTemplateProvider,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $template = $this->htmlTemplateProvider->getTemplate('error404.php');

        $template
            ->markAsNotFound()
            ->putInPlaceholder('head_title', $this->translator->trans('Error 404'))
            ->putInPlaceholder('title', '<h1 class="error404-header">' . $this->translator->trans('Error 404') . '</h1>')
            ->putInPlaceholder('text', sprintf($this->translator->trans('Error 404 text'), $this->urlBuilder->link('/')))
            ->addBreadCrumb($this->articleProvider->mainPageTitle(), $this->urlBuilder->link('/'))
        ;

        return $template->toHttpResponse();
    }
}
