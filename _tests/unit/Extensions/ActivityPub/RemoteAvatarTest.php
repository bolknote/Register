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
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\HttpClient\HttpClientInterface;
use S2\Cms\HttpClient\HttpResponse;
use S2\Cms\HttpClient\Remote\HostResolverInterface;
use S2\Cms\HttpClient\Remote\PublicAddressGuard;
use S2\Cms\HttpClient\Remote\SafeRemoteHttpClient;
use S2\Cms\Pdo\DbLayerSqlite;
use S2\Cms\Queue\QueueConsumer;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueHandlerRegistry;
use S2\Cms\Queue\QueuePublisher;
use s2_extensions\activitypub\Application\RemoteAvatarMaintenanceService;
use s2_extensions\activitypub\Application\RemoteAvatarScheduler;
use s2_extensions\activitypub\Admin\ActivityPubAdminRepository;
use s2_extensions\activitypub\Controller\RemoteAvatarController;
use s2_extensions\activitypub\Domain\PublicIdGenerator;
use s2_extensions\activitypub\Infrastructure\ActivityPubSchema;
use s2_extensions\activitypub\Infrastructure\FetchedRemoteActor;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\InteractionRepository;
use s2_extensions\activitypub\Infrastructure\NewRemoteInteraction;
use s2_extensions\activitypub\Infrastructure\RemoteActorRepository;
use s2_extensions\activitypub\Infrastructure\RemoteAvatarRepository;
use s2_extensions\activitypub\Inbox\RemoteActorDocumentValidator;
use s2_extensions\activitypub\Media\RemoteAvatarFetchClient;
use s2_extensions\activitypub\Media\RemoteAvatarImageInspector;
use s2_extensions\activitypub\Media\RemoteAvatarQueue;
use s2_extensions\activitypub\Media\RemoteAvatarQueueHandler;
use s2_extensions\activitypub\Media\RemoteAvatarStorage;
use s2_extensions\activitypub\Presentation\ActivityPubCommentPresentationEnricher;
use s2_extensions\activitypub\Presentation\CanonicalJson;
use s2_extensions\activitypub\Security\RsaCrypto;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RemoteAvatarTest extends Unit
{
    private string $temporaryDirectory = '';

    #[\Override]
    protected function _before(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/register_activitypub_avatar_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    #[\Override]
    protected function _after(): void
    {
        (new Filesystem())->remove($this->temporaryDirectory);
    }

    public function testActorIconIsQueuedThenMirroredAndServedOnlyFromPrivateLocalCache(): void
    {
        $avatarUrl = 'https://remote.example/media/avatar.png';
        $environment = $this->environment([
            new HttpResponse([
                'HTTP/1.1 200 OK',
                'Content-Type: image/png',
                'ETag: "avatar-v1"',
                'Last-Modified: Fri, 21 Aug 2026 12:00:00 GMT',
            ], 200, $this->png()),
        ]);
        $actor = $environment->saveActor($avatarUrl);

        self::assertSame(0, $environment->transport->requestCount());
        self::assertSame('pending', $environment->mediaRow()['state']);
        self::assertNotNull($environment->queueRow());
        self::assertSame(1, (new ActivityPubAdminRepository($environment->dbLayer))->summary()['avatars_pending']);

        self::assertTrue($environment->runQueue());
        self::assertSame(1, $environment->transport->requestCount());
        self::assertSame($avatarUrl, $environment->transport->requests[0]['url']);
        self::assertSame(RemoteAvatarImageInspector::MAX_BYTES + 1, $environment->transport->requests[0]['options'][HttpClient::MAX_RESPONSE_BYTES]);
        $media = $environment->mediaRow();
        self::assertSame('ready', $media['state']);
        self::assertSame('image/png', $media['content_type']);
        self::assertSame(hash('sha256', $this->png()), $media['content_hash']);
        self::assertSame(1, (int)$media['width']);
        self::assertSame(1, (int)$media['height']);
        self::assertNull($environment->queueRow());
        $avatarSummary = (new ActivityPubAdminRepository($environment->dbLayer))->summary();
        self::assertSame(1, $avatarSummary['avatars_ready']);
        self::assertSame(0, $avatarSummary['avatars_pending']);
        self::assertSame(0, $avatarSummary['avatars_failed']);

        $request = $environment->mediaRequest((string)$media['public_id']);
        $response = $environment->controller->handle($request);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame($this->png(), $response->getContent());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));

        $filename = $environment->storage->path((string)$media['storage_key']);
        self::assertFileExists($filename);
        $filePermissions = fileperms($filename);
        self::assertIsInt($filePermissions);
        self::assertSame(0600, $filePermissions & 0777);
        $denyPolicy = dirname($filename, 3) . '/.htaccess';
        self::assertFileExists($denyPolicy);
        self::assertStringContainsString('Require all denied', (string)file_get_contents($denyPolicy));

        $conditional = $environment->mediaRequest((string)$media['public_id']);
        $conditional->headers->set('If-None-Match', (string)$response->headers->get('ETag'));
        self::assertSame(Response::HTTP_NOT_MODIFIED, $environment->controller->handle($conditional)->getStatusCode());
        $head = $environment->mediaRequest((string)$media['public_id'], Request::METHOD_HEAD);
        $headResponse = $environment->controller->handle($head);
        self::assertSame('', $headResponse->getContent());
        self::assertSame((string)\strlen($this->png()), $headResponse->headers->get('Content-Length'));

        (new InteractionRepository($environment->dbLayer, new CanonicalJson()))->create(new NewRemoteInteraction(
            'reply',
            $actor->id,
            'https://remote.example/activities/reply-1',
            'https://remote.example/notes/reply-1',
            null,
            42,
            '',
            '',
            ['transport' => 'verified'],
            $environment->clock->now,
        ));
        $enrichments = (new ActivityPubCommentPresentationEnricher(
            $environment->dbLayer,
            $environment->clock->__invoke(...),
        ))->enrich([42]);
        self::assertCount(1, $enrichments);
        self::assertSame('/activitypub/media/' . $media['public_id'], $enrichments[0]->localAvatarPath);
        self::assertSame('https://remote.example/users/alice', $enrichments[0]->authorUrl);
        self::assertSame('https://remote.example/notes/reply-1', $enrichments[0]->sourceUrl);
        self::assertStringNotContainsString($avatarUrl, json_encode($enrichments[0], JSON_THROW_ON_ERROR));
    }

    public function testRefreshUsesConditionalRequestAndKeepsStablePublicUrlOn304(): void
    {
        $environment = $this->environment([
            new HttpResponse(['HTTP/1.1 200 OK', 'Content-Type: image/png', 'ETag: "avatar-v1"'], 200, $this->png()),
            new HttpResponse(['HTTP/1.1 304 Not Modified', 'ETag: "avatar-v1"'], 304, ''),
        ]);
        $environment->saveActor('https://remote.example/avatar.png');
        self::assertTrue($environment->runQueue());
        $before = $environment->mediaRow();

        $environment->clock->now += 24 * 60 * 60 + 1;
        self::assertSame(1, $environment->maintenance->scheduleDue($environment->clock->now));
        ++$environment->clock->now;
        self::assertTrue($environment->runQueue());
        $after = $environment->mediaRow();
        self::assertSame('ready', $after['state']);
        self::assertSame($before['public_id'], $after['public_id']);
        self::assertSame($before['storage_key'], $after['storage_key']);
        self::assertSame('"avatar-v1"', $environment->transport->requests[1]['headers']['If-None-Match']);
        self::assertGreaterThan((int)$before['serve_until'], (int)$after['serve_until']);
    }

    public function testMissingCacheFileForcesFullRefreshInsteadOfAccepting304(): void
    {
        $environment = $this->environment([
            new HttpResponse(['HTTP/1.1 200 OK', 'Content-Type: image/png', 'ETag: "avatar-v1"'], 200, $this->png()),
            new HttpResponse(['HTTP/1.1 200 OK', 'Content-Type: image/png', 'ETag: "avatar-v2"'], 200, $this->png()),
        ]);
        $environment->saveActor('https://remote.example/avatar.png');
        self::assertTrue($environment->runQueue());
        $before = $environment->mediaRow();
        unlink($environment->storage->path((string)$before['storage_key']));

        $environment->clock->now += 24 * 60 * 60 + 1;
        self::assertSame(1, $environment->maintenance->scheduleDue($environment->clock->now));
        ++$environment->clock->now;
        self::assertTrue($environment->runQueue());
        self::assertArrayNotHasKey('If-None-Match', $environment->transport->requests[1]['headers']);
        self::assertSame('ready', $environment->mediaRow()['state']);
        self::assertFileExists($environment->storage->path((string)$environment->mediaRow()['storage_key']));
    }

    public function testRedirectIsPersistedAndFetchedAsASeparatePinnedHop(): void
    {
        $environment = $this->environment([
            new HttpResponse(['HTTP/1.1 302 Found', 'Location: https://cdn.example/avatar.png'], 302, ''),
            new HttpResponse(['HTTP/1.1 200 OK', 'Content-Type: image/png'], 200, $this->png()),
        ], [
            'remote.example' => ['93.184.216.34'],
            'cdn.example'    => ['93.184.216.35'],
        ]);
        $environment->saveActor('https://remote.example/avatar.png');

        self::assertTrue($environment->runQueue());
        self::assertSame(1, $environment->transport->requestCount());
        $redirected = $environment->mediaRow();
        self::assertSame('pending', $redirected['state']);
        self::assertSame('https://cdn.example/avatar.png', $redirected['request_url']);
        self::assertSame(1, (int)$redirected['redirect_count']);

        ++$environment->clock->now;
        self::assertTrue($environment->runQueue());
        self::assertSame(2, $environment->transport->requestCount());
        self::assertSame('https://cdn.example/avatar.png', $environment->transport->requests[1]['url']);
        self::assertSame('ready', $environment->mediaRow()['state']);
    }

    public function testPrivateAddressIsRejectedBeforeTransportAndNeverBecomesPublic(): void
    {
        $environment = $this->environment(
            [new HttpResponse(['HTTP/1.1 200 OK', 'Content-Type: image/png'], 200, $this->png())],
            ['private.example' => ['127.0.0.1']],
        );
        $environment->saveActor('https://private.example/avatar.png');

        self::assertTrue($environment->runQueue());
        self::assertSame(0, $environment->transport->requestCount());
        $media = $environment->mediaRow();
        self::assertSame('failed', $media['state']);
        self::assertSame('unsafe_address', $media['error_code']);
        self::assertSame(1, (new ActivityPubAdminRepository($environment->dbLayer))->summary()['avatars_failed']);
        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $environment->controller->handle($environment->mediaRequest((string)$media['public_id']))->getStatusCode(),
        );
    }

    public function testInspectorRejectsMimeSpoofAndOversizeBeforePublishing(): void
    {
        $inspector = new RemoteAvatarImageInspector();
        try {
            $inspector->inspect($this->png(), 'image/jpeg');
            self::fail('A MIME-spoofed avatar must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('does not match', $exception->getMessage());
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('512 KiB');
        $inspector->inspect(str_repeat('x', RemoteAvatarImageInspector::MAX_BYTES + 1), 'image/png');
    }

    public function testActorValidatorExtractsUsableIconAndIgnoresUnsafeOptionalIcon(): void
    {
        $rsa = new RsaCrypto();
        $pair = $rsa->generateKeyPair();
        $validator = new RemoteActorDocumentValidator($rsa, new CanonicalJson());
        $document = (static fn(string $iconUrl): string => json_encode([
            '@context'          => 'https://www.w3.org/ns/activitystreams',
            'id'                => 'https://remote.example/users/alice',
            'type'              => 'Person',
            'preferredUsername' => 'alice',
            'inbox'             => 'https://remote.example/users/alice/inbox',
            'icon'              => ['type' => 'Image', 'url' => $iconUrl],
            'publicKey'         => [
                'id'           => 'https://remote.example/users/alice#key',
                'owner'        => 'https://remote.example/users/alice',
                'publicKeyPem' => $pair->publicKeyPem,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $valid = $validator->validateForDiscovery(
            'https://remote.example/users/alice',
            $document('https://remote.example/avatar.png'),
            time(),
        );
        self::assertSame('https://remote.example/avatar.png', $valid->avatarUrl);
        $unsafe = $validator->validateForDiscovery(
            'https://remote.example/users/alice',
            $document('http://127.0.0.1/avatar.png'),
            time(),
        );
        self::assertNull($unsafe->avatarUrl);
    }

    /**
     * @param list<HttpResponse|\Throwable> $responses
     * @param array<string, list<string>> $dns
     */
    private function environment(
        array $responses,
        array $dns = ['remote.example' => ['93.184.216.34']],
    ): RemoteAvatarTestEnvironment {
        return new RemoteAvatarTestEnvironment($this->temporaryDirectory, $responses, $dns);
    }

    private function png(): string
    {
        $content = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        if (!\is_string($content)) {
            throw new \RuntimeException('The remote avatar PNG fixture is invalid.');
        }

        return $content;
    }
}

/** @internal */
final readonly class RemoteAvatarTestEnvironment
{
    public \PDO $pdo;

    public DbLayerSqlite $dbLayer;

    public RemoteAvatarTestClock $clock;

    public RemoteAvatarTestTransport $transport;

    public RemoteAvatarRepository $repository;

    public RemoteActorRepository $actorRepository;

    public RemoteAvatarStorage $storage;

    public RemoteAvatarController $controller;

    public RemoteAvatarMaintenanceService $maintenance;

    private QueueConsumer $consumer;

    private RsaCrypto $rsa;

    /**
     * @param list<HttpResponse|\Throwable> $responses
     * @param array<string, list<string>> $dns
     */
    public function __construct(string $temporaryDirectory, array $responses, array $dns)
    {
        $this->clock = new RemoteAvatarTestClock(time());
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->dbLayer = new DbLayerSqlite($this->pdo, '');
        $this->createQueueTable();
        ActivityPubSchema::install($this->dbLayer);
        $this->dbLayer->update(ActivityPubSchema::STATE_TABLE)
            ->set('lifecycle_state', ':state')->setParameter('state', 'active')
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $this->clock->now)
            ->where('id = :id')->setParameter('id', 'installation')
            ->execute()
        ;

        $publicIds = new PublicIdGenerator();
        $publisher = new QueuePublisher($this->pdo, '');
        $this->repository = new RemoteAvatarRepository($this->dbLayer, $publicIds);
        $queue = new RemoteAvatarQueue($publisher, $this->repository);
        $scheduler = new RemoteAvatarScheduler($this->repository, $queue);
        $this->actorRepository = new RemoteActorRepository($this->dbLayer, $scheduler);
        $this->storage = new RemoteAvatarStorage($temporaryDirectory . '/cache');
        $this->transport = new RemoteAvatarTestTransport($responses);
        $safeClient = new SafeRemoteHttpClient(
            $this->transport,
            new PublicAddressGuard(new RemoteAvatarTestHostResolver($dns)),
        );
        $logger = new NullLogger();
        $handler = new RemoteAvatarQueueHandler(
            $this->repository,
            new RemoteAvatarFetchClient($safeClient),
            new RemoteAvatarImageInspector(),
            $this->storage,
            new FederationStateRepository($this->dbLayer),
            $queue,
            $logger,
            $this->clock->__invoke(...),
        );
        $this->consumer = new QueueConsumer(
            $this->pdo,
            '',
            $logger,
            new QueueHandlerRegistry($handler),
        );
        $this->controller = new RemoteAvatarController(
            $this->repository,
            $this->storage,
            $this->clock->__invoke(...),
        );
        $this->maintenance = new RemoteAvatarMaintenanceService(
            $this->repository,
            $queue,
            $this->storage,
            $logger,
        );
        $this->rsa = new RsaCrypto();
    }

    public function saveActor(?string $avatarUrl): \s2_extensions\activitypub\Domain\RemoteActor
    {
        $pair = $this->rsa->generateKeyPair();
        $snapshot = json_encode([
            'id'   => 'https://remote.example/users/alice',
            'icon' => $avatarUrl,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->actorRepository->save(new FetchedRemoteActor(
            'https://remote.example/users/alice',
            'Person',
            'alice',
            'Alice',
            'https://remote.example/users/alice/inbox',
            'https://remote.example/inbox',
            'https://remote.example/users/alice#key',
            $pair->publicKeyPem,
            [],
            $snapshot,
            hash('sha256', $snapshot),
            $this->clock->now,
            $this->clock->now + 6 * 60 * 60,
            null,
            $avatarUrl,
        ));
    }

    /** @phpstan-impure */
    public function runQueue(): bool
    {
        return $this->consumer->runQueue(
            $this->clock->now,
            new QueueExecutionBudget(10.0, static fn(): float => 0.0),
        );
    }

    /** @return array<string, mixed> */
    public function mediaRow(): array
    {
        $row = $this->dbLayer->select('*')->from(ActivityPubSchema::REMOTE_MEDIA_TABLE)->execute()->fetchAssoc();
        if (!\is_array($row)) {
            throw new \RuntimeException('The remote avatar fixture row is missing.');
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function queueRow(): ?array
    {
        $row = $this->dbLayer->select('*')
            ->from('queue')
            ->where('id = :id')->setParameter('id', RemoteAvatarQueue::JOB_ID)
            ->andWhere('code = :code')->setParameter('code', RemoteAvatarQueue::CODE)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $row : null;
    }

    public function mediaRequest(string $publicId, string $method = Request::METHOD_GET): Request
    {
        $request = Request::create('https://local.example/activitypub/media/' . $publicId, $method);
        $request->attributes->set('publicId', $publicId);

        return $request;
    }

    private function createQueueTable(): void
    {
        $this->pdo->exec('CREATE TABLE queue (
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
    }
}

/** @internal */
final class RemoteAvatarTestTransport implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|null, options: array<string, int|bool|string>}> */
    public array $requests = [];

    /** @param list<HttpResponse|\Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    public function append(HttpResponse|\Throwable $response): void
    {
        $this->responses[] = $response;
    }

    public function requestCount(): int
    {
        return \count($this->requests);
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
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        if (!$response instanceof HttpResponse) {
            throw new \RuntimeException('The remote avatar test transport has no response queued.');
        }

        return $response;
    }

    #[\Override]
    public function resolveRedirectUrl(string $location, string $currentUrl): string
    {
        return (new HttpClient())->resolveRedirectUrl($location, $currentUrl);
    }
}

/** @internal */
final readonly class RemoteAvatarTestHostResolver implements HostResolverInterface
{
    /** @param array<string, list<string>> $answers */
    public function __construct(private array $answers)
    {
    }

    /** @return list<string> */
    #[\Override]
    public function resolve(string $host, ?float $timeoutSeconds = null): array
    {
        unset($timeoutSeconds);

        return $this->answers[$host] ?? [];
    }
}

/** @internal */
final class RemoteAvatarTestClock
{
    public function __construct(public int $now)
    {
    }

    public function __invoke(): int
    {
        return $this->now;
    }
}
