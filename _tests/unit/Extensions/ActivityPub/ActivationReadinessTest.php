<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use Psr\Log\NullLogger;
use Register\Core\Config\DynamicSecretParameterRegistry;
use Register\Core\Config\DynamicSecretStore;
use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpClientInterface;
use Register\Core\HttpClient\HttpResponse;
use Register\Core\HttpClient\Remote\HostResolverInterface;
use Register\Core\HttpClient\Remote\PublicAddressGuard;
use Register\Core\HttpClient\Remote\SafeRemoteHttpClient;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Queue\QueueConsumer;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Core\Queue\QueueHandlerRegistry;
use Register\Core\Queue\QueuePublisher;
use Register\Extension\activitypub\Application\ActivationCheckResult;
use Register\Extension\activitypub\Application\ActivationProbeService;
use Register\Extension\activitypub\Application\ActivationReadinessAttempt;
use Register\Extension\activitypub\Application\ActivationReadinessCheck;
use Register\Extension\activitypub\Application\ActivationReadinessQueueHandler;
use Register\Extension\activitypub\Application\ActivationReadinessStarter;
use Register\Extension\activitypub\Application\ActivationReadinessState;
use Register\Extension\activitypub\Application\FederationActivationService;
use Register\Extension\activitypub\Application\BundledReleaseInteroperabilityGate;
use Register\Extension\activitypub\Application\InboxRateLimiter;
use Register\Extension\activitypub\Application\InboxRequestValidator;
use Register\Extension\activitypub\Application\PublicFederationAccess;
use Register\Extension\activitypub\Application\ReleaseInteroperabilityGateInterface;
use Register\Extension\activitypub\Application\SiteActorDraft;
use Register\Extension\activitypub\Application\SiteActorProvisioner;
use Register\Extension\activitypub\Content\PortableHtmlSanitizer;
use Register\Extension\activitypub\Controller\ActorController;
use Register\Extension\activitypub\Controller\InboxController;
use Register\Extension\activitypub\Controller\WebFingerController;
use Register\Extension\activitypub\Domain\ActorType;
use Register\Extension\activitypub\Domain\CanonicalBasePath;
use Register\Extension\activitypub\Domain\CanonicalOrigin;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Domain\LocalHandle;
use Register\Extension\activitypub\Domain\PublicIdGenerator;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Inbox\InboxQueue;
use Register\Extension\activitypub\Infrastructure\ActivationReadinessRepository;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\InboxRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;
use Register\Extension\activitypub\Presentation\ActivationProbeDocumentBuilder;
use Register\Extension\activitypub\Presentation\ActorDocumentBuilder;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Security\ActivityPubSecret;
use Register\Extension\activitypub\Security\ActorKeyVault;
use Register\Extension\activitypub\Security\LegacyHttpSignature;
use Register\Extension\activitypub\Security\Rfc9421HttpSignature;
use Register\Extension\activitypub\Security\RsaCrypto;
use Register\Extension\activitypub\Manifest;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ActivationReadinessTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_activitypub_readiness_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testTwoPhaseSetupUsesThreeShutdownGenerationsAndOnlyThenFreezesIdentity(): void
    {
        $now = 10_000;
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE queue (
            id TEXT NOT NULL,
            code TEXT NOT NULL,
            payload TEXT NOT NULL,
            generation INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            available_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL,
            last_error TEXT,
            failed_at INTEGER,
            PRIMARY KEY (id, code)
        )');
        $dbLayer = new DbLayerSqlite($pdo);
        ActivityPubSchema::install($dbLayer);

        $registry = new DynamicSecretParameterRegistry(['CORE_TEST_SECRET']);
        $registry->registerExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $secretStore = new DynamicSecretStore($this->temporaryDirectory . '/config.secrets.php', $registry);
        $secretStore->getOrCreateExtensionPrivate(ActivityPubSecret::MASTER_KEY);

        $stateRepository   = new FederationStateRepository($dbLayer);
        $actorRepository   = new LocalActorRepository($dbLayer);
        $attemptRepository = new ActivationReadinessRepository($dbLayer);
        $transaction       = new PortableDatabaseTransaction($pdo);
        $rsa               = new RsaCrypto();
        $vault             = new ActorKeyVault($secretStore);
        $legacy            = new LegacyHttpSignature($rsa);
        $rfc9421           = new Rfc9421HttpSignature($rsa);
        $publisher         = new QueuePublisher($pdo, '');
        $responseFactory   = new ActivityPubResponseFactory();
        $urlFactory        = new FederationUrlGeneratorFactory($stateRepository);
        $provisioner       = new SiteActorProvisioner(
            $stateRepository,
            $actorRepository,
            new PublicIdGenerator(),
            $rsa,
            $vault,
            $transaction,
            new PortableHtmlSanitizer(new HttpClient()),
        );
        $probeBuilder = new ActivationProbeDocumentBuilder($actorRepository, $stateRepository);
        $probeService = new ActivationProbeService(
            $attemptRepository,
            $actorRepository,
            $probeBuilder,
            $legacy,
            static fn(): int => $now,
        );
        $access = new PublicFederationAccess($stateRepository);
        $webFingerController = new WebFingerController(
            $stateRepository,
            $actorRepository,
            $urlFactory,
            $access,
            $responseFactory,
            $probeService,
        );
        $actorController = new ActorController(
            $actorRepository,
            $access,
            new ActorDocumentBuilder($stateRepository, $actorRepository, $urlFactory),
            $responseFactory,
            $probeService,
        );
        $inboxRepository = new InboxRepository($dbLayer);
        $inboxController = new InboxController(
            $stateRepository,
            $actorRepository,
            $urlFactory,
            $access,
            new InboxRequestValidator($legacy, $rfc9421, static fn(): int => $now),
            new InboxRateLimiter($dbLayer),
            $inboxRepository,
            new InboxQueue($publisher, $inboxRepository),
            $responseFactory,
            new NullLogger(),
            activationProbeService: $probeService,
        );
        $transport = new ActivationProbeTestTransport(static function (Request $request) use (
            $webFingerController,
            $actorController,
            $inboxController,
        ): Response {
            $path = $request->getPathInfo();
            if ($path === '/.well-known/webfinger') {
                return $webFingerController->handle($request);
            }

            if (preg_match('~/activitypub/actors/([A-Za-z0-9_-]{22})/inbox$~D', $path, $match) === 1) {
                $request->attributes->set('publicId', $match[1]);
                return $inboxController->handle($request);
            }

            if (preg_match('~/activitypub/actors/([A-Za-z0-9_-]{22})$~D', $path, $match) === 1) {
                $request->attributes->set('publicId', $match[1]);
                return $actorController->handle($request);
            }

            return new Response('', Response::HTTP_NOT_FOUND);
        });
        $safeClient = new SafeRemoteHttpClient(
            $transport,
            new PublicAddressGuard(new ActivationProbeTestResolver()),
        );
        $handler = new ActivationReadinessQueueHandler(
            $attemptRepository,
            $actorRepository,
            $probeBuilder,
            $safeClient,
            $legacy,
            $vault,
            new CanonicalJson(),
            $publisher,
            static fn(): int => $now,
        );
        $consumer = new QueueConsumer($pdo, '', new NullLogger(), new QueueHandlerRegistry($handler));
        $starter  = new ActivationReadinessStarter(
            $stateRepository,
            $actorRepository,
            $attemptRepository,
            $provisioner,
            new PublicIdGenerator(),
            $vault,
            $rsa,
            $dbLayer,
            new class implements ReleaseInteroperabilityGateInterface {
                #[\Override]
                public function check(): ActivationCheckResult
                {
                    return new ActivationCheckResult(
                        ActivationReadinessCheck::RELEASE_INTEROPERABILITY_GATE,
                        true,
                        'Test release matrix passed.',
                    );
                }
            },
            $publisher,
            'https://journal.example',
            '/register',
        );
        $attempt = $starter->start(
            new SiteActorDraft(
                ActorType::ORGANIZATION,
                new LocalHandle('journal'),
                'The Journal',
                '<p>Independent publishing.</p>',
                'https://journal.example/register/about',
            ),
            new CanonicalOrigin('https://journal.example'),
            new CanonicalBasePath('/register'),
            $now,
        );
        $draft = $actorRepository->findById($attempt->actorId);
        self::assertInstanceOf(LocalActor::class, $draft);
        self::assertSame(LocalActorState::DRAFT, $draft->state);
        self::assertSame(ActivationReadinessState::CHECKING, $attempt->state);
        self::assertCount(6, $attempt->results());
        $attemptId = $attempt->id;

        $withoutProbe = Request::create(
            'https://journal.example/register/activitypub/actors/' . $draft->publicId,
        );
        $withoutProbe->attributes->set('publicId', $draft->publicId);
        self::assertSame(Response::HTTP_NOT_FOUND, $actorController->handle($withoutProbe)->getStatusCode());

        self::assertTrue($consumer->runQueue($now, new QueueExecutionBudget(10.0, static fn(): float => 0.0)));
        $attempt = $attemptRepository->find($attemptId);
        self::assertInstanceOf(ActivationReadinessAttempt::class, $attempt);
        self::assertSame(1, $attempt->nextStep);
        self::assertTrue($attempt->result(ActivationReadinessCheck::ROOT_WEBFINGER)?->passed);

        self::assertTrue($consumer->runQueue($now + 1, new QueueExecutionBudget(10.0, static fn(): float => 0.0)));
        $attempt = $attemptRepository->find($attemptId);
        self::assertInstanceOf(ActivationReadinessAttempt::class, $attempt);
        self::assertSame(2, $attempt->nextStep);
        self::assertTrue($attempt->result(ActivationReadinessCheck::BASE_PATH_ROUTING)?->passed);
        self::assertTrue($attempt->result(ActivationReadinessCheck::EXTERNAL_ACTOR_FETCH)?->passed);

        self::assertTrue($consumer->runQueue($now + 2, new QueueExecutionBudget(10.0, static fn(): float => 0.0)));
        $attempt = $attemptRepository->find($attemptId);
        self::assertInstanceOf(ActivationReadinessAttempt::class, $attempt);
        self::assertSame(ActivationReadinessState::READY, $attempt->state);
        self::assertNotNull($attempt->signedProbeReceivedAt);
        self::assertCount(\count(ActivationReadinessCheck::cases()), $attempt->results());
        self::assertSame(['GET', 'GET', 'POST'], array_column($transport->requests, 'method'));

        $activation = new FederationActivationService(
            $dbLayer,
            $stateRepository,
            $actorRepository,
            $transaction,
            $attemptRepository,
        );
        $actor = $activation->activateAttempt($attemptId, $now + 3);
        self::assertSame(LocalActorState::ACTIVE, $actor->state);
        self::assertSame(FederationLifecycleState::ACTIVE, $stateRepository->lifecycleState());
        $attempt = $attemptRepository->find($attemptId);
        self::assertInstanceOf(ActivationReadinessAttempt::class, $attempt);
        self::assertSame(ActivationReadinessState::ACTIVATED, $attempt->state);
        self::assertSame('https://journal.example', $stateRepository->state()->canonicalOrigin?->value);
        self::assertSame('/register', $stateRepository->state()->basePath->value);
    }

    public function testReleaseGateRequiresAnExactPeerMatrixAttestationForThisBuild(): void
    {
        $filename = $this->temporaryDirectory . '/interoperability-attestation.json';
        $resultsFilename = $this->temporaryDirectory . '/interoperability-results.json';
        $gate     = new BundledReleaseInteroperabilityGate($filename, $resultsFilename);
        self::assertFalse($gate->check()->passed);

        $peers = [
            'akkoma',
            'ghost',
            'gotosocial',
            'mastodon',
            'misskey',
            'register',
            'wordpress-activitypub',
            'writefreely',
        ];
        $scenarios = [
            'announce',
            'create',
            'delete',
            'discovery',
            'duplicate_delivery',
            'follow',
            'like',
            'reply',
            'retry',
            'signed_fetch',
            'undo',
            'update',
        ];
        $resultsBody = json_encode([
            'schema'            => 1,
            'module_version'    => Manifest::VERSION,
            'protocol_profile'  => Manifest::PROTOCOL_PROFILE,
            'completed_at'      => '2026-08-22T12:00:00Z',
            'database_profiles' => ['mysql', 'pgsql', 'sqlite'],
            'runtime'           => [
                'shared_hosting' => true,
                'redis'          => false,
                'external_cron'  => false,
                'ext_openssl'    => false,
            ],
            'peers' => array_map(static fn(string $peer): array => [
                'family'                 => $peer,
                'implementation_version' => 'test-version',
                'scenarios'              => $scenarios,
            ], $peers),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($resultsFilename, $resultsBody);
        file_put_contents($filename, json_encode([
            'schema'           => 1,
            'module_version'   => Manifest::VERSION,
            'protocol_profile' => Manifest::PROTOCOL_PROFILE,
            'suite_sha256'     => hash('sha256', $resultsBody),
            'completed_at'     => '2026-08-22T12:00:00Z',
            'peers'            => $peers,
        ], JSON_THROW_ON_ERROR));
        self::assertTrue($gate->check()->passed);

        $attestation = json_decode((string)file_get_contents($filename), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($attestation);
        $attestation = array_reverse($attestation, true);
        file_put_contents($filename, json_encode($attestation, JSON_THROW_ON_ERROR));
        self::assertTrue($gate->check()->passed);

        file_put_contents($resultsFilename, $resultsBody . "\n");
        self::assertFalse($gate->check()->passed);
        file_put_contents($resultsFilename, $resultsBody);

        $attestation['module_version'] = '0.0.0';
        file_put_contents($filename, json_encode($attestation, JSON_THROW_ON_ERROR));
        self::assertFalse($gate->check()->passed);
    }
}

/** @internal */
final readonly class ActivationProbeTestResolver implements HostResolverInterface
{
    /** @return list<string> */
    #[\Override]
    public function resolve(string $host, ?float $timeoutSeconds = null): array
    {
        unset($timeoutSeconds);

        return $host === 'journal.example' ? ['8.8.8.8'] : [];
    }
}

/** @internal */
final class ActivationProbeTestTransport implements HttpClientInterface
{
    /** @var list<array{method:string, url:string}> */
    public array $requests = [];

    /** @param \Closure(Request): Response $dispatch */
    public function __construct(private readonly \Closure $dispatch)
    {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, int|bool|string> $options
     */
    #[\Override]
    public function request(
        string  $method,
        string  $url,
        array   $headers = [],
        ?string $body = null,
        array   $options = [],
    ): HttpResponse {
        unset($options);
        $this->requests[] = ['method' => $method, 'url' => $url];
        $request = Request::create($url, $method, content: $body ?? '');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        if (\is_string($host)) {
            $request->headers->set('Host', $host . (\is_int($port) && $port !== 443 ? ':' . $port : ''));
        }

        if ($body !== null) {
            $request->headers->set('Content-Length', (string)\strlen($body));
        }

        $response = ($this->dispatch)($request);
        $responseHeaders = [];
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                $responseHeaders[] = $name . ': ' . $value;
            }
        }

        $content = $response->getContent();

        return new HttpResponse(
            $responseHeaders,
            $response->getStatusCode(),
            \is_string($content) ? $content : null,
        );
    }

    #[\Override]
    public function resolveRedirectUrl(string $location, string $currentUrl): string
    {
        return (new HttpClient())->resolveRedirectUrl($location, $currentUrl);
    }
}
