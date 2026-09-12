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
use Register\Url\ContentUrlGenerator;
use Register\Url\TagUrlAliasRepository;
use Register\Url\TagUrlAliasRedirector;
use Register\Url\UrlHistoryService;
use Register\Content\ContentChangeDispatcher;
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
        $container->set(TagUrlAliasRepository::class, static fn(Container $container): TagUrlAliasRepository => new TagUrlAliasRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(TagUrlAliasRedirector::class, static fn(Container $container): TagUrlAliasRedirector => new TagUrlAliasRedirector(
            $container->get(TagUrlAliasRepository::class),
            $container->get(\Register\Core\Model\UrlBuilder::class),
            $container->get(DynamicConfigProvider::class)->getStringProxy('REGISTER_TAGS_URL'),
        ));
        $container->set(UrlHistoryService::class, static fn(Container $container): UrlHistoryService => new UrlHistoryService(
            $container->get(\PDO::class),
            $container->get(DbLayer::class),
            $container->get(ContentUrlGenerator::class),
            $container->get(ContentUrlAliasRepository::class),
            $container->get(TagUrlAliasRepository::class),
            $container->get(ContentChangeDispatcher::class),
        ));
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
            $container->get(ContentUrlGenerator::class),
        ));
    }
}
