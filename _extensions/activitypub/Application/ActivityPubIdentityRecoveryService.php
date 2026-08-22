<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Core\Config\DynamicSecretStore;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Security\ActivityPubSecret;
use Register\Extension\activitypub\Security\ActorKeyVault;
use Register\Extension\activitypub\Security\RsaCrypto;

/** Exports and authenticates the one secret required to recover encrypted actor keys. */
final readonly class ActivityPubIdentityRecoveryService
{
    private const string FORMAT = 'register-activitypub-identity-recovery';

    public function __construct(
        private FederationStateRepository $stateRepository,
        private LocalActorRepository      $actorRepository,
        private DynamicSecretStore        $secretStore,
        private ActorKeyVault             $keyVault,
        private RsaCrypto                 $rsaCrypto,
        private CanonicalJson             $canonicalJson,
    ) {
    }

    public function audit(): IdentityHealthReport
    {
        $secret = $this->secretStore->getExtensionPrivate(ActivityPubSecret::MASTER_KEY);
        if ($secret === null) {
            $identity = $this->identityDocument();

            return new IdentityHealthReport(
                \count($identity['actors']),
                $this->keyCount($identity),
                $this->identityFingerprint($identity),
                ['The ActivityPub master key is missing.'],
            );
        }

        return $this->auditWithSecret($secret);
    }

    public function exportRecoveryDocument(): string
    {
        $secret = $this->secretStore->getExtensionPrivate(ActivityPubSecret::MASTER_KEY);
        if ($secret === null) {
            throw new \RuntimeException('The ActivityPub master key is missing; a recoverable backup cannot be created.');
        }

        $report = $this->auditWithSecret($secret);
        if (!$report->isHealthy()) {
            throw new \RuntimeException('ActivityPub identity audit failed before backup: ' . implode('; ', $report->errors));
        }

        $identity = $this->identityDocument();

        return $this->canonicalJson->encode([
            'format'               => self::FORMAT,
            'version'              => 1,
            'canonicalOrigin'      => $identity['canonicalOrigin'],
            'actors'               => $identity['actors'],
            'identityFingerprint'  => $report->identityFingerprint,
            'masterKey'            => $secret,
            'restoreInstruction'   => 'Restore the database first, then authenticate this document before replacing the ActivityPub master key.',
        ]);
    }

    public function restoreRecoveryDocument(string $json): IdentityHealthReport
    {
        if ($json === '' || \strlen($json) > 4_194_304) {
            throw new \InvalidArgumentException('The ActivityPub identity recovery document is invalid.');
        }

        try {
            $document = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('The ActivityPub identity recovery document is invalid.', 0, $exception);
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new \InvalidArgumentException('The ActivityPub identity recovery document is invalid.');
        }

        $format = $this->value($document, 'format');
        $version = $this->value($document, 'version');
        $documentFingerprint = $this->value($document, 'identityFingerprint');
        $masterKey = $this->value($document, 'masterKey');
        if ($format !== self::FORMAT
            || $version !== 1
            || !\is_string($documentFingerprint)
            || !\is_string($masterKey)
        ) {
            throw new \InvalidArgumentException('The ActivityPub identity recovery document is invalid.');
        }

        $identity = $this->identityDocument();
        $fingerprint = $this->identityFingerprint($identity);
        if (!hash_equals($fingerprint, $documentFingerprint)) {
            throw new \DomainException('The ActivityPub recovery document belongs to another identity database.');
        }

        $report = $this->auditWithSecret($masterKey);
        if (!$report->isHealthy()) {
            throw new \DomainException('The ActivityPub recovery key does not unlock this identity database.');
        }

        $this->secretStore->replaceExtensionPrivate(ActivityPubSecret::MASTER_KEY, $masterKey);

        return $this->audit();
    }

    private function auditWithSecret(string $encodedMasterKey): IdentityHealthReport
    {
        $identity = $this->identityDocument();
        $errors = $this->masterSecretErrors($encodedMasterKey);
        $probePrefix = "Register ActivityPub identity audit\0";
        foreach ($this->actorRepository->allActors() as $actor) {
            $keys = $this->actorRepository->keysForActor($actor->id);
            if ($keys === []) {
                $errors[] = 'Actor ' . $actor->publicId . ' has no signing key.';
                continue;
            }

            $currentKeys = 0;
            foreach ($keys as $key) {
                if ($key->destroyedAt !== null) {
                    continue;
                }

                $currentKeys += (int)$key->current;
                try {
                    $privateKey = $this->keyVault->decryptWithMasterSecret(
                        $key->publicId,
                        $key->encryptedPrivateKey,
                        $encodedMasterKey,
                    );
                    try {
                        $probe = $probePrefix . $key->publicId;
                        $signature = $this->rsaCrypto->sign($privateKey, $probe);
                        if (!$this->rsaCrypto->verify($key->publicKeyPem, $probe, $signature)) {
                            $errors[] = 'Key ' . $key->publicId . ' does not match its public key.';
                        }
                    } finally {
                        sodium_memzero($privateKey);
                    }
                } catch (\Throwable) {
                    $errors[] = 'Key ' . $key->publicId . ' failed authenticated recovery.';
                }
            }

            if ($currentKeys !== 1) {
                $errors[] = 'Actor ' . $actor->publicId . ' does not have exactly one current key.';
            }
        }

        return new IdentityHealthReport(
            \count($identity['actors']),
            $this->keyCount($identity),
            $this->identityFingerprint($identity),
            array_values(array_unique($errors)),
        );
    }

    /**
     * @return array{
     *     canonicalOrigin: string|null,
     *     actors: list<array{publicId:string,state:string,keys:list<array{publicId:string,current:bool,destroyed:bool,publicKeySha256:string}>}>
     * }
     */
    private function identityDocument(): array
    {
        $actors = [];
        foreach ($this->actorRepository->allActors() as $actor) {
            $keys = [];
            foreach ($this->actorRepository->keysForActor($actor->id) as $key) {
                $keys[] = [
                    'publicId'        => $key->publicId,
                    'current'         => $key->current,
                    'destroyed'       => $key->destroyedAt !== null,
                    'publicKeySha256' => hash('sha256', $key->publicKeyPem),
                ];
            }

            $actors[] = [
                'publicId' => $actor->publicId,
                'state'    => $actor->state->value,
                'keys'     => $keys,
            ];
        }

        return [
            'canonicalOrigin' => $this->stateRepository->state()->canonicalOrigin?->value,
            'actors'          => $actors,
        ];
    }

    /** @param array{canonicalOrigin:string|null,actors:list<array{publicId:string,state:string,keys:list<array{publicId:string,current:bool,destroyed:bool,publicKeySha256:string}>}>} $identity */
    private function identityFingerprint(array $identity): string
    {
        return hash('sha256', $this->canonicalJson->encode($identity));
    }

    /** @param array{canonicalOrigin:string|null,actors:list<array{publicId:string,state:string,keys:list<array{publicId:string,current:bool,destroyed:bool,publicKeySha256:string}>}>} $identity */
    private function keyCount(array $identity): int
    {
        return array_sum(array_map(
            static fn(array $actor): int => \count($actor['keys']),
            $identity['actors'],
        ));
    }

    /** @return list<string> */
    private function masterSecretErrors(string $encodedMasterKey): array
    {
        try {
            $decoded = sodium_base642bin($encodedMasterKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\Throwable) {
            return ['The ActivityPub master key encoding is invalid.'];
        }

        try {
            $canonical = sodium_bin2base64($decoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

            return \strlen($decoded) === 32 && hash_equals($canonical, $encodedMasterKey)
                ? []
                : ['The ActivityPub master key encoding is invalid.'];
        } finally {
            sodium_memzero($decoded);
        }
    }

    /** @param array<mixed> $document */
    private function value(array $document, string $key): mixed
    {
        return $document[$key] ?? null;
    }
}
