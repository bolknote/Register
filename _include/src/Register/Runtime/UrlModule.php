<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Register\Url\ContentSlugService;
use Register\Url\ContentUrlAliasRepository;
use Register\Url\IcuTransliterator;
use Register\Url\PortableAsciiTransliterator;
use Register\Url\ReservedRouteRegistry;
use Register\Url\SlugGenerator;
use Register\Url\UniqueSlugGenerator;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\Pdo\DbLayer;

final readonly class UrlModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(SlugGenerator::class, new SlugGenerator(
            new PortableAsciiTransliterator(),
            IcuTransliterator::create(),
        ));
        $container->set(UniqueSlugGenerator::class, static fn(Container $container): UniqueSlugGenerator => new UniqueSlugGenerator(
            $container->get(SlugGenerator::class),
        ));
        $container->set(ReservedRouteRegistry::class, static function (Container $container): ReservedRouteRegistry {
            $provider = $container->get(DynamicConfigProvider::class);

            return new ReservedRouteRegistry(
                $provider->getStringProxy('REGISTER_TAGS_URL'),
                $provider->getStringProxy('REGISTER_FAVORITE_URL'),
            );
        });
        $container->set(ContentSlugService::class, static fn(Container $container): ContentSlugService => new ContentSlugService(
            $container->get(DbLayer::class),
            $container->get(UniqueSlugGenerator::class),
            $container->get(ReservedRouteRegistry::class),
            $container->get(ContentUrlAliasRepository::class),
        ));
    }
}
