<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Typography;

use S2\Cms\Controller\Rss\FeedItemRenderEvent;
use S2\Cms\Controller\Rss\FeedRenderEvent;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\ContainerAwareListenerModuleInterface;
use S2\Cms\Template\TemplateFinalReplaceEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class Module implements ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $process = static function (string $contents, bool $soft = false) use ($container): string {
            /** @var TranslatorInterface $translator */
            $translator = $container->get('translator');

            return Typograph::process($contents, $translator->getLocale(), $soft);
        };

        $eventDispatcher->addListener(TemplateFinalReplaceEvent::class, static function (TemplateFinalReplaceEvent $event) use ($process): void {
            $event->template = $process($event->template);
        });

        $eventDispatcher->addListener(FeedItemRenderEvent::class, static function (FeedItemRenderEvent $event) use ($process): void {
            $event->feedItemDto->title = $process($event->feedItemDto->title, true);
            $event->feedItemDto->text  = $process($event->feedItemDto->text);
        }, -10);

        $eventDispatcher->addListener(FeedRenderEvent::class, static function (FeedRenderEvent $event) use ($process): void {
            $event->feedDto->title       = $process($event->feedDto->title, true);
            $event->feedDto->description = $process($event->feedDto->description, true);
        }, -10);
    }
}
