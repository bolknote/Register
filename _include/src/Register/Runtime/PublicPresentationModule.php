<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Register\Auth\PublicAuthRenderer;
use Register\Comment\ContentCommentRenderer;
use Register\Content\ContentRepository;
use Register\Live\LiveFragmentRenderer;
use Register\Live\LiveUpdateController;
use Register\Live\LiveUpdateRepository;
use Register\Module\Blog\Model\PostFeedRenderer;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Template\HtmlTemplateProvider;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class PublicPresentationModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(LiveFragmentRenderer::class, static fn(Container $container): LiveFragmentRenderer => new LiveFragmentRenderer(
            $container->get(HtmlTemplateProvider::class),
        ));
        $container->set(LiveUpdateController::class, static fn(Container $container): LiveUpdateController => new LiveUpdateController(
            $container->get(LiveUpdateRepository::class),
            $container->get(PostFeedRenderer::class),
            $container->get(ContentCommentRenderer::class),
            $container->get(ContentRepository::class),
            $container->get(LiveFragmentRenderer::class),
            $container->get(PublicAuthRenderer::class),
            $container->get(EventDispatcherInterface::class),
        ));
    }
}
