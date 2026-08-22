<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Application;

use s2_extensions\activitypub\Manifest;

/** Refuses public identity creation unless this exact release ships a peer-matrix attestation. */
final readonly class BundledReleaseInteroperabilityGate implements ReleaseInteroperabilityGateInterface
{
    private const array REQUIRED_PEER_FAMILIES = [
        'akkoma',
        'ghost',
        'gotosocial',
        'mastodon',
        'misskey',
        'register',
        'wordpress-activitypub',
        'writefreely',
    ];

    private const array REQUIRED_DATABASE_PROFILES = ['mysql', 'pgsql', 'sqlite'];

    private const array REQUIRED_SCENARIOS = [
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

    private const int MAX_RESULTS_BYTES = 1024 * 1024;

    public function __construct(
        private string $filename = __DIR__ . '/../resources/interoperability-attestation.json',
        private ?string $resultsFilename = null,
    ) {
    }

    #[\Override]
    public function check(): ActivationCheckResult
    {
        $failure = static fn(string $detail): ActivationCheckResult => new ActivationCheckResult(
            ActivationReadinessCheck::RELEASE_INTEROPERABILITY_GATE,
            false,
            $detail,
        );
        if (!is_file($this->filename) || is_link($this->filename)) {
            return $failure('This build has no ActivityPub interoperability attestation.');
        }

        try {
            $body = file_get_contents($this->filename);
            $data = \is_string($body) ? json_decode($body, true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            return $failure('The bundled ActivityPub interoperability attestation is invalid JSON.');
        }

        $keys = \is_array($data) ? array_keys($data) : [];
        $expectedKeys = ['schema', 'module_version', 'protocol_profile', 'suite_sha256', 'completed_at', 'peers'];
        sort($keys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if (!\is_array($data)
            || $keys !== $expectedKeys
            || ($data['schema'] ?? null) !== 1
            || ($data['module_version'] ?? null) !== Manifest::VERSION
            || ($data['protocol_profile'] ?? null) !== Manifest::PROTOCOL_PROFILE
            || !\is_string($data['suite_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $data['suite_sha256']) !== 1
            || !\is_string($data['completed_at'] ?? null)
            || !$this->isRfc3339($data['completed_at'])
            || !\is_array($data['peers'] ?? null)
            || !array_is_list($data['peers'])
        ) {
            return $failure('The bundled ActivityPub interoperability attestation does not match this release.');
        }

        $peers = $data['peers'];
        foreach ($peers as $peer) {
            if (!\is_string($peer)) {
                return $failure('The bundled ActivityPub peer matrix is invalid.');
            }
        }

        sort($peers, SORT_STRING);
        if ($peers !== self::REQUIRED_PEER_FAMILIES) {
            return $failure('The bundled ActivityPub attestation does not cover the required peer matrix.');
        }

        $resultsFilename = $this->resultsFilename
            ?? \dirname($this->filename) . '/interoperability-results.json';
        if (!is_file($resultsFilename) || is_link($resultsFilename)) {
            return $failure('This build has no archived ActivityPub interoperability results.');
        }

        $resultsSize = filesize($resultsFilename);
        if (!\is_int($resultsSize) || $resultsSize < 1 || $resultsSize > self::MAX_RESULTS_BYTES) {
            return $failure('The archived ActivityPub interoperability results have an invalid size.');
        }

        $resultsHash = hash_file('sha256', $resultsFilename);
        if (!\is_string($resultsHash) || !hash_equals($data['suite_sha256'], $resultsHash)) {
            return $failure('The archived ActivityPub interoperability results do not match their attestation.');
        }

        try {
            $resultsBody = file_get_contents($resultsFilename);
            $results = \is_string($resultsBody)
                ? json_decode($resultsBody, true, 32, JSON_THROW_ON_ERROR)
                : null;
        } catch (\JsonException) {
            return $failure('The archived ActivityPub interoperability results are invalid JSON.');
        }

        $resultsError = $this->validateResults($results, $data['completed_at']);
        if ($resultsError !== null) {
            return $failure($resultsError);
        }

        return new ActivationCheckResult(
            ActivationReadinessCheck::RELEASE_INTEROPERABILITY_GATE,
            true,
            'Interoperability profile ' . Manifest::PROTOCOL_PROFILE . ' is attested for this release.',
        );
    }

    private function isRfc3339(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return false;
        }

        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $date       = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        $errors     = \DateTimeImmutable::getLastErrors();

        return $date instanceof \DateTimeImmutable
            && (!\is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d\TH:i:sP') === $normalized;
    }

    private function validateResults(mixed $results, string $completedAt): ?string
    {
        if (!\is_array($results) || array_is_list($results)) {
            return 'The archived ActivityPub interoperability results do not match this release.';
        }

        if (!$this->hasExactKeys($results, [
                'schema',
                'module_version',
                'protocol_profile',
                'completed_at',
                'database_profiles',
                'runtime',
                'peers',
            ])) {
            return 'The archived ActivityPub interoperability results do not match this release.';
        }

        $schema = $this->value($results, 'schema');
        $moduleVersion = $this->value($results, 'module_version');
        $protocolProfile = $this->value($results, 'protocol_profile');
        $resultsCompletedAt = $this->value($results, 'completed_at');
        if ($schema !== 1
            || $moduleVersion !== Manifest::VERSION
            || $protocolProfile !== Manifest::PROTOCOL_PROFILE
            || $resultsCompletedAt !== $completedAt
        ) {
            return 'The archived ActivityPub interoperability results do not match this release.';
        }

        $databases = $this->value($results, 'database_profiles');
        if (!\is_array($databases) || !array_is_list($databases)) {
            return 'The archived ActivityPub database matrix is invalid.';
        }

        foreach ($databases as $database) {
            if (!\is_string($database)) {
                return 'The archived ActivityPub database matrix is invalid.';
            }
        }

        sort($databases, SORT_STRING);
        if ($databases !== self::REQUIRED_DATABASE_PROFILES) {
            return 'The archived ActivityPub results do not cover every database profile.';
        }

        $runtime = $this->value($results, 'runtime');
        if (!\is_array($runtime) || array_is_list($runtime)) {
            return 'The archived ActivityPub results do not prove the supported shared-hosting profile.';
        }

        if (!$this->hasExactKeys($runtime, ['shared_hosting', 'redis', 'external_cron', 'ext_openssl'])
            || $this->value($runtime, 'shared_hosting') !== true
            || $this->value($runtime, 'redis') !== false
            || $this->value($runtime, 'external_cron') !== false
            || $this->value($runtime, 'ext_openssl') !== false
        ) {
            return 'The archived ActivityPub results do not prove the supported shared-hosting profile.';
        }

        $peerResults = $this->value($results, 'peers');
        if (!\is_array($peerResults) || !array_is_list($peerResults)) {
            return 'The archived ActivityPub peer results are invalid.';
        }

        $families = [];
        foreach ($peerResults as $peerResult) {
            if (!\is_array($peerResult) || array_is_list($peerResult)) {
                return 'An archived ActivityPub peer result is invalid.';
            }

            $family = $this->value($peerResult, 'family');
            $implementationVersion = $this->value($peerResult, 'implementation_version');
            $scenarios = $this->value($peerResult, 'scenarios');
            if (!$this->hasExactKeys($peerResult, ['family', 'implementation_version', 'scenarios'])
                || !\is_string($family)
                || !\is_string($implementationVersion)
                || $implementationVersion === ''
                || \strlen($implementationVersion) > 255
                || !\is_array($scenarios)
                || !array_is_list($scenarios)
            ) {
                return 'An archived ActivityPub peer result is invalid.';
            }

            foreach ($scenarios as $scenario) {
                if (!\is_string($scenario)) {
                    return 'An archived ActivityPub scenario result is invalid.';
                }
            }

            sort($scenarios, SORT_STRING);
            if ($scenarios !== self::REQUIRED_SCENARIOS) {
                return 'An archived ActivityPub peer result does not cover every required scenario.';
            }

            $families[] = $family;
        }

        sort($families, SORT_STRING);
        if ($families !== self::REQUIRED_PEER_FAMILIES) {
            return 'The archived ActivityPub results do not cover the required peer matrix.';
        }

        return null;
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $expected
     */
    private function hasExactKeys(array $data, array $expected): bool
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);

        return $keys === $expected;
    }

    /** @param array<mixed> $data */
    private function value(array $data, string $key): mixed
    {
        return $data[$key] ?? null;
    }
}
