<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace Helper;

use Codeception\TestInterface;
use Register\Module\BaseModuleRegistry;
use Register\RegisterKernel;
use Register\Schema\SchemaManager;
use S2\Cms\Admin\AdminAjaxRequestHandler;
use S2\Cms\Admin\AdminRequestHandler;
use S2\Cms\Comment\Antispam\CommentFormTokenManager;
use S2\Cms\Comment\SpamDetectorComment;
use S2\Cms\Comment\SpamDetectorInterface;
use S2\Cms\Comment\SpamDetectorReport;
use S2\Cms\Config\StringProxy;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Framework\Application;
use S2\Cms\Framework\Container;
use S2\Cms\Framework\StatefulServiceInterface;
use S2\Cms\Model\Installer;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Pdo\DbLayer;
use S2\Rose\Storage\Database\PdoStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Tests\Support\Helper\AbstractBrowserModule;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use S2\Cms\Pdo\DbLayerException;

// "Tests\Support\Helper\AbstractBrowserModule" is loaded in AdminYard via autoload-dev and is not available here
require_once __DIR__ . '/../../../_vendor/s2/admin-yard/tests/Support/Helper/AbstractBrowserModule.php';

class Integration extends AbstractBrowserModule
{
    protected const ROOT_DIR = __DIR__ . '/../../../';

    protected Application $publicApplication;

    protected Application $adminApplication;

    protected Session $session;

    protected \PDO $pdo;

    private bool $spamDetectorDecorated = false;

    private bool $commentMailerDecorated = false;

    /** @var list<string> */
    private array $spamResponses = [];

    /**
     * @var mixed[][]
     */
    private array $moderatorMails = [];

    /**
     * @var mixed[][]
     */
    private array $subscriberMails = [];

    /**
     * @throws ContainerExceptionInterface
     * @throws DbLayerException
     * @throws NotFoundExceptionInterface
     */
    public function _initialize(): void
    {
        parent::_initialize();
        $this->clearConfigCache();
        $this->publicApplication = $this->createApplication();
        $this->pdo               = $this->publicApplication->container->get(\PDO::class);

        $this->adminApplication = $this->createAdminApplication();
        $this->adminApplication->container->decorate(\PDO::class, fn(): mixed => $this->pdo);
        $this->decorateSpamDetector();
        $this->decorateCommentMailer();

        $adminDbLayer = $this->adminApplication->container->get(DbLayer::class);
        $this->dropBaseModuleTables($adminDbLayer);
        $installer = new Installer($adminDbLayer);
        $installer->dropTables();
        $installer->createTables();

        $installer->insertConfigData('Test site', 'admin@example.com', 'English');
        $installer->insertMainPage('Main page', time());
        $this->createUsers();

        $this->session = new Session(new MockArraySessionStorage());

        /** Install product schema here since CREATE TABLE triggers an implicit commit on MySQL. */
        $this->adminApplication->container->get(SchemaManager::class)->ensureCurrent();
        $this->clearConfigCache();
    }

    public function _before(TestInterface $test): void
    {
        $this->clearConfigCache();
        $this->pdo->beginTransaction();
        $this->session->clear();
        $this->spamResponses  = [];
        $this->moderatorMails = [];
        $this->subscriberMails = [];
    }

    public function _after(TestInterface $test): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @param array<string, mixed> $parameterOverrides */
    public function createApplication(array $parameterOverrides = []): Application
    {
        $application = new Application();
        (new RegisterKernel(new BaseModuleRegistry()))->registerBaseModules($application, false);
        $application->boot($this->collectParameters($parameterOverrides));

        return $application;
    }

    /** @param array<string, mixed> $parameterOverrides */
    public function createAdminApplication(array $parameterOverrides = []): Application
    {
        $application = new Application();
        (new RegisterKernel(new BaseModuleRegistry()))->registerBaseModules($application, true);
        $application->boot($this->collectParameters($parameterOverrides));

        return $application;
    }

    public function grabService(string $serviceName): mixed
    {
        return $this->publicApplication->container->get($serviceName);
    }

    public function grabHttpHeader(string $name): ?string
    {
        return $this->response?->headers->get($name);
    }

    public function seeHttpHeader(string $name, string $expected): void
    {
        $this->assertSame($expected, $this->response?->headers->get($name));
    }

    public function sendRequestWithMethod(string $method, string $url): void
    {
        $this->doRequest(Request::create($url, $method));
    }

