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
use S2\Cms\Framework\ListenerModuleInterface;
use S2\Cms\Template\TemplateFinalReplaceEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Module implements ListenerModuleInterface
{
    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher): void
    {
        $eventDispatcher->addListener(TemplateFinalReplaceEvent::class, function (TemplateFinalReplaceEvent $event): void {
            $event->template = Typograph::processRussianText($event->template);
        });

        $eventDispatcher->addListener(FeedItemRenderEvent::class, static function (FeedItemRenderEvent $event): void {
            $event->feedItemDto->title = Typograph::processRussianText($event->feedItemDto->title, true);
            $event->feedItemDto->text  = Typograph::processRussianText($event->feedItemDto->text);
        }, -10);

        $eventDispatcher->addListener(FeedRenderEvent::class, static function (FeedRenderEvent $event): void {
            $event->feedDto->title       = Typograph::processRussianText($event->feedDto->title, true);
            $event->feedDto->description = Typograph::processRussianText($event->feedDto->description, true);
        }, -10);
    }

}
