<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Runtime;

use Psr\Log\LoggerInterface;
use Register\Ai\AiClient;
use Register\Ai\AiSettings;
use Register\Content\PublicationMetadataGenerator;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Framework\Container;
use Register\Core\Framework\ContainerModuleInterface;
use Register\Core\HttpClient\HttpClient;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final readonly class AiModule implements ContainerModuleInterface
{
    #[\Override]
    public function buildContainer(Container $container): void
    {
        $container->set(AiSettings::class, static fn(Container $container): AiSettings => new AiSettings(
            $container->get(DynamicConfigProvider::class),
        ));
        $container->set('ai_token_cache', static fn(Container $container): FilesystemAdapter => new FilesystemAdapter(
            'ai_tokens',
            0,
            $container->getStringParameter('cache_dir'),
        ));
        $container->set(AiClient::class, static fn(Container $container): AiClient => new AiClient(
            $container->get(HttpClient::class),
            $container->get(AiSettings::class),
            $container->get('ai_token_cache'),
        ));
        $container->set(PublicationMetadataGenerator::class, static fn(Container $container): PublicationMetadataGenerator => new PublicationMetadataGenerator(
            $container->get(AiClient::class),
            $container->get(AiSettings::class),
            $container->get(LoggerInterface::class),
        ));
    }
}
