<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\FederationUrlGenerator;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorKey;
use Register\Extension\activitypub\Domain\ProtocolLimits;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Infrastructure\ActivationReadinessRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Presentation\ActivationProbeDocumentBuilder;
use Register\Extension\activitypub\Security\HttpSignatureRequest;
use Register\Extension\activitypub\Security\LegacyHttpSignature;
use Register\Extension\activitypub\Security\SignatureVerificationFailed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authenticates temporary self-test reads and the signed inbox loop without publishing the actor. */
final readonly class ActivationProbeService
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private ActivationReadinessRepository  $attemptRepository,
        private LocalActorRepository            $actorRepository,
        private ActivationProbeDocumentBuilder $documentBuilder,
        private LegacyHttpSignature             $legacySignature,
        ?\Closure                               $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return array<string, mixed>|null */
    public function webFinger(Request $request): ?array
    {
        $attempt = $this->attempt($request->query->getString('activation_probe'));
        if (!$attempt instanceof ActivationReadinessAttempt) {
            return null;
        }

        $actor = $this->actorRepository->findById($attempt->actorId);
        if (!$actor instanceof LocalActor) {
            return null;
        }

        $expected = 'acct:' . $actor->handle . '@' . $attempt->canonicalOrigin->authority();
        if (!hash_equals(strtolower($expected), strtolower($request->query->getString('resource')))) {
            return null;
        }

        return $this->documentBuilder->webFinger($attempt, $actor);
    }

    /** @return array<string, mixed>|null */
    public function actor(string $publicId, Request $request): ?array
    {
        $attempt = $this->attempt($request->query->getString('activation_probe'), $publicId);
        if (!$attempt instanceof ActivationReadinessAttempt) {
            return null;
        }

        $actor = $this->actorRepository->findById($attempt->actorId);

        return $actor instanceof LocalActor ? $this->documentBuilder->actor($attempt, $actor) : null;
    }

    /** Returns null when the request is not an activation probe; otherwise validates or throws. */
    public function acceptInbox(string $publicId, Request $request): ?bool
    {
        $probeId = $request->query->getString('activation_probe');
        $attempt = $this->attempt($probeId, $publicId);
        if (!$attempt instanceof ActivationReadinessAttempt) {
            return null;
        }

        if ($attempt->nextStep !== 2) {
            throw new InboxRequestException(Response::HTTP_UNAUTHORIZED, 'The ActivityPub activation inbox probe is not expected.');
        }

        $actor = $this->actorRepository->findById($attempt->actorId);
        $key   = $this->actorRepository->currentKey($attempt->actorId);
        if (!$actor instanceof LocalActor || !$key instanceof LocalActorKey) {
            throw new InboxRequestException(Response::HTTP_UNAUTHORIZED, 'The ActivityPub activation identity is unavailable.');
        }

        if ($key->destroyedAt !== null) {
            throw new InboxRequestException(Response::HTTP_UNAUTHORIZED, 'The ActivityPub activation identity is unavailable.');
        }

        $contentType = strtolower(trim(explode(';', $request->headers->get('Content-Type') ?? '', 2)[0]));
        $body        = $request->getContent();
        if ($contentType !== ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE
            || $body === ''
            || \strlen($body) > ProtocolLimits::INBOX_BODY_BYTES
        ) {
            throw new InboxRequestException(Response::HTTP_BAD_REQUEST, 'The ActivityPub activation probe body is invalid.');
        }

        $headers = [];
        foreach (['Host', 'Date', 'Digest', 'Content-Type', 'Signature'] as $name) {
            $value = $request->headers->get($name);
            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        $urls      = new FederationUrlGenerator($attempt->canonicalOrigin, $attempt->basePath);
        $targetUri = $urls->actorInbox($actor->publicId) . '?activation_probe=' . rawurlencode($attempt->id);
        try {
            $verified = $this->legacySignature->verify(
                new HttpSignatureRequest('POST', $targetUri, $headers, $body),
                $headers['Signature'] ?? '',
                $key->publicKeyPem,
                ($this->clock)(),
            );
        } catch (SignatureVerificationFailed | \InvalidArgumentException $exception) {
            throw new InboxRequestException(Response::HTTP_UNAUTHORIZED, $exception->getMessage());
        }

        if (!hash_equals($urls->key($key->publicId), $verified->keyId)) {
            throw new InboxRequestException(Response::HTTP_UNAUTHORIZED, 'The activation probe used a different signing key.');
        }

        try {
            $document = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InboxRequestException(Response::HTTP_BAD_REQUEST, $exception->getMessage());
        }

        $object = \is_array($document) ? ($document['object'] ?? null) : null;
        $expectedProbe = 'urn:register:activitypub:activation:' . $attempt->id;
        if (!\is_array($document)
            || ($document['type'] ?? null) !== 'Create'
            || ($document['actor'] ?? null) !== $urls->actor($actor->publicId)
            || !\is_array($object)
            || ($object['id'] ?? null) !== $expectedProbe
            || ($object['type'] ?? null) !== 'Note'
            || ($object['content'] ?? null) !== $expectedProbe
        ) {
            throw new InboxRequestException(Response::HTTP_BAD_REQUEST, 'The signed ActivityPub activation challenge is invalid.');
        }

        $now = ($this->clock)();
        if (!$this->attemptRepository->recordSignedProbe($attempt->id, $actor->id, $now)) {
            $reloaded = $this->attemptRepository->find($attempt->id);
            if (!$reloaded instanceof ActivationReadinessAttempt) {
                throw new InboxRequestException(Response::HTTP_SERVICE_UNAVAILABLE, 'The signed activation probe cannot be recorded.');
            }

            if ($reloaded->signedProbeReceivedAt === null) {
                throw new InboxRequestException(Response::HTTP_SERVICE_UNAVAILABLE, 'The signed activation probe cannot be recorded.');
            }
        }

        return true;
    }

    private function attempt(string $id, ?string $actorPublicId = null): ?ActivationReadinessAttempt
    {
        if ($actorPublicId !== null) {
            return $this->attemptRepository->usableProbe($id, $actorPublicId, ($this->clock)());
        }

        $attempt = $this->attemptRepository->find($id);

        if (!$attempt instanceof ActivationReadinessAttempt) {
            return null;
        }

        return $attempt->state === ActivationReadinessState::CHECKING
            && !$attempt->isExpired(($this->clock)())
            ? $attempt
            : null;
    }
}