    /** @param array<string, string> $headers */
    public function sendRequestWithHeaders(string $url, array $headers): void
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->doRequest(Request::create($url, Request::METHOD_GET, server: $server));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function sendJson(string $url, array $payload, string $method = Request::METHOD_POST, array $headers = []): void
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->doRequest(Request::create(
            $url,
            $method,
            server: $server,
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        ));
    }

    public function grabTestCookie(string $name, string $path = '/'): ?string
    {
        return $this->cookieJar?->get($name, $path)?->getValue();
    }

    public function resetTestCookie(string $name, string $path = '/'): void
    {
        $this->cookieJar?->expire($name, $path);
    }

    /** @param array<string, mixed> $postData */
    public function sendPostWithAntispamVisitor(string $url, array $postData, string $visitorToken): void
    {
        $request = Request::create($url, Request::METHOD_POST, $postData);
        $manager = $this->publicApplication->container->get(CommentFormTokenManager::class);
        $cookie  = $manager->createVisitorCookie($visitorToken, $request);
        $cookieJar = $this->cookieJar ?? throw new \LogicException('The test cookie jar is not initialized.');
        $cookieJar->updateFromSetCookie([(string)$cookie], $url);

        $this->doRequest($request);
    }

    public function grabAdminService(string $serviceName): mixed
    {
        return $this->adminApplication->container->get($serviceName);
    }

    /** @param list<string> $statuses */
    public function setSpamResponses(array $statuses): void
    {
        $this->spamResponses = $statuses;
    }

    /**
     * @return mixed[][]
     */
    public function grabModeratorMails(): array
    {
        return $this->moderatorMails;
    }

    /**
     * @return mixed[][]
     */
    public function grabSubscriberMails(): array
    {
        return $this->subscriberMails;
    }

    public function shiftSpamResponse(): string
    {
        return array_shift($this->spamResponses) ?? SpamDetectorReport::STATUS_HAM;
    }

    /** @param array<string, mixed> $mail */
    public function recordModeratorMail(array $mail): void
    {
        $this->moderatorMails[] = $mail;
    }

    /** @param array<string, mixed> $mail */
    public function recordSubscriberMail(array $mail): void
    {
        $this->subscriberMails[] = $mail;
    }

    /**
     * Set config value and regenerate config cache for both applications.
     */
    public function setConfigValue(string $name, string $value): void
    {
        $statement = $this->pdo->prepare('UPDATE config SET value = :value WHERE name = :name');
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare the test configuration update.');
        }

        $statement->execute([':value' => $value, ':name' => $name]);

        $this->publicApplication->container->get(DynamicConfigProvider::class)->regenerate();
        $this->adminApplication->container->get(DynamicConfigProvider::class)->regenerate();

        $this->clearStatefulServices($this->publicApplication->container);
        $this->clearStatefulServices($this->adminApplication->container);
        $this->publicApplication->container->clearByTag('dynamic_config_dependent');
        $this->adminApplication->container->clearByTag('dynamic_config_dependent');
    }

    private function clearStatefulServices(Container $container): void
    {
        foreach ($container->getByTagIfInstantiated(StatefulServiceInterface::class) as $service) {
            /** @var StatefulServiceInterface $service */
            $service->clearState();
        }
    }

    private function dropBaseModuleTables(DbLayer $dbLayer): void
    {
        $this->adminApplication->container->get(PdoStorage::class)->drop();
        $dbLayer->dropTable('register_reaction');
        $dbLayer->dropTable('register_visitor_fingerprint');
        $dbLayer->dropTable('register_visitor');
        $dbLayer->dropTable('register_analytics_visitor');
        $dbLayer->dropTable('register_analytics_daily');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function collectParameters(array $overrides = []): array
    {
        $imgDir = '_tests/_output/images';

        $result = [
            'root_dir'           => self::ROOT_DIR,
            'cache_dir'          => '_cache/test/',
            'log_dir'            => '_cache/test/',
            'image_dir'          => self::ROOT_DIR . $imgDir . '/', // filesystem
            'image_path'         => '/' . $imgDir, // web URL prefix
            'allowed_extensions' => \S2\Cms\Config\StaticConfigLoader::DEFAULT_ALLOWED_EXTENSIONS,
            'disable_cache'      => false,
            'base_url'           => 'http://s2.localhost',
            'base_path'          => '',
            'trusted_proxies'    => [],
            'url_prefix'         => '',
            'debug'              => false,
            'debug_view'         => false,
            'show_queries'       => false,
            'boot_timestamp'     => microtime(true),
            'redirect_map'       => [
                '#^/redirect$#' => '/redirected',
            ],
            'version'            => '2.0dev',
            'canonical_url'      => null,

            'cookie_name'       => 's2_cookie_904732485',
            'antispam_secret'   => str_repeat('ab', 32),
            'backup_enabled'    => false,
            'backup_dir'        => self::ROOT_DIR . '_tests/_output/backups',
            'backup_retention'  => 2,
            'force_admin_https' => true,
            'db_host'           => '127.0.0.1',
            'db_name'           => 's2_test',
            'db_prefix'         => '',
            'p_connect'         => false,
            ...(match (getenv('APP_DB_TYPE')) {
                'sqlite' => ['db_type' => 'sqlite', 'db_username' => '', 'db_password' => ''],
                'pgsql' => ['db_type' => 'pgsql', 'db_username' => 'postgres', 'db_password' => '12345'],
                default => ['db_type' => 'mysql', 'db_username' => 'root', 'db_password' => ''],
            })
        ];

        return array_replace($result, $overrides);
    }

    /**
     * @throws DbLayerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function doRealRequest(Request $request): Response
    {
        if ($request->getPathInfo() === '/_admin/index.php') {
            /** @var AdminRequestHandler $handler */
            $handler = $this->adminApplication->container->get(AdminRequestHandler::class);
            return $handler->handle($request);
        }

        if ($request->getPathInfo() === '/_admin/ajax.php') {
            /** @var AdminAjaxRequestHandler $handler */
            $handler = $this->adminApplication->container->get(AdminAjaxRequestHandler::class);
            return $handler->handle($request);
        }

        if ($request->isMethod(Request::METHOD_POST) && !$request->request->has('antispam_token')) {
            $tokenManager = $this->publicApplication->container->get(CommentFormTokenManager::class);
            $visitorToken = $tokenManager->getOrCreateVisitorToken($request);
            $request->request->set('antispam_token', $tokenManager->issue($request->getPathInfo(), $visitorToken, time() - 5));
            $visitorCookie = $tokenManager->createVisitorCookie($visitorToken, $request);
            $request->cookies->set($visitorCookie->getName(), $visitorToken);
        }

        return $this->publicApplication->handle($request);
    }

    private function createUsers(): void
    {
        $roleMapping = [
            'nobody'          => '',
            'guest'           => PermissionChecker::PERMISSION_VIEW,
            'power_guest'     => PermissionChecker::PERMISSION_VIEW_HIDDEN,
            'moderator'       => PermissionChecker::PERMISSION_HIDE_COMMENTS,
            'power_moderator' => PermissionChecker::PERMISSION_EDIT_COMMENTS,
            'author'          => PermissionChecker::PERMISSION_CREATE_ARTICLES,
            'editor'          => PermissionChecker::PERMISSION_EDIT_SITE,
            'admin'           => PermissionChecker::PERMISSION_EDIT_USERS,
        ];
        foreach ($roleMapping as $role => $enabledPermission) {
            $fields = [
                'login'           => $role,
                'password'        => password_hash($role, PASSWORD_DEFAULT),
                'email'           => $role . '@example.com',
                'view'            => $enabledPermission !== '' ? 1 : 0,
                'view_hidden'     => $role === 'admin' || $enabledPermission === PermissionChecker::PERMISSION_VIEW_HIDDEN ? 1 : 0,
                'hide_comments'   => $role === 'admin' || $enabledPermission === PermissionChecker::PERMISSION_HIDE_COMMENTS ? 1 : 0,
                'edit_comments'   => $role === 'admin' || $enabledPermission === PermissionChecker::PERMISSION_EDIT_COMMENTS ? 1 : 0,
                'create_articles' => $role === 'admin' || $enabledPermission === PermissionChecker::PERMISSION_CREATE_ARTICLES ? 1 : 0,
                'edit_site'       => $role === 'admin' || $enabledPermission === PermissionChecker::PERMISSION_EDIT_SITE ? 1 : 0,
                'edit_users'      => $role === 'admin' || $enabledPermission === PermissionChecker::PERMISSION_EDIT_USERS ? 1 : 0,
            ];


            $statement = $this->pdo->prepare('INSERT INTO users (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_fill(0, \count($fields), '?')) . ')');
            if ($statement === false) {
                throw new \RuntimeException('Unable to prepare a test user insert.');
            }

            $statement->execute(array_values($fields));
        }
    }

    private static function deleteRecursive(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $array = scandir($dir);
        if ($array === false) {
            return false;
        }

        $files = array_diff($array, ['.', '..']);
        foreach ($files as $file) {
            is_dir("$dir/$file") ? self::deleteRecursive("$dir/$file") : unlink("$dir/$file");
        }

        return rmdir($dir);
    }

    private function clearConfigCache(): void
    {
        s2_call_without_warnings(static fn(): bool => self::deleteRecursive(self::ROOT_DIR . '_cache/test/config/'));
        s2_call_without_warnings(static fn(): bool => unlink(self::ROOT_DIR . '_cache/test/cache_config.php'));
    }

    private function decorateSpamDetector(): void
    {
        if ($this->spamDetectorDecorated) {
            return;
        }

        $decorator = (fn(Container $container, callable $factory): \S2\Cms\Comment\SpamDetectorInterface => new readonly class($this) implements SpamDetectorInterface {
            public function __construct(private Integration $helper)
            {
            }

            public function getReport(SpamDetectorComment $comment, string $clientIp): SpamDetectorReport
            {
                $status = $this->helper->shiftSpamResponse();
                return match ($status) {
                    SpamDetectorReport::STATUS_SPAM => SpamDetectorReport::spam(),
                    SpamDetectorReport::STATUS_BLATANT => SpamDetectorReport::blatant(),
                    SpamDetectorReport::STATUS_FAILED => SpamDetectorReport::failed(),
                    SpamDetectorReport::STATUS_DISABLED => SpamDetectorReport::disabled(),
                    default => SpamDetectorReport::ham(),
                };
            }
        });

        $this->publicApplication->container->decorate(SpamDetectorInterface::class, $decorator);
        $this->adminApplication->container->decorate(SpamDetectorInterface::class, $decorator);
        $this->spamDetectorDecorated = true;
    }

    private function decorateCommentMailer(): void
    {
        if ($this->commentMailerDecorated) {
            return;
        }

        $decorator = function (Container $container, callable $factory): \Helper\IntegrationCommentMailer {
            /** @var DynamicConfigProvider $provider */
            $provider = $container->get(DynamicConfigProvider::class);

            return new IntegrationCommentMailer(
                $container->get('comments_translator'),
                $provider->getStringProxy('S2_WEBMASTER'),
                $provider->getStringProxy('S2_WEBMASTER_EMAIL'),
                $this
            );
        };

        $this->publicApplication->container->decorate(\S2\Cms\Mail\CommentMailer::class, $decorator);
        $this->commentMailerDecorated = true;
    }
}

