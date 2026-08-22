<?php /** @noinspection HtmlUnknownTarget */
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Math;

use Register\Core\Asset\AssetPack;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Template\TemplateAssetEvent;
use Register\Core\Template\TemplatePreCommentRenderEvent;
use Register\Rose\Finder;
use Register\Module\Search\Event\TextNodeExtractEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Register\Core\Translation\ExtensibleTranslator;
use Symfony\Contracts\Translation\TranslatorInterface;

class Module implements ContainerModuleInterface, ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set('register_math_translator', static function (Container $container) {
            /** @var ExtensibleTranslator $translator */
            $translator = $container->get('translator');
            $translator->attachLoader('register_math', static fn(string $lang): array => require ($dir = __DIR__ . '/lang/') . (file_exists($dir . $lang . '.php') ? $lang : 'English') . '.php');

            return $translator;
        });

        $container->decorate(Finder::class, static function (Container $container, callable $originalFactory) {
            /** @var Finder $finder */
            $finder = $originalFactory($container);
            // same as \$\$(.*?)\$\$ but with optimizations, see https://www.rexegg.com/regex-quantifiers.php#explicit_greed
            $finder->setHighlightMaskRegexArray(['#\$\$(?:[^$]++|\$(?!\$))*+\$\$#']);

            return $finder;
        });
    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $basePath = rtrim($container->getStringParameter('base_path'), '/');
            $event->assetPack
                ->addCss($basePath . '/_assets/register/math/math.css')
                ->addJs($basePath . '/_assets/register/math/loader.js', [AssetPack::OPTION_DEFER])
            ;
        });

        $eventDispatcher->addListener(TemplatePreCommentRenderEvent::class, static function (TemplatePreCommentRenderEvent $event) use ($container): void {
            /** @var TranslatorInterface $translator */
            $translator = $container->get('register_math_translator');
            array_unshift($event->syntaxHelpItems, $translator->trans('Comment latex syntax'));
        });

        // Note: Indexing is performed in the QueueConsumer, so it cannot be moved to AdminExtension right now.
        $eventDispatcher->addListener(TextNodeExtractEvent::class, self::textNodeExtractListener(...));
    }

    public static function textNodeExtractListener(TextNodeExtractEvent $event): void
    {
        /**
         * These conditions mirror isBlockFormula() in the local math loader.
         */
        $contentPieces = explode('$$', $event->textContent);

        if ($event->parentNode->nodeName !== 'p' || \count($event->parentNode->childNodes) >= 2) {
            return;
        }

        if (\count($contentPieces) === 3
            && preg_match('/^[ \t]*$/', $contentPieces[0]) === 1
            && preg_match('/^(?:[ \t]*\([ \t]*\S+[ \t]*\))?[ \t]*$/', $contentPieces[2]) === 1
        ) {
            // A block formula encountered. We do not index it and do not add to snippets.
            $event->stopPropagation();
        }
    }
}
