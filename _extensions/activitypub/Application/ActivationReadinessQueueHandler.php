<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use S2\Cms\HttpClient\Remote\SafeRemoteHttpClient;
use S2\Cms\HttpClient\Remote\SafeRemoteRequestOptions;
use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;
use S2\Cms\Queue\QueueTimeBudgetExceeded;
use s2_extensions\activitypub\Domain\FederationUrlGenerator;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalActorKey;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Infrastructure\ActivationReadinessRepository;
use s2_extensions\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Presentation\ActivationProbeDocumentBuilder;
use s2_extensions\activitypub\Presentation\ActivityStreamsContext;
use s2_extensions\activitypub\Presentation\CanonicalJson;
use s2_extensions\activitypub\Security\ActorKeyVault;
use s2_extensions\activitypub\Security\HttpSignatureRequest;
use s2_extensions\activitypub\Security\LegacyHttpSignature;

/** Advances exactly one externally observable activation probe per shutdown generation. */
final readonly class ActivationReadinessQueueHandler implements QueueHandlerInterface
{
    public const string CODE = 'register_activitypub_activation';

    private const int LAST_STEP = 2;

    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private ActivationReadinessRepository  $attemptRepository,
        private LocalActorRepository            $actorRepository,
        private ActivationProbeDocumentBuilder $documentBuilder,
        private SafeRemoteHttpClient            $httpClient,
        private LegacyHttpSignature             $legacySignature,
        private ActorKeyVault                   $keyVault,
        private CanonicalJson                   $canonicalJson,
        private QueuePublisher                  $queuePublisher,
        ?\Closure                               $clock = null,
        private ?ActivityPubRunnerTelemetryRepository $telemetry = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [self::CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 0.45;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        $step = $payload['step'] ?? null;
        if ($code !== self::CODE
            || preg_match('/^[A-Za-z0-9_-]{22}$/D', $id) !== 1
            || !\is_int($step)
            || $step < 0
            || $step > self::LAST_STEP
            || array_diff_key($payload, ['step' => true]) !== []
        ) {
            throw new \InvalidArgumentException('Invalid ActivityPub activation readiness job.');
        }

        $now = ($this->clock)();
        $this->telemetry?->record($code, $now);
        $attempt = $this->attemptRepository->find($id);
        if (!$attempt instanceof ActivationReadinessAttempt) {
            return;
        }

        if ($attempt->state !== ActivationReadinessState::CHECKING || $attempt->nextStep !== $step) {
            return;
        }

        $budget->checkpoint($this->minimumExecutionTime());
        if ($attempt->isExpired($now)) {
            $this->fail($attempt, $step, 'The activation attempt expired before this public-path probe ran.', $now);
            return;
        }

        $actor = $this->actorRepository->findById($attempt->actorId);
        if (!$actor instanceof LocalActor) {
            $this->fail($attempt, $step, 'The unpublished site actor is missing.', $now);
            return;
        }

        try {
            $results = match ($step) {
                0 => $this->probeWebFinger($attempt, $actor, $budget),
                1 => $this->probeActor($attempt, $actor, $budget),
                2 => $this->probeSignedInbox($attempt, $actor, $budget, $now),
            };
        } catch (QueueTimeBudgetExceeded $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->fail($attempt, $step, $this->safeDetail($exception->getMessage()), $now);
            return;
        }

        $passed    = array_filter($results, static fn(ActivationCheckResult $result): bool => !$result->passed) === [];
        $nextStep  = $step + 1;
        $nextState = !$passed
            ? ActivationReadinessState::FAILED
            : ($nextStep > self::LAST_STEP ? ActivationReadinessState::READY : ActivationReadinessState::CHECKING);
        if (!$this->attemptRepository->advance($id, $step, $results, $nextState, $nextStep, $now)) {
            return;
        }

        if ($nextState === ActivationReadinessState::CHECKING) {
            $this->queuePublisher->publish($id, self::CODE, ['step' => $nextStep], $now + 1);
        }
    }

    /** @return list<ActivationCheckResult> */
    private function probeWebFinger(
        ActivationReadinessAttempt $attempt,
        LocalActor                 $actor,
        QueueExecutionBudget       $budget,
    ): array {
        $account = 'acct:' . $actor->handle . '@' . $attempt->canonicalOrigin->authority();
        $url = $attempt->canonicalOrigin->value . '/.well-known/webfinger?' . http_build_query([
            'resource'         => $account,
            'activation_probe' => $attempt->id,
        ], '', '&', PHP_QUERY_RFC3986);
        $response = $this->httpClient->requestHop(
            'GET',
            $url,
            ['Accept' => ActivityPubResponseFactory::JRD_MEDIA_TYPE . ', application/json; q=0.9'],
            options: $this->options(131_072),
            budget: $budget,
        );
        if ($response->redirectUrl !== null || !$response->response->isSuccessful()) {
            return [$this->failedResult(
                ActivationReadinessCheck::ROOT_WEBFINGER,
                'Origin-root WebFinger returned HTTP ' . $response->response->statusCode . ' or redirected.',
            )];
        }

        $document = $this->decodeObject($response->response->content);
        if ($this->canonicalJson->encode($document)
            !== $this->canonicalJson->encode($this->documentBuilder->webFinger($attempt, $actor))
        ) {
            return [$this->failedResult(
                ActivationReadinessCheck::ROOT_WEBFINGER,
                'Origin-root WebFinger returned a different activation identity.',
            )];
        }

        return [$this->passedResult(
            ActivationReadinessCheck::ROOT_WEBFINGER,
            'Origin-root WebFinger resolved the unpublished actor through its expiring probe.',
        )];
    }

    /** @return list<ActivationCheckResult> */
    private function probeActor(
        ActivationReadinessAttempt $attempt,
        LocalActor                 $actor,
        QueueExecutionBudget       $budget,
    ): array {
        $urls = new FederationUrlGenerator($attempt->canonicalOrigin, $attempt->basePath);
        $url  = $urls->actor($actor->publicId) . '?activation_probe=' . rawurlencode($attempt->id);
        $response = $this->httpClient->requestHop(
            'GET',
            $url,
            ['Accept' => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE . ', application/ld+json; q=0.9'],
            options: $this->options(1_048_576),
            budget: $budget,
        );
        if ($response->redirectUrl !== null || !$response->response->isSuccessful()) {
            $detail = 'The canonical actor route returned HTTP ' . $response->response->statusCode . ' or redirected.';
            return [
                $this->failedResult(ActivationReadinessCheck::BASE_PATH_ROUTING, $detail),
                $this->failedResult(ActivationReadinessCheck::EXTERNAL_ACTOR_FETCH, $detail),
            ];
        }

        $routing = $this->passedResult(
            ActivationReadinessCheck::BASE_PATH_ROUTING,
            'The configured base path reaches the future canonical actor route.',
        );
        try {
            $document = $this->decodeObject($response->response->content);
            if ($this->canonicalJson->encode($document)
                !== $this->canonicalJson->encode($this->documentBuilder->actor($attempt, $actor))
            ) {
                throw new \UnexpectedValueException('The fetched actor document differs from the prepared identity.');
            }
        } catch (\Throwable $exception) {
            return [
                $routing,
                $this->failedResult(ActivationReadinessCheck::EXTERNAL_ACTOR_FETCH, $exception->getMessage()),
            ];
        }

        return [
            $routing,
            $this->passedResult(
                ActivationReadinessCheck::EXTERNAL_ACTOR_FETCH,
                'The externally fetched actor, endpoints, and public key match the prepared identity.',
            ),
        ];
    }

    /** @return list<ActivationCheckResult> */
    private function probeSignedInbox(
        ActivationReadinessAttempt $attempt,
        LocalActor                 $actor,
        QueueExecutionBudget       $budget,
        int                        $now,
    ): array {
        $key = $this->actorRepository->currentKey($actor->id);
        if (!$key instanceof LocalActorKey) {
            throw new \RuntimeException('The activation actor has no current key.');
        }

        if ($key->destroyedAt !== null) {
            throw new \RuntimeException('The activation actor has no current key.');
        }

        $urls      = new FederationUrlGenerator($attempt->canonicalOrigin, $attempt->basePath);
        $targetUrl = $urls->actorInbox($actor->publicId) . '?activation_probe=' . rawurlencode($attempt->id);
        $probeUrn  = 'urn:register:activitypub:activation:' . $attempt->id;
        $body      = $this->canonicalJson->encode([
            '@context' => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'       => $probeUrn . ':activity',
            'type'     => 'Create',
            'actor'    => $urls->actor($actor->publicId),
            'object'   => [
                'id'      => $probeUrn,
                'type'    => 'Note',
                'content' => $probeUrn,
            ],
        ]);
        $privateKey = $this->keyVault->decrypt($key->publicId, $key->encryptedPrivateKey);
        try {
            $signed = $this->legacySignature->sign(
                new HttpSignatureRequest(
                    'POST',
                    $targetUrl,
                    ['Content-Type' => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE],
                    $body,
                ),
                $urls->key($key->publicId),
                $privateKey,
                $now,
            );
        } finally {
            sodium_memzero($privateKey);
        }

        $headers = [
            'Accept'       => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE,
            'Content-Type' => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE,
        ];
        foreach ($signed->headers as $name => $value) {
            if (strtolower($name) !== 'host') {
                $headers[$name] = $value;
            }
        }

        $response = $this->httpClient->requestHop(
            'POST',
            $targetUrl,
            $headers,
            $body,
            $this->options(65_536),
            $budget,
        );
        $reloaded = $this->attemptRepository->find($attempt->id);
        if (!$reloaded instanceof ActivationReadinessAttempt) {
            return [$this->failedResult(
                ActivationReadinessCheck::SIGNED_INBOX_ROUND_TRIP,
                'The canonical inbox did not durably acknowledge the signed activation challenge.',
            )];
        }

        if ($response->redirectUrl !== null
            || $response->response->statusCode !== 202
            || $reloaded->signedProbeReceivedAt === null
        ) {
            return [$this->failedResult(
                ActivationReadinessCheck::SIGNED_INBOX_ROUND_TRIP,
                'The canonical inbox did not durably acknowledge the signed activation challenge.',
            )];
        }

        return [$this->passedResult(
            ActivationReadinessCheck::SIGNED_INBOX_ROUND_TRIP,
            'The future actor key signed a challenge that the canonical inbox verified and stored.',
        )];
    }

    private function fail(ActivationReadinessAttempt $attempt, int $step, string $detail, int $now): void
    {
        $results = array_map(
            fn(ActivationReadinessCheck $check): ActivationCheckResult => $this->failedResult($check, $detail),
            $this->checksForStep($step),
        );
        $this->attemptRepository->advance(
            $attempt->id,
            $step,
            $results,
            ActivationReadinessState::FAILED,
            $step + 1,
            $now,
        );
    }

    /** @return non-empty-list<ActivationReadinessCheck> */
    private function checksForStep(int $step): array
    {
        return match ($step) {
            0 => [ActivationReadinessCheck::ROOT_WEBFINGER],
            1 => [ActivationReadinessCheck::BASE_PATH_ROUTING, ActivationReadinessCheck::EXTERNAL_ACTOR_FETCH],
            2 => [ActivationReadinessCheck::SIGNED_INBOX_ROUND_TRIP],
            default => throw new \LogicException('The ActivityPub activation probe step is invalid.'),
        };
    }

    private function options(int $maxBytes): SafeRemoteRequestOptions
    {
        return new SafeRemoteRequestOptions(
            connectTimeout: 2,
            readTimeout: 3,
            maxResponseBytes: $maxBytes,
            requireHttps: true,
            deadlineSafetyMargin: 0.2,
        );
    }

    /** @return array<string, mixed> */
    private function decodeObject(?string $body): array
    {
        if ($body === null || $body === '') {
            throw new \UnexpectedValueException('The activation probe returned an empty document.');
        }

        $document = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($document) || array_is_list($document)) {
            throw new \UnexpectedValueException('The activation probe did not return a JSON object.');
        }

        return $document;
    }

    private function passedResult(ActivationReadinessCheck $check, string $detail): ActivationCheckResult
    {
        return new ActivationCheckResult($check, true, $detail);
    }

    private function failedResult(ActivationReadinessCheck $check, string $detail): ActivationCheckResult
    {
        return new ActivationCheckResult($check, false, $this->safeDetail($detail));
    }

    private function safeDetail(string $detail): string
    {
        $detail = preg_replace('/([?&]activation_probe=)[A-Za-z0-9_-]+/', '$1[redacted]', $detail) ?? '';
        $detail = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $detail) ?? '');

        return mb_substr($detail === '' ? 'The external activation probe failed.' : $detail, 0, 1_000);
    }
}