readonly class IntegrationCommentMailer extends \S2\Cms\Mail\CommentMailer
{
    public function __construct(
        \Symfony\Contracts\Translation\TranslatorInterface $translator,
        StringProxy                                        $webmasterName,
        StringProxy                                        $webmasterEmail,
        private Integration                                $helper
    ) {
        parent::__construct($translator, $webmasterName, $webmasterEmail);
    }

    public function mailToModerator(
        string $moderatorName,
        string $moderatorEmail,
        string $text,
        string $title,
        string $url,
        string $authorName,
        string $authorEmail,
        bool   $isPublished,
        string $spamReportStatus,
    ): bool {
        $this->helper->recordModeratorMail(['moderatorName' => $moderatorName, 'moderatorEmail' => $moderatorEmail, 'text' => $text, 'title' => $title, 'url' => $url, 'authorName' => $authorName, 'authorEmail' => $authorEmail, 'isPublished' => $isPublished, 'spamReportStatus' => $spamReportStatus]);
        return true;
    }

    public function mailToSubscriber(
        string $subscriberName,
        string $subscriberEmail,
        string $text,
        string $title,
        string $url,
        string $authorName,
        string $unsubscribeLink
    ): bool {
        $this->helper->recordSubscriberMail([
            'subscriberName'  => $subscriberName,
            'subscriberEmail' => $subscriberEmail,
            'text'            => $text,
            'title'           => $title,
            'url'             => $url,
            'authorName'      => $authorName,
            'unsubscribeLink' => $unsubscribeLink,
        ]);

        return true;
    }
}
