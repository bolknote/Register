<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Controller;

use Register\Core\Framework\ControllerInterface;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\Model\PostProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RandomPostController implements ControllerInterface
{
    public function __construct(
        private PostProvider $postProvider,
        private BlogUrlBuilder $blogUrlBuilder,
    ) {
    }

    /** @suppress PhanUnusedPublicFinalMethodParameter Required by the controller contract. */
    #[\Override]
    public function handle(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return new RedirectResponse(
            $this->postProvider->randomPublishedPostUrl() ?? $this->blogUrlBuilder->main(),
            Response::HTTP_FOUND,
        );
    }
}
