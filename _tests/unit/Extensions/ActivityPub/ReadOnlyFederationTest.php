<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use S2\Cms\Config\DynamicSecretParameterRegistry;
use S2\Cms\Config\DynamicSecretStore;
use S2\Cms\Framework\Container;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\Pdo\DbLayerSqlite;
use s2_extensions\activitypub\Application\ActivationCheckResult;
use s2_extensions\activitypub\Application\ActivationReadinessCheck;
use s2_extensions\activitypub\Application\ActivationReadinessReport;
use s2_extensions\activitypub\Application\FederationActivationService;
use s2_extensions\activitypub\Application\PublicFederationAccess;
use s2_extensions\activitypub\Application\SiteActorDraft;
use s2_extensions\activitypub\Application\SiteActorProvisioner;
use s2_extensions\activitypub\Content\PortableHtmlSanitizer;
use s2_extensions\activitypub\Controller\ActorCollectionController;
use s2_extensions\activitypub\Controller\ActorController;
use s2_extensions\activitypub\Controller\ActorKeyController;
use s2_extensions\activitypub\Controller\WebFingerController;
use s2_extensions\activitypub\Domain\ActorType;
use s2_extensions\activitypub\Domain\CanonicalBasePath;
use s2_extensions\activitypub\Domain\CanonicalOrigin;
use s2_extensions\activitypub\Domain\CollectionAnchor;
use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalHandle;
use s2_extensions\activitypub\Domain\PublicIdGenerator;
use s2_extensions\activitypub\Extension;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Infrastructure\ActivityPubSchema;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Infrastructure\PortableDatabaseTransaction;
use s2_extensions\activitypub\Presentation\ActorDocumentBuilder;
use s2_extensions\activitypub\Presentation\ActorKeyDocumentBuilder;
use s2_extensions\activitypub\Security\ActivityPubSecret;
use s2_extensions\activitypub\Security\ActorKeyVault;
use s2_extensions\activitypub\Security\CollectionCursorCodec;
use s2_extensions\activitypub\Security\RsaCrypto;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouteCollection;

