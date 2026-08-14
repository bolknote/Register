<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

use Register\Content\ContentId;
use Register\Content\ContentRenderedEvent;
use Register\Content\ContentRepository;
use Register\Content\ContentType;
use Register\Module\VisitorIdentity\JsonMutationGuard;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use S2\Cms\Asset\AssetPack;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Framework\ContainerModuleInterface;
use S2\Cms\Framework\RoutingModuleInterface;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Template\TemplateAssetEvent;
use S2\Cms\Template\TemplateEvent;
use S2\Cms\Translation\ExtensibleTranslator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\Translation\TranslatorInterface;

final class Module implements ContainerModuleInterface, ContainerAwareListenerModuleInterface, RoutingModuleInterface
{
    private const string MARKER_PATTERN = '~<!-- register_reactions:(page|post):([1-9][0-9]*) -->~';

    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set('register_reactions_translator', static function (Container $container): TranslatorInterface {
            /** @var ExtensibleTranslator $translator */
            $translator = $container->get('translator');
            $translator->attachLoader('register_reactions', static fn(string $lang): array => require ($dir = __DIR__ . '/resources/lang/') . (file_exists($dir . $lang . '.php') ? $lang : 'English') . '.php');

            return $translator;
        });
        $container->set(ReactionRepository::class, static fn(Container $container): ReactionRepository => new ReactionRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(ReactionRenderer::class, static fn(Container $container): ReactionRenderer => new ReactionRenderer(
            $container->get(ReactionRepository::class),
            $container->get('register_reactions_translator'),
            $container->getStringParameter('base_path'),
        ));
        $container->set(ReactionController::class, static fn(Container $container): ReactionController => new ReactionController(
            $container->get(ReactionRepository::class),
            $container->get(ContentRepository::class),
            $container->get(VisitorIdentityManager::class),
            $container->get(JsonMutationGuard::class),
        ));
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(ContentRenderedEvent::class, static function (ContentRenderedEvent $event) use ($container): void {
            $text = $event->template->getFromPlaceholder('text');
            if (!\is_string($text)) {
                return;
            }

            if (preg_match(self::MARKER_PATTERN, $text) !== 1) {
                $reactions = $container->get(ReactionRenderer::class)->render($event->contentId);
                $event->template->putInPlaceholder('text', $text . "\n" . $reactions);
            }
        });

        $eventDispatcher->addListener(TemplateEvent::EVENT_PRE_REPLACE, static function (TemplateEvent $event) use ($container): void {
            $text    = $event->htmlTemplate->getFromPlaceholder('text');
            $matches = [];
            if (!\is_string($text) || preg_match_all(self::MARKER_PATTERN, $text, $matches, PREG_SET_ORDER) < 1) {
                return;
            }

            $contentIdsByMarker = [];
            foreach ($matches as $match) {
                $contentIdsByMarker[$match[0]] = new ContentId(ContentType::from($match[1]), (int)$match[2]);
            }

            $rendered = $container->get(ReactionRenderer::class)->renderMany(array_values($contentIdsByMarker));
            foreach ($contentIdsByMarker as $marker => $contentId) {
                $event->htmlTemplate->registerPlaceholder($marker, $rendered[(string)$contentId]);
            }
        });

        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $basePath = rtrim($container->getStringParameter('base_path'), '/');
            $event->assetPack
                ->addCss($basePath . '/_assets/register/reactions/reactions.css')
                ->addJs($basePath . '/_assets/register/reactions/reactions.js', [AssetPack::OPTION_DEFER])
            ;
        });
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->add('register_reactions', new Route(
            '/_reactions/{type}/{id}',
            ['_controller' => ReactionController::class],
            requirements: ['type' => 'page|post', 'id' => '[1-9][0-9]*'],
            methods: ['GET', 'POST'],
        ));
    }
}
