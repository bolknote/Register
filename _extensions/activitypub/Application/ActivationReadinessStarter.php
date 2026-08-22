<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use S2\Cms\Pdo\DbLayer;
use S2\Cms\Queue\QueuePublisher;
use s2_extensions\activitypub\Domain\CanonicalBasePath;
use s2_extensions\activitypub\Domain\CanonicalOrigin;
use s2_extensions\activitypub\Domain\FederationLifecycleState;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalActorKey;
use s2_extensions\activitypub\Domain\PublicIdGenerator;
use s2_extensions\activitypub\Infrastructure\ActivationReadinessRepository;
use s2_extensions\activitypub\Infrastructure\ActivityPubSchema;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Security\ActorKeyVault;
use s2_extensions\activitypub\Security\RsaCrypto;

/** Prepares an identity and schedules bounded public-path probes without publishing it. */
final readonly class ActivationReadinessStarter
{
    private const int ATTEMPT_TTL_SECONDS = 15 * 60;

    public function __construct(
        private FederationStateRepository            $stateRepository,
        private LocalActorRepository                  $actorRepository,
        private ActivationReadinessRepository         $attemptRepository,
        private SiteActorProvisioner                  $provisioner,
        private PublicIdGenerator                     $publicIdGenerator,
        private ActorKeyVault                         $keyVault,
        private RsaCrypto                             $rsaCrypto,
        private DbLayer                               $dbLayer,
        private ReleaseInteroperabilityGateInterface  $releaseGate,
        private QueuePublisher                        $queuePublisher,
        private string                                $configuredBaseUrl,
        private string                                $configuredBasePath,
    ) {
    }

    public function start(
        SiteActorDraft    $draft,
        CanonicalOrigin   $origin,
        CanonicalBasePath $basePath,
        ?int              $now = null,
    ): ActivationReadinessAttempt {
        if ($this->stateRepository->lifecycleState() !== FederationLifecycleState::INSTALLED) {
            throw new \DomainException('ActivityPub federation has already frozen a public identity.');
        }

        $timestamp = $now ?? time();
        $actor     = $this->provisioner->provisionOrUpdate($draft, $timestamp);
        $results   = [
            $this->canonicalConfigurationCheck($origin, $basePath, $actor),
            $this->tlsTransportCheck(),
            $this->privateSecretCheck($actor),
            $this->databaseSchemaCheck(),
            $this->rsaRoundTripCheck($actor),
            $this->releaseGate->check(),
        ];
        $localChecksPassed = array_filter(
            $results,
            static fn(ActivationCheckResult $result): bool => !$result->passed,
        ) === [];
        $attempt = $this->attemptRepository->create(
            $this->publicIdGenerator->generate(),
            $actor->id,
            $origin,
            $basePath,
            $results,
            $localChecksPassed,
            $timestamp,
            $timestamp + self::ATTEMPT_TTL_SECONDS,
        );
        if ($localChecksPassed) {
            $this->queuePublisher->publish(
                $attempt->id,
                ActivationReadinessQueueHandler::CODE,
                ['step' => 0],
                $timestamp,
            );
        }

        return $attempt;
    }

    private function canonicalConfigurationCheck(
        CanonicalOrigin   $origin,
        CanonicalBasePath $basePath,
        LocalActor        $actor,
    ): ActivationCheckResult {
        try {
            $configuredOrigin = $this->configuredOrigin();
            $configuredPath   = new CanonicalBasePath($this->configuredBasePath);
            if (!hash_equals($configuredOrigin->value, $origin->value)) {
                throw new \DomainException('The proposed origin does not match Register http.base_url.');
            }

            if (!hash_equals($configuredPath->value, $basePath->value)) {
                throw new \DomainException('The proposed base path does not match Register http.base_path.');
            }

            if (!$this->urlHasOrigin($actor->profileUrl, $origin)) {
                throw new \DomainException('The actor profile page must use the canonical ActivityPub origin.');
            }

            return $this->passed(
                ActivationReadinessCheck::CANONICAL_HTTPS_ORIGIN,
                'Canonical identity will use ' . $origin->value . $basePath->value . '.',
            );
        } catch (\Throwable $exception) {
            return $this->failed(ActivationReadinessCheck::CANONICAL_HTTPS_ORIGIN, $exception);
        }
    }

    private function tlsTransportCheck(): ActivationCheckResult
    {
        try {
            if (!\function_exists('curl_init') || !\function_exists('curl_version')) {
                throw new \RuntimeException('The PHP cURL extension is required for OpenSSL-independent HTTPS transport.');
            }

            $version   = curl_version();
            $protocols = $version['protocols'] ?? null;
            if (!\is_array($protocols) || !\in_array('https', $protocols, true)) {
                throw new \RuntimeException('The installed libcurl does not advertise HTTPS support.');
            }

            return $this->passed(
                ActivationReadinessCheck::TLS_TRANSPORT,
                'PHP cURL uses libcurl ' . (\is_string($version['version'] ?? null) ? $version['version'] : 'with HTTPS') . '.',
            );
        } catch (\Throwable $exception) {
            return $this->failed(ActivationReadinessCheck::TLS_TRANSPORT, $exception);
        }
    }

    private function privateSecretCheck(LocalActor $actor): ActivationCheckResult
    {
        try {
            $key = $this->currentKey($actor);
            $privateKey = $this->keyVault->decrypt($key->publicId, $key->encryptedPrivateKey);
            try {
                if (!str_starts_with($privateKey, '-----BEGIN PRIVATE KEY-----')) {
                    throw new \RuntimeException('The protected private key cannot be recovered.');
                }
            } finally {
                sodium_memzero($privateKey);
            }

            return $this->passed(
                ActivationReadinessCheck::PRIVATE_SECRET_STORAGE,
                'The private master secret decrypts the site actor key.',
            );
        } catch (\Throwable $exception) {
            return $this->failed(ActivationReadinessCheck::PRIVATE_SECRET_STORAGE, $exception);
        }
    }

    private function databaseSchemaCheck(): ActivationCheckResult
    {
        try {
            foreach (ActivityPubSchema::tables() as $table) {
                if (!$this->dbLayer->tableExists($table)) {
                    throw new \RuntimeException('The ActivityPub table ' . $table . ' is missing.');
                }
            }

            if ($this->stateRepository->state()->profileVersion !== ActivityPubSchema::PROFILE_VERSION) {
                throw new \RuntimeException('The ActivityPub database profile is not current.');
            }

            return $this->passed(
                ActivationReadinessCheck::DATABASE_SCHEMA,
                'Database profile ' . ActivityPubSchema::PROFILE_VERSION . ' is complete.',
            );
        } catch (\Throwable $exception) {
            return $this->failed(ActivationReadinessCheck::DATABASE_SCHEMA, $exception);
        }
    }

    private function rsaRoundTripCheck(LocalActor $actor): ActivationCheckResult
    {
        try {
            $key        = $this->currentKey($actor);
            $privateKey = $this->keyVault->decrypt($key->publicId, $key->encryptedPrivateKey);
            $challenge  = random_bytes(32);
            try {
                $signature = $this->rsaCrypto->sign($privateKey, $challenge);
            } finally {
                sodium_memzero($privateKey);
            }

            if (!$this->rsaCrypto->verify($key->publicKeyPem, $challenge, $signature)) {
                throw new \RuntimeException('The pure-PHP RSA signature round trip failed.');
            }

            return $this->passed(
                ActivationReadinessCheck::RSA_ROUND_TRIP,
                'RSA-SHA256 PKCS#1 v1.5 signing and verification succeeded.',
            );
        } catch (\Throwable $exception) {
            return $this->failed(ActivationReadinessCheck::RSA_ROUND_TRIP, $exception);
        }
    }

    private function configuredOrigin(): CanonicalOrigin
    {
        $parts = parse_url(trim($this->configuredBaseUrl));
        if (!\is_array($parts) || !\is_string($parts['host'] ?? null)) {
            throw new \DomainException('Register http.base_url is not a usable absolute URL.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        return new CanonicalOrigin($scheme . '://' . $parts['host'] . $port);
    }

    private function urlHasOrigin(string $url, CanonicalOrigin $origin): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !\is_string($parts['host'] ?? null)) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        try {
            return hash_equals($origin->value, (new CanonicalOrigin($scheme . '://' . $parts['host'] . $port))->value);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function currentKey(LocalActor $actor): LocalActorKey
    {
        return $this->actorRepository->currentKey($actor->id)
            ?? throw new \RuntimeException('The ActivityPub site actor has no current signing key.');
    }

    private function passed(ActivationReadinessCheck $check, string $detail): ActivationCheckResult
    {
        return new ActivationCheckResult($check, true, $detail);
    }

    private function failed(ActivationReadinessCheck $check, \Throwable $exception): ActivationCheckResult
    {
        $detail = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $exception->getMessage()) ?? '');

        return new ActivationCheckResult(
            $check,
            false,
            mb_substr($detail === '' ? 'The check failed without a diagnostic.' : $detail, 0, 1_000),
        );
    }
}
