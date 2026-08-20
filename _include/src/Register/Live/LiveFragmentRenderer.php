<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Live;

use S2\Cms\Template\HtmlTemplateProvider;

/** Runs live fragments through the same extension and typography pipeline as full pages. */
final readonly class LiveFragmentRenderer
{
    public function __construct(private HtmlTemplateProvider $templateProvider)
    {
    }

    public function render(string $html): string
    {
        $template = $this->templateProvider->getTemplate('live-fragment.php');
        $template
            ->putInPlaceholder('commented', false)
            ->putInPlaceholder('text', $html)
        ;

        return (string)$template->toHttpResponse()->getContent();
    }
}