final class ReadOnlyFederationTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_activitypub_read_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testRegistersStableReadOnlyRoutes(): void
    {
        $routes = new RouteCollection();
        (new Extension())->registerRoutes($routes, new Container([]));

        $expectedPaths = [
            'activitypub_webfinger' => '/.well-known/webfinger',
            'activitypub_nodeinfo_discovery' => '/.well-known/nodeinfo',
            'activitypub_nodeinfo' => '/nodeinfo/2.1',
            'activitypub_actor' => '/activitypub/actors/{publicId}',
            'activitypub_object' => '/activitypub/objects/{publicId}',
            'activitypub_remote_avatar' => '/activitypub/media/{publicId}',
            'activitypub_activity' => '/activitypub/activities/{publicId}',
            'activitypub_key' => '/activitypub/keys/{publicId}',
        ];

        foreach ($expectedPaths as $routeName => $path) {
            $route = $routes->get($routeName);
            self::assertNotNull($route);
            self::assertSame($path, $route->getPath());
        }

        $actorRoute = $routes->get('activitypub_actor');
        self::assertNotNull($actorRoute);
        self::assertSame(['GET', 'HEAD'], $actorRoute->getMethods());
    }

    public function testDiscoveryActorKeyAndOpaqueCursorCollections(): void
    {
        $services = $this->services();
        $actor = $services['provisioner']->provision(new SiteActorDraft(
            ActorType::SERVICE,
            new LocalHandle('journal'),
            'The Journal',
            '<script>bad()</script><p onclick="bad()">Independent <a href="/about" rel="me">publishing</a>.</p>',
            'https://journal.example/register/about',
        ), 1_000);

        $hiddenRequest = Request::create('https://journal.example/.well-known/webfinger?resource=acct:journal@journal.example');
        self::assertSame(Response::HTTP_NOT_FOUND, $services['webFinger']->handle($hiddenRequest)->getStatusCode());

        $this->activate($services, $actor);
        $services['dbLayer']->insert(ActivityPubSchema::ACTOR_HANDLE_TABLE)->values([
            'handle'     => ':handle',
            'actor_id'   => ':actor_id',
            'is_current' => '0',
            'created_at' => ':created_at',
            'retired_at' => ':retired_at',
        ])->execute([
            'handle'     => 'oldjournal',
            'actor_id'   => $actor->id,
            'created_at' => 900,
            'retired_at' => 1_000,
        ]);
        $webFingerResponse = $services['webFinger']->handle($hiddenRequest);
        self::assertSame(Response::HTTP_OK, $webFingerResponse->getStatusCode());
        self::assertSame('application/jrd+json', $webFingerResponse->headers->get('Content-Type'));
        $webFinger = $this->jsonObject((string)$webFingerResponse->getContent());
        self::assertSame('acct:journal@journal.example', $webFinger['subject']);
        self::assertSame(
            'https://journal.example/register/activitypub/actors/' . $actor->publicId,
            $webFinger['links'][0]['href'],
        );
        $aliasRequest = Request::create('https://journal.example/.well-known/webfinger?resource=acct:oldjournal@journal.example');
        $aliasDocument = $this->jsonObject((string)$services['webFinger']->handle($aliasRequest)->getContent());
        self::assertSame('acct:oldjournal@journal.example', $aliasDocument['subject']);
        self::assertContains('acct:journal@journal.example', $aliasDocument['aliases']);

        $actorRequest = Request::create(
            'https://journal.example/register/activitypub/actors/' . $actor->publicId,
            server: ['HTTP_ACCEPT' => 'application/activity+json'],
        );
        $actorRequest->attributes->set('publicId', $actor->publicId);

        $actorResponse = $services['actorController']->handle($actorRequest);
        $actorDocument = $this->jsonObject((string)$actorResponse->getContent());
        self::assertSame(Response::HTTP_OK, $actorResponse->getStatusCode());
        self::assertSame('Service', $actorDocument['type']);
        self::assertSame(
            '<p>Independent <a href="https://journal.example/about" rel="me noopener noreferrer">publishing</a>.</p>',
            $actorDocument['summary'],
        );
        self::assertArrayHasKey('publicKeyPem', $actorDocument['publicKey']);
        self::assertSame(['acct:oldjournal@journal.example'], $actorDocument['alsoKnownAs']);
        self::assertStringNotContainsString('PRIVATE KEY', (string)$actorResponse->getContent());
        self::assertSame('*', $actorResponse->headers->get('Access-Control-Allow-Origin'));
        self::assertNotNull($actorResponse->getEtag());

        $htmlRequest = clone $actorRequest;
        $htmlRequest->headers->set('Accept', 'text/html');
        self::assertSame(
            Response::HTTP_NOT_ACCEPTABLE,
            $services['actorController']->handle($htmlRequest)->getStatusCode(),
        );

        $headRequest = clone $actorRequest;
        $headRequest->setMethod(Request::METHOD_HEAD);

        $headResponse = $services['actorController']->handle($headRequest);
        self::assertSame('', $headResponse->getContent());
        self::assertNotSame('0', $headResponse->headers->get('Content-Length'));

        $key = $services['actorRepository']->currentKey($actor->id);
        self::assertNotNull($key);
        $keyRequest = Request::create('https://journal.example/register/activitypub/keys/' . $key->publicId);
        $keyRequest->attributes->set('publicId', $key->publicId);

        $keyDocument = $this->jsonObject((string)$services['keyController']->handle($keyRequest)->getContent());
        self::assertSame('CryptographicKey', $keyDocument['type']);
        self::assertSame($actorDocument['id'], $keyDocument['owner']);

        $this->insertActivities($services['dbLayer'], $actor);
        $collectionRoot = $this->collectionRequest($actor->publicId);
        $rootDocument = $this->jsonObject((string)$services['collectionController']->handle($collectionRoot)->getContent());
        self::assertSame(45, $rootDocument['totalItems']);

        $firstPageRequest = $this->collectionRequest($actor->publicId, ['page' => 'true']);
        $firstPage = $this->jsonObject((string)$services['collectionController']->handle($firstPageRequest)->getContent());
        self::assertCount(40, $firstPage['orderedItems']);
        self::assertArrayHasKey('next', $firstPage);

        $nextQuery = [];
        parse_str((string)parse_url((string)$firstPage['next'], PHP_URL_QUERY), $nextQuery);
        self::assertIsString($nextQuery['cursor'] ?? null);
        $secondPageRequest = $this->collectionRequest($actor->publicId, [
            'page'   => 'true',
            'cursor' => $nextQuery['cursor'],
        ]);
        $secondPage = $this->jsonObject((string)$services['collectionController']->handle($secondPageRequest)->getContent());
        self::assertCount(5, $secondPage['orderedItems']);
        self::assertArrayNotHasKey('next', $secondPage);
        self::assertSame((string)$firstPage['next'], $secondPage['id']);

        $firstIds  = array_column($firstPage['orderedItems'], 'id');
        $secondIds = array_column($secondPage['orderedItems'], 'id');
        self::assertSame([], array_intersect($firstIds, $secondIds));

        $anchor = new CollectionAnchor(12_345, 67);
        $cursor = $services['cursorCodec']->encode('outbox:' . $actor->publicId, $anchor);
        self::assertSame($cursor, $services['cursorCodec']->encode('outbox:' . $actor->publicId, $anchor));
        self::assertEquals($anchor, $services['cursorCodec']->decode('outbox:' . $actor->publicId, $cursor));
        $this->expectException(\InvalidArgumentException::class);
        $services['cursorCodec']->decode('featured:' . $actor->publicId, $cursor);
    }

    /**
     * @return array{
     *     dbLayer: DbLayerSqlite,
     *     stateRepository: FederationStateRepository,
     *     actorRepository: LocalActorRepository,
     *     provisioner: SiteActorProvisioner,
     *     activation: FederationActivationService,
     *     webFinger: WebFingerController,
     *     actorController: ActorController,
     *     keyController: ActorKeyController,
     *     collectionController: ActorCollectionController,
     *     cursorCodec: CollectionCursorCodec
     * }
     */
    private function services(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $dbLayer = new DbLayerSqlite($pdo);
        ActivityPubSchema::install($dbLayer);

        $registry = new DynamicSecretParameterRegistry(['CORE_TEST_SECRET']);
        $registry->registerExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $secrets = new DynamicSecretStore($this->temporaryDirectory . '/config.secrets.php', $registry);
        $secrets->getOrCreateExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $stateRepository      = new FederationStateRepository($dbLayer);
        $actorRepository      = new LocalActorRepository($dbLayer);
        $federationRepository = new LocalFederationRepository($dbLayer);
        $transaction          = new PortableDatabaseTransaction($pdo);
        $urlFactory           = new FederationUrlGeneratorFactory($stateRepository);
        $vault                = new ActorKeyVault($secrets);
        $responseFactory      = new ActivityPubResponseFactory();
        $access               = new PublicFederationAccess($stateRepository);
        $provisioner          = new SiteActorProvisioner(
            $stateRepository,
            $actorRepository,
            new PublicIdGenerator(),
            new RsaCrypto(),
            $vault,
            $transaction,
            new PortableHtmlSanitizer(new HttpClient()),
        );
        $activation = new FederationActivationService($dbLayer, $stateRepository, $actorRepository, $transaction);
        $cursorCodec = new CollectionCursorCodec($secrets);

        return [
            'dbLayer'              => $dbLayer,
            'stateRepository'      => $stateRepository,
            'actorRepository'      => $actorRepository,
            'provisioner'          => $provisioner,
            'activation'           => $activation,
            'webFinger'            => new WebFingerController($stateRepository, $actorRepository, $urlFactory, $access, $responseFactory),
            'actorController'      => new ActorController(
                $actorRepository,
                $access,
                new ActorDocumentBuilder($stateRepository, $actorRepository, $urlFactory),
                $responseFactory,
            ),
            'keyController'        => new ActorKeyController(
                $actorRepository,
                $access,
                new ActorKeyDocumentBuilder($actorRepository, $urlFactory),
                $responseFactory,
            ),
            'collectionController' => new ActorCollectionController(
                $actorRepository,
                $federationRepository,
                $urlFactory,
                $cursorCodec,
                $access,
                $responseFactory,
            ),
            'cursorCodec' => $cursorCodec,
        ];
    }

    /** @param array<string, object> $services */
    private function activate(array $services, LocalActor $actor): void
    {
        $results = array_map(
            static fn(ActivationReadinessCheck $check): ActivationCheckResult => new ActivationCheckResult($check, true, 'Passed.'),
            ActivationReadinessCheck::cases(),
        );
        $activation = $services['activation'];
        if (!$activation instanceof FederationActivationService) {
            throw new \LogicException('The ActivityPub test activation service is missing.');
        }

        $activation->activate(new ActivationReadinessReport(
            $actor->publicId,
            new CanonicalOrigin('https://journal.example'),
            new CanonicalBasePath('/register'),
            1_050,
            $results,
        ), 1_100);
    }

    private function insertActivities(DbLayerSqlite $dbLayer, LocalActor $actor): void
    {
        $generator = new PublicIdGenerator();
        for ($index = 0; $index < 45; ++$index) {
            $publicId = $generator->generate();
            $publishedAt = 10_000 - intdiv($index, 2);
            $body = json_encode([
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id'       => 'https://journal.example/register/activitypub/activities/' . $publicId,
                'type'     => 'Create',
                'actor'    => 'https://journal.example/register/activitypub/actors/' . $actor->publicId,
                'object'   => ['type' => 'Article', 'name' => 'Post ' . $index],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $dbLayer->insert(ActivityPubSchema::ACTIVITY_TABLE)->values([
                'public_id'         => ':public_id',
                'actor_id'          => ':actor_id',
                'activity_type'     => ':activity_type',
                'visibility'        => ':visibility',
                'deduplication_key' => ':deduplication_key',
                'serialized_body'   => ':serialized_body',
                'body_hash'         => ':body_hash',
                'published_at'      => ':published_at',
                'created_at'        => ':created_at',
            ])->execute([
                'public_id'         => $publicId,
                'actor_id'          => $actor->id,
                'activity_type'     => 'Create',
                'visibility'        => 'public',
                'deduplication_key' => 'test-' . $index,
                'serialized_body'   => $body,
                'body_hash'         => hash('sha256', $body),
                'published_at'      => $publishedAt,
                'created_at'        => $publishedAt,
            ]);
        }
    }

    /** @param array<string, string> $query */
    private function collectionRequest(string $actorPublicId, array $query = []): Request
    {
        $request = Request::create(
            'https://journal.example/register/activitypub/actors/' . $actorPublicId . '/outbox',
            parameters: $query,
        );
        $request->attributes->set('publicId', $actorPublicId);
        $request->attributes->set('collection', 'outbox');

        return $request;
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $json): array
    {
        $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($value) || array_is_list($value)) {
            throw new \RuntimeException('Expected a JSON object in ActivityPub test response.');
        }

        return $value;
    }
}
