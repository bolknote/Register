<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\AudioPlayer;

use Register\Core\Asset\AssetPack;
use Register\Core\Asset\PublicAssetUrl;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerAwareListenerModuleInterface;
use Register\Core\Template\TemplateAssetEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class Module implements ContainerAwareListenerModuleInterface
{
    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $assetUrl = new PublicAssetUrl(
                $container->getStringParameter('public_root_dir'),
                $container->getStringParameter('base_path'),
            );
            $event->assetPack->addJs(
                $assetUrl->versioned('/_assets/register/audio-player/loader.js'),
                [AssetPack::OPTION_DEFER],
            );
        });
    }
}
