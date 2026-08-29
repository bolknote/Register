<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Register\Core\Http\ContentSecurityPolicy;
use Register\Core\Http\CspViolationReportController;
use Register\Core\Http\CspViolationReporter;
use Register\Core\Http\InlineStyleAttributeStripper;
use Register\Core\Http\LegacyContentStylesheetInjector;
use Register\Core\Http\ResponseCompressionCache;
use Register\Core\Asset\AssetMergeFactory;
use Register\Core\Comment\AkismetProxy;
use Register\Core\Comment\Antispam\CommentFormTokenManager;
use Register\Core\Comment\Antispam\ConfigurableSpamDetector;
use Register\Core\Comment\Antispam\LocalSpamDetector;
use Register\Core\Comment\Antispam\SpamAssessmentStoreInterface;
use Register\Core\Comment\Antispam\SpamFeatureExtractor;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Comment\Antispam\SpamMaintenance;
use Register\Core\Comment\Antispam\SpamMaintenanceQueueHandler;
use Register\Core\Comment\Antispam\SpamMetricsRepository;
use Register\Core\Comment\Antispam\SpamRateLimiter;
use Register\Core\Comment\Antispam\SpamRatePolicyRepository;
use Register\Core\Comment\Antispam\SpamReputationRepository;
use Register\Core\Comment\Antispam\SpamRiskScorer;
use Register\Core\Comment\Antispam\SpamRuleRepository;
use Register\Core\Comment\Antispam\SpamSignalPolicyRepository;
use Register\Core\Comment\Antispam\SpamTextClassifier;
use Register\Core\Comment\Antispam\SpamTextFeatureExtractor;
use Register\Core\Comment\Antispam\SpamTextModelRepository;
use Register\Core\Comment\CommentFormRenderer;
use Register\Core\Comment\CommentFormResponseProcessor;
use Register\Core\Comment\SpamDetectorInterface;
use Register\Core\Comment\SpamDecisionProvider;
use Register\Core\Comment\SpamDecisionProviderInterface;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Config\DynamicSecretStore;
use Register\Core\Config\DynamicSecretParameterRegistry;
use Register\Core\Config\DynamicSecretProviderInterface;
use Register\Core\Controller\Rss\FeedSettings;
use Register\Core\Framework\Container;
use Register\Core\Framework\Exception\ConfigurationException;
use Register\Core\Framework\ExtensionInterface;
use Register\Core\Framework\ResponseProcessorInterface;
use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Http\RedirectDetector;
use Register\Core\Http\TrustedProxyConfigurator;
use Register\Core\Http\Cache\PageCacheGarbageCollector;
use Register\Core\Http\Cache\PageCachePoolFactory;
use Register\Core\Http\Cache\PageCachePools;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\Remote\HostResolverInterface;
use Register\Core\HttpClient\Remote\NativeHostResolver;
use Register\Core\HttpClient\Remote\PublicAddressGuard;
use Register\Core\HttpClient\Remote\SafeRemoteHttpClient;
use Register\Core\Image\ThumbnailGenerator;
use Register\Core\Logger\Logger;
use Register\Core\Mail\ApplicationMailer;
use Register\Core\Mail\ApplicationMailerInterface;
use Register\Core\Mail\CommentMailer;
use Register\Core\Mail\MailDeliveryInspector;
use Register\Core\Mail\MailDeliveryLog;
use Register\Core\Mail\MailSettings;
use Register\Core\Mail\MailTransportFactory;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\Comment\CommentThreadBuilder;
use Register\Core\Model\ExtensionCache;
use Register\Core\Model\LoginRateLimiter;
use Register\Core\Model\UrlBuilder;
use Register\Core\Model\User\UserProvider;
use Register\Core\Monitoring\RequestPerformanceInspector;
use Register\Core\Monitoring\RequestPerformanceMonitor;
use Register\Core\Monitoring\QueryProfilerInspector;
use Register\Core\Monitoring\QueryProfilerLog;
use Register\Core\Monitoring\QueryProfilerState;
use Register\Core\Monitoring\RequestQueryProfiler;
use Register\Core\Monitoring\SqlQueryTemplateSanitizer;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerPostgres;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Pdo\PDO;
use Register\Core\Pdo\PdoSqliteFactory;
use Register\Core\Queue\BackgroundWorkRunner;
use Register\Core\Queue\QueueConsumer;
use Register\Core\Queue\QueueHandlerInterface;
use Register\Core\Queue\QueueHandlerRegistry;
use Register\Core\Queue\QueueMonitor;
use Register\Core\Queue\QueuePublisher;
use Register\Core\Queue\QueueRecovery;
use Register\Core\Queue\QueueRunnerLease;
use Register\Core\Queue\NativeShutdownRuntime;
use Register\Core\Queue\ScheduledWorkCoordinatorInterface;
use Register\Core\Queue\ScheduledMaintenanceTaskInterface;
use Register\Core\Queue\ShutdownWorkCoordinator;
use Register\Core\Security\Audit\SecurityAuditLogger;
use Register\Core\Security\Monitoring\SecurityTelemetryRecorder;
use Register\Core\Template\HtmlTemplateProvider;
use Register\Core\Template\TemplateEvent;
use Register\Core\Template\TemplateAssetEvent;
use Register\Core\Template\TemplateFinalReplaceEvent;
use Register\Core\Template\Viewer;
use Register\Core\Translation\ExtensibleTranslator;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Register\Core\Framework\Exception\ServiceAlreadyDefinedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class CmsExtension implements ExtensionInterface
{
    /**
     * @throws ServiceAlreadyDefinedException
     */
    #[\Override]
    public function buildContainer(Container $container): void
    {
        TrustedProxyConfigurator::configure(array_values($container->getArrayParameter('trusted_proxies')));

        $container->set(DbLayer::class, function (Container $container): \Register\Core\Pdo\DbLayer|\Register\Core\Pdo\DbLayerSqlite|\Register\Core\Pdo\DbLayerPostgres {
            $db_prefix = $container->getStringParameter('db_prefix');
            $db_type   = $container->getStringParameter('db_type');

            return match ($db_type) {
                'mysql' => new DbLayer($container->get(\PDO::class), $db_prefix),
                'sqlite' => new DbLayerSqlite($container->get(\PDO::class), $db_prefix),
                'pgsql' => new DbLayerPostgres($container->get(\PDO::class), $db_prefix),
                default => throw new \RuntimeException(\sprintf('Unsupported db_type="%s"', $db_type)),
            };
        }, [StatefulServiceInterface::class]);
        $container->set(\PDO::class, function (Container $container): \Register\Core\Pdo\PDO {
            $container->getStringParameter('db_prefix');
            $db_type     = $container->getStringParameter('db_type');
            $db_host     = $container->getStringParameter('db_host');
            $db_name     = $container->getStringParameter('db_name');
            $db_username = $container->getStringParameter('db_username');
            $db_password = $container->getStringParameter('db_password');
            $p_connect   = $container->getBoolParameter('p_connect');

            if (!class_exists(\PDO::class)) {
                throw new \RuntimeException('This PHP environment does not have PDO support built in. PDO support is required. Consult the PHP documentation for further assistance.');
            }

            if (!\in_array($db_type, \PDO::getAvailableDrivers(), true)) {
                throw new \RuntimeException(\sprintf('This PHP environment does not have PDO "%s" support built in. It is required if you want to use this type of database. Consult the PHP documentation for further assistance.', $db_type));
            }

            return match ($db_type) {
                'mysql' => new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_username, $db_password),
                'sqlite' => PdoSqliteFactory::create($container->getStringParameter('root_dir') . $db_name, $p_connect),
                'pgsql' => new PDO("pgsql:host=$db_host;dbname=$db_name", $db_username, $db_password),
                default => throw new \RuntimeException(\sprintf('Unsupported db_type="%s"', $db_type)),
            };
        }, [StatefulServiceInterface::class]);

        $container->set(ExtensionCache::class, fn(Container $container): \Register\Core\Model\ExtensionCache => new ExtensionCache(
            $container->get(DbLayer::class),
            $container->getBoolParameter('disable_cache'),
            $container->getStringParameter('cache_dir'),
        ));

        $container->set(ThumbnailGenerator::class, fn(Container $container): \Register\Core\Image\ThumbnailGenerator => new ThumbnailGenerator(
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->get(QueuePublisher::class),
            $container->getStringParameter('image_path'),
            $container->getStringParameter('image_dir'),
        ), [QueueHandlerInterface::class]);
        $container->set(LoggerInterface::class, fn(Container $container): \Register\Core\Logger\Logger => new Logger($container->getStringParameter('log_dir') . 'app.log', 'app', LogLevel::INFO));
        $container->set(RequestPerformanceMonitor::class, fn(Container $container): RequestPerformanceMonitor => new RequestPerformanceMonitor(
            $container->get(\PDO::class),
            $container->getStringParameter('log_dir') . 'performance.jsonl',
            $container->getFloatParameter('boot_timestamp'),
        ));
        $container->set(RequestPerformanceInspector::class, fn(Container $container): RequestPerformanceInspector => new RequestPerformanceInspector(
            $container->getStringParameter('log_dir') . 'performance.jsonl',
        ));
        $container->set(QueryProfilerState::class, fn(Container $container): QueryProfilerState => new QueryProfilerState(
            $container->getStringParameter('log_dir') . 'query-profiler-state.json',
        ));
        $container->set(QueryProfilerLog::class, fn(Container $container): QueryProfilerLog => new QueryProfilerLog(
            $container->getStringParameter('log_dir') . 'query-profiler.jsonl',
        ));
        $container->set(SqlQueryTemplateSanitizer::class, new SqlQueryTemplateSanitizer());
        $container->set(QueryProfilerInspector::class, fn(Container $container): QueryProfilerInspector => new QueryProfilerInspector(
            $container->get(QueryProfilerLog::class),
        ));
        $container->set(RequestQueryProfiler::class, fn(Container $container): RequestQueryProfiler => new RequestQueryProfiler(
            $container->get(\PDO::class),
            $container->get(QueryProfilerState::class),
            $container->get(QueryProfilerLog::class),
            $container->get(SqlQueryTemplateSanitizer::class),
            $container->getFloatParameter('boot_timestamp'),
        ), [StatefulServiceInterface::class]);
        $container->set(PageCachePoolFactory::class, fn(Container $container): PageCachePoolFactory => new PageCachePoolFactory(
            $container->get(LoggerInterface::class),
        ));
        $container->set(PageCachePools::class, fn(Container $container): PageCachePools => $container
            ->get(PageCachePoolFactory::class)
            ->create(
                $container->getStringParameter('cache_dir'),
                $container->getStringParameter('root_dir'),
            ));
        $container->set(PageCacheGarbageCollector::class, fn(Container $container): PageCacheGarbageCollector => new PageCacheGarbageCollector(
            $container->getStringParameter('cache_dir'),
            PageCachePoolFactory::filesystemNamespace(),
        ), [ScheduledMaintenanceTaskInterface::class]);
        $container->set(ResponseCompressionCache::class, fn(Container $container): ResponseCompressionCache => new ResponseCompressionCache(
            new FilesystemAdapter('response_encoding', 0, $container->getStringParameter('cache_dir')),
            $container->getBoolParameter('disable_cache'),
        ));

        $container->set(DynamicSecretParameterRegistry::class, static function (Container $container): DynamicSecretParameterRegistry {
            $registry = new DynamicSecretParameterRegistry([
                'REGISTER_AKISMET_KEY',
                'REGISTER_ANTISPAM_SECRET',
                MailSettings::SMTP_PASSWORD_CONFIG_KEY,
                MailSettings::DKIM_PRIVATE_KEY_CONFIG_KEY,
            ]);
            $registry->registerExtensionPrivate(MailDeliveryLog::HASH_SECRET_KEY);
            foreach ($container->getByTag(DynamicSecretProviderInterface::class) as $provider) {
                foreach ($provider->managedNames() as $parameterName) {
                    $registry->registerManaged($parameterName);
                }
            }

            return $registry;
        });
        $container->set(DynamicSecretStore::class, fn(Container $container): \Register\Core\Config\DynamicSecretStore => new DynamicSecretStore(
            $container->getStringParameter('secret_config_file'),
            $container->get(DynamicSecretParameterRegistry::class),
        ));
        $container->set(DynamicConfigProvider::class, fn(Container $container): \Register\Core\Config\DynamicConfigProvider => new DynamicConfigProvider(
            $container->get(DbLayer::class),
            $container->getStringParameter('cache_dir') . 'register_config.php',
            $container->getBoolParameter('disable_cache'),
            $container->get(DynamicSecretStore::class),
        ), [StatefulServiceInterface::class]); // TODO not enough, parameters are set into many other services
        $container->set(FeedSettings::class, fn(Container $container): FeedSettings => new FeedSettings(
            $container->get(DynamicConfigProvider::class),
        ));
        $container->set(MailSettings::class, fn(Container $container): MailSettings => new MailSettings(
            $container->get(DynamicConfigProvider::class),
        ));
        $container->set(MailDeliveryLog::class, fn(Container $container): MailDeliveryLog => new MailDeliveryLog(
            $container->getStringParameter('log_dir') . 'mail-delivery.jsonl',
            $container->get(DynamicSecretStore::class)->getOrCreateExtensionPrivate(MailDeliveryLog::HASH_SECRET_KEY),
        ));
        $container->set(MailDeliveryInspector::class, fn(Container $container): MailDeliveryInspector => new MailDeliveryInspector(
            $container->get(MailDeliveryLog::class),
        ));
        $container->set(MailTransportFactory::class, fn(Container $container): MailTransportFactory => new MailTransportFactory(
            $container->get(MailSettings::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(ApplicationMailer::class, fn(Container $container): ApplicationMailer => new ApplicationMailer(
            $container->get(MailSettings::class),
            $container->get(MailTransportFactory::class),
            $container->get(MailDeliveryLog::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(
            ApplicationMailerInterface::class,
            fn(Container $container): ApplicationMailerInterface => $container->get(ApplicationMailer::class),
        );

        $container->set('translator', function (Container $container): \Register\Core\Translation\ExtensibleTranslator {
            $provider = $container->get(DynamicConfigProvider::class);
            $language = $provider->getStringProxy('REGISTER_LANGUAGE');

            $translator = new ExtensibleTranslator($language);

            $translator->attachLoader('common', function (string $language, ExtensibleTranslator $translator): array {
                $fileName = __DIR__ . '/../../_lang/' . $language . '/common.php';
                if (!\file_exists($fileName)) {
                    throw new ConfigurationException(\sprintf('The language pack "%s" you have chosen seems to be corrupt. Please check that file "common.php" exists.', $language));
                }

                $translations = require $fileName;
                if (!\is_array($translations)) {
                    throw new ConfigurationException(\sprintf('The language pack "%s" you have chosen seems to be corrupt. Please check that file "common.php" has the correct format.', $language));
                }

                $locale = $translations['locale'] ?? 'en';

                $translator->setLocale($locale);

                return $translations;
            });

            return $translator;
        }, [StatefulServiceInterface::class]);

        $container->set(QueuePublisher::class, fn(Container $container): \Register\Core\Queue\QueuePublisher => new QueuePublisher(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
        ));
        $container->set(QueueMonitor::class, fn(Container $container): \Register\Core\Queue\QueueMonitor => new QueueMonitor(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
        ));
        $container->set(QueueRecovery::class, fn(Container $container): \Register\Core\Queue\QueueRecovery => new QueueRecovery(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
        ));
        $container->set(QueueHandlerRegistry::class, fn(Container $container): \Register\Core\Queue\QueueHandlerRegistry => new QueueHandlerRegistry(
            ...$container->getByTag(QueueHandlerInterface::class)
        ));
        $container->set(QueueConsumer::class, fn(Container $container): \Register\Core\Queue\QueueConsumer => new QueueConsumer(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
            $container->get(LoggerInterface::class),
            $container->get(QueueHandlerRegistry::class),
        ));
        $container->set(QueueRunnerLease::class, fn(Container $container): \Register\Core\Queue\QueueRunnerLease => new QueueRunnerLease(
            $container->get(\PDO::class),
            $container->getStringParameter('db_prefix'),
        ));
        $container->set(BackgroundWorkRunner::class, fn(Container $container): \Register\Core\Queue\BackgroundWorkRunner => new BackgroundWorkRunner(
            $container->get(\PDO::class),
            $container->get(QueueRunnerLease::class),
            $container->get(QueueConsumer::class),
            $container->get(ScheduledWorkCoordinatorInterface::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(ShutdownWorkCoordinator::class, fn(Container $container): \Register\Core\Queue\ShutdownWorkCoordinator => new ShutdownWorkCoordinator(
            $container->get(\PDO::class),
            $container->get(LoggerInterface::class),
            new NativeShutdownRuntime(),
            fn(): BackgroundWorkRunner => $container->get(BackgroundWorkRunner::class),
            $container->getFloatParameter('boot_timestamp'),
            $container->get(RequestPerformanceMonitor::class),
            $container->get(RequestQueryProfiler::class),
            webQueueCooldownSeconds: getenv('APP_ENV') === 'test' ? 0 : 30,
        ));

        $container->set(UrlBuilder::class, fn(Container $container): \Register\Core\Model\UrlBuilder => new UrlBuilder(
            $container->getStringParameter('base_path'),
            $container->getStringParameter('base_url'),
            $container->getStringParameter('url_prefix'),
        ));

        $container->set(RequestStack::class, fn(Container $_container): \Symfony\Component\HttpFoundation\RequestStack => new RequestStack());

        $container->set(SpamIdentityHasher::class, function (Container $container): \Register\Core\Comment\Antispam\SpamIdentityHasher {
            $staticSecret = $container->getNullableStringParameter('antispam_secret');
            $secret       = \is_string($staticSecret) && \strlen($staticSecret) >= 32
                ? $staticSecret
                : $container->get(DynamicConfigProvider::class)->getStringProxy('REGISTER_ANTISPAM_SECRET');

            return new SpamIdentityHasher($secret);
        });
        $container->set(SecurityAuditLogger::class, static fn(Container $container): SecurityAuditLogger => new SecurityAuditLogger(
            $container->getStringParameter('log_dir') . 'security-audit.jsonl',
            $container->get(SpamIdentityHasher::class),
        ));
        $container->set(SecurityTelemetryRecorder::class, static fn(Container $container): SecurityTelemetryRecorder => new SecurityTelemetryRecorder(
            $container->getStringParameter('log_dir') . 'security-events.jsonl',
            $container->get(SpamIdentityHasher::class),
        ));
        $container->set(CspViolationReporter::class, static fn(Container $container): CspViolationReporter => new CspViolationReporter(
            $container->getStringParameter('log_dir') . 'csp-violations.jsonl',
            $container->get(SpamIdentityHasher::class),
        ));
        $container->set(CspViolationReportController::class, static fn(Container $container): CspViolationReportController => new CspViolationReportController(
            $container->get(CspViolationReporter::class),
        ));
        $container->set(InlineStyleAttributeStripper::class, static fn(): InlineStyleAttributeStripper => new InlineStyleAttributeStripper());
        $container->set(LegacyContentStylesheetInjector::class, static fn(Container $container): LegacyContentStylesheetInjector => new LegacyContentStylesheetInjector(
            $container->getStringParameter('public_root_dir'),
            $container->getStringParameter('base_path'),
        ));
        $container->set(SpamFeatureExtractor::class, new SpamFeatureExtractor());
        $container->set(SpamTextFeatureExtractor::class, new SpamTextFeatureExtractor());
        $container->set(SpamTextModelRepository::class, fn(Container $container): SpamTextModelRepository => new SpamTextModelRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(SpamTextClassifier::class, fn(Container $container): SpamTextClassifier => new SpamTextClassifier(
            $container->get(SpamTextModelRepository::class),
            $container->get(SpamTextFeatureExtractor::class),
        ));
        $container->set(SpamMetricsRepository::class, fn(Container $container): \Register\Core\Comment\Antispam\SpamMetricsRepository => new SpamMetricsRepository(
            $container->get(DbLayer::class),
            $container->get(SpamTextModelRepository::class),
        ));
        $container->set(SpamReputationRepository::class, fn(Container $container): \Register\Core\Comment\Antispam\SpamReputationRepository => new SpamReputationRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(SpamRuleRepository::class, fn(Container $container): \Register\Core\Comment\Antispam\SpamRuleRepository => new SpamRuleRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(SpamSignalPolicyRepository::class, fn(Container $container): \Register\Core\Comment\Antispam\SpamSignalPolicyRepository => new SpamSignalPolicyRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(SpamRatePolicyRepository::class, fn(Container $container): \Register\Core\Comment\Antispam\SpamRatePolicyRepository => new SpamRatePolicyRepository(
            $container->get(DbLayer::class),
        ));
        $container->set(SpamRiskScorer::class, fn(Container $container): \Register\Core\Comment\Antispam\SpamRiskScorer => new SpamRiskScorer(
            $container->get(SpamIdentityHasher::class),
            $container->get(SpamFeatureExtractor::class),
            $container->get(SpamReputationRepository::class),
            $container->get(SpamRuleRepository::class),
            $container->get(SpamSignalPolicyRepository::class),
            $container->get(SpamTextClassifier::class),
        ));
        $container->set(CommentFormTokenManager::class, fn(Container $container): \Register\Core\Comment\Antispam\CommentFormTokenManager => new CommentFormTokenManager(
            $container->get(SpamIdentityHasher::class),
            $container->get(DbLayer::class),
            $container->getStringParameter('cookie_name'),
            $container->getStringParameter('base_path'),
        ));
        $container->set(SpamRateLimiter::class, fn(Container $container): \Register\Core\Comment\Antispam\SpamRateLimiter => new SpamRateLimiter(
            $container->get(DbLayer::class),
            $container->get(SpamIdentityHasher::class),
            $container->get(SpamRatePolicyRepository::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(LoginRateLimiter::class, fn(Container $container): LoginRateLimiter => new LoginRateLimiter(
            $container->get(DbLayer::class),
            $container->get(SpamIdentityHasher::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(SpamMaintenance::class, fn(Container $container): \Register\Core\Comment\Antispam\SpamMaintenance => new SpamMaintenance(
            $container->get(DbLayer::class),
            $container->get(SpamRateLimiter::class),
            $container->get(SpamAssessmentStoreInterface::class),
            $container->get(SpamReputationRepository::class),
            $container->get(LoggerInterface::class),
        ));
        $container->set(SpamMaintenanceQueueHandler::class, fn(Container $container): \Register\Core\Comment\Antispam\SpamMaintenanceQueueHandler => new SpamMaintenanceQueueHandler(
            $container->get(SpamMaintenance::class),
            $container->get(QueuePublisher::class),
        ), [QueueHandlerInterface::class]);

        $container->set(HttpClient::class, fn(Container $_container): \Register\Core\HttpClient\HttpClient => new HttpClient());
        $container->set('asset_http_client', fn(Container $_container): \Register\Core\HttpClient\HttpClient => new HttpClient(verifySsl: true));
        $container->set(HostResolverInterface::class, new NativeHostResolver());
        $container->set(PublicAddressGuard::class, static fn(Container $container): PublicAddressGuard => new PublicAddressGuard(
            $container->get(HostResolverInterface::class),
        ));
        $container->set(SafeRemoteHttpClient::class, static fn(Container $container): SafeRemoteHttpClient => new SafeRemoteHttpClient(
            $container->get(HttpClient::class),
            $container->get(PublicAddressGuard::class),
        ));

        $container->set(AssetMergeFactory::class, fn(Container $container): \Register\Core\Asset\AssetMergeFactory => new AssetMergeFactory(
            $container->get('asset_http_client'),
            $container->get(LoggerInterface::class),
            $container->getBoolParameter('debug'),
            // Application/config caches remain private. Merged CSS/JS must be
            // written below the actual document root in split deployments.
            $container->getStringParameter('public_root_dir') . '_cache/',
            $container->getStringParameter('base_path') . '/_cache/',
            $container->getBoolParameter('disable_cache'),
        ));

        $container->set(CommentFormRenderer::class, static fn(Container $container): CommentFormRenderer => new CommentFormRenderer(
            $container->get(UrlBuilder::class),
            $container->get('translator'),
            $container->get(Viewer::class),
            $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $container->get(CommentFormTokenManager::class),
            $container->get(AuthProvider::class),
        ));
        $container->set(
            CommentFormResponseProcessor::class,
            static fn(Container $container): CommentFormResponseProcessor => new CommentFormResponseProcessor(
                static fn(): CommentFormRenderer => $container->get(CommentFormRenderer::class),
            ),
            [ResponseProcessorInterface::class],
        );

        $container->set(HtmlTemplateProvider::class, function (Container $container): \Register\Core\Template\HtmlTemplateProvider {
            $provider = $container->get(DynamicConfigProvider::class);
            return new HtmlTemplateProvider(
                $container->get(RequestStack::class),
                $container->get(UrlBuilder::class),
                $container->get('translator'),
                $container->get(Viewer::class),
                $container->get(AssetMergeFactory::class),
                $container->get(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
                $provider->getStringProxy('REGISTER_STYLE'),
                $provider->getStringProxy('REGISTER_SITE_NAME'),
                $provider->getStringProxy('REGISTER_SITE_TAGLINE'),
                $provider->getStringProxy('REGISTER_SOCIAL_IMAGE'),
                $provider->getBoolProxy('REGISTER_ENABLED_COMMENTS'),
                $provider->getStringProxy('REGISTER_WEBMASTER'),
                $provider->getStringProxy('REGISTER_WEBMASTER_EMAIL'),
                $provider->getIntProxy('REGISTER_START_YEAR'),
                $container->getBoolParameter('debug_view'),
                $container->getStringParameter('root_dir'),
                $container->getStringParameter('base_path'),
                $container->getNullableStringParameter('canonical_url'),
                $container->get(CommentFormRenderer::class),
            );
        });

        $container->set(Viewer::class, function (Container $container): \Register\Core\Template\Viewer {
            $provider = $container->get(DynamicConfigProvider::class);
            return new Viewer(
                $container->get('translator'),
                $container->get(UrlBuilder::class),
                $container->getStringParameter('root_dir'),
                $provider->getStringProxy('REGISTER_STYLE'),
                $container->getBoolParameter('debug_view')
            );
        });

        $container->set(CommentThreadBuilder::class, static fn(): CommentThreadBuilder => new CommentThreadBuilder());

        $container->set('strict_viewer', function (Container $container): \Register\Core\Template\Viewer {
            $provider = $container->get(DynamicConfigProvider::class);
            return new Viewer(
                $container->get('translator'),
                $container->get(UrlBuilder::class),
                $container->getStringParameter('root_dir'),
                $provider->getStringProxy('REGISTER_STYLE'),
                false // no HTML debug info for XML and other non-HTML content
            );
        });

        $container->set(RedirectDetector::class, fn(Container $container): \Register\Core\Http\RedirectDetector => new RedirectDetector(
            $container->get(UrlBuilder::class),
            $container->getArrayParameter('redirect_map'),
        ));

        $container->set(CommentMailer::class, fn(Container $container): \Register\Core\Mail\CommentMailer => new CommentMailer(
            $container->get('comments_translator'),
            $container->get(ApplicationMailerInterface::class),
        ));

        $container->set('comments_translator', function (Container $container) {
            /** @var ExtensibleTranslator $translator */
            $translator = $container->get('translator');
            $translator->attachLoader('comments', fn(string $lang): array => require __DIR__ . '/../../_lang/' . $lang . '/comments.php');

            return $translator;
        });

        $container->set(AuthProvider::class, fn(Container $container): \Register\Core\Model\AuthProvider => new AuthProvider(
            $container->get(DbLayer::class),
            $container->getStringParameter('cookie_name'),
        ));

        $container->set(UserProvider::class, fn(Container $container): \Register\Core\Model\User\UserProvider => new UserProvider(
            $container->get(DbLayer::class),
        ));

        $container->set(AkismetProxy::class, function (Container $container): \Register\Core\Comment\AkismetProxy {
            $provider = $container->get(DynamicConfigProvider::class);
            return new AkismetProxy(
                $container->get(HttpClient::class),
                $container->get(UrlBuilder::class),
                $container->get(LoggerInterface::class),
                $provider->getStringProxy('REGISTER_AKISMET_KEY'),
            );
        });

        $container->set(LocalSpamDetector::class, function (Container $container): \Register\Core\Comment\Antispam\LocalSpamDetector {
            $provider = $container->get(DynamicConfigProvider::class);
            return new LocalSpamDetector(
                $container->get(SpamRiskScorer::class),
                $container->get(SpamAssessmentStoreInterface::class),
                $container->get(SpamIdentityHasher::class),
                $container->get(SpamFeatureExtractor::class),
                $container->get(LoggerInterface::class),
                $provider->getIntProxy('REGISTER_ANTISPAM_SPAM_SCORE'),
                $provider->getIntProxy('REGISTER_ANTISPAM_BLATANT_SCORE'),
            );
        });

        $container->set(SpamDetectorInterface::class, function (Container $container): \Register\Core\Comment\Antispam\ConfigurableSpamDetector {
            $provider = $container->get(DynamicConfigProvider::class);
            return new ConfigurableSpamDetector(
                $container->get(LocalSpamDetector::class),
                $container->get(AkismetProxy::class),
                $container->get(SpamAssessmentStoreInterface::class),
                $provider->getStringProxy('REGISTER_ANTISPAM_MODE'),
                $container->get(LoggerInterface::class),
            );
        }, ['dynamic_config_dependent']);

        $container->set(SpamDecisionProviderInterface::class, fn(Container $container): \Register\Core\Comment\SpamDecisionProvider => new SpamDecisionProvider(
            $container->get(SpamDetectorInterface::class),
            $container->get(SpamFeatureExtractor::class),
        ));

    }

    #[\Override]
    public function registerListeners(EventDispatcherInterface $eventDispatcher, Container $container): void
    {
        $eventDispatcher->addListener(TemplateEvent::EVENT_PRE_REPLACE, function (TemplateEvent $event) use ($container): void {
            $registerDebugOutput = '';
            $request = $container->get(RequestStack::class)->getCurrentRequest();
            if ($container->getBoolParameter('show_queries')
                && $request instanceof Request
                && $container->get(AuthProvider::class)->isAuthenticatedAdministrator($request)
            ) {
                $pdo     = $container->getIfInstantiated(\PDO::class);
                $pdoLogs = $pdo instanceof PDO ? $pdo->getQueryLog() : [];

                $viewer        = $container->get(Viewer::class);
                $registerDebugOutput = $viewer->render('debug_queries', [
                    'saved_queries' => $pdoLogs,
                ]);
            }

            $event->htmlTemplate->registerPlaceholder('<!-- register_debug -->', $registerDebugOutput);
        });

        $eventDispatcher->addListener(TemplateAssetEvent::class, static function (TemplateAssetEvent $event) use ($container): void {
            $basePath = rtrim($container->getStringParameter('base_path'), '/');
            $event->assetPack->addCss($basePath . '/_assets/register/content-security.css');
        });

        $eventDispatcher->addListener(TemplateFinalReplaceEvent::class, function (TemplateFinalReplaceEvent $event) use ($container): void {
            $content = '';
            $request = $container->get(RequestStack::class)->getCurrentRequest();
            if (
                ($container->getBoolParameter('debug') || defined('REGISTER_SHOW_TIME'))
                && $request instanceof Request
                && $container->get(AuthProvider::class)->isAuthenticatedAdministrator($request)
            ) {
                $viewer = $container->get(Viewer::class);

                /** @var TranslatorInterface $translator */
                $translator = $container->get('translator');

                $pdo           = $container->getIfInstantiated(\PDO::class);
                $executionTime = microtime(true) - $container->getFloatParameter('boot_timestamp');
                $content       = '<span class="technical-data">' . \sprintf(
                    $translator->trans('Performance info'),
                    $viewer->numberFormat($executionTime * 1000.0, true, 1),
                    $pdo instanceof PDO ? $pdo->getQueryCount() : 0
                ) . '</span>';
            }

            $event->replace('<!-- register_querytime -->', $content);
        }, -256);

        $eventDispatcher->addListener(TemplateFinalReplaceEvent::class, static function (TemplateFinalReplaceEvent $event) use ($container): void {
            $styleName = $container->get(DynamicConfigProvider::class)->getStringProxy('REGISTER_STYLE')->get();
            $template = $container->get(LegacyContentStylesheetInjector::class)->inject($event->template, $styleName);
            if ($template !== $event->template) {
                $event->setTemplate($template);
            }
        }, -384);

        $eventDispatcher->addListener(TemplateFinalReplaceEvent::class, static function (TemplateFinalReplaceEvent $event) use ($container): void {
            $event->setTemplate($container->get(InlineStyleAttributeStripper::class)->strip($event->template));
        }, -512);
    }

    #[\Override]
    public function registerRoutes(RouteCollection $routes, Container $container): void
    {
        unset($container);

        $routes->add('csp_report', new Route(
            ContentSecurityPolicy::REPORT_PATH,
            ['_controller' => CspViolationReportController::class],
        ));
    }
}
