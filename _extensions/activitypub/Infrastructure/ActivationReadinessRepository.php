<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Infrastructure;

use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Application\ActivationCheckResult;
use Register\Extension\activitypub\Application\ActivationReadinessAttempt;
use Register\Extension\activitypub\Application\ActivationReadinessCheck;
use Register\Extension\activitypub\Application\ActivationReadinessState;
use Register\Extension\activitypub\Domain\CanonicalBasePath;
use Register\Extension\activitypub\Domain\CanonicalOrigin;

/** Durable source of truth for the two-phase activation protocol. */
final readonly class ActivationReadinessRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /** @param list<ActivationCheckResult> $results */
    public function create(
        string            $id,
        int               $actorId,
        CanonicalOrigin   $origin,
        CanonicalBasePath $basePath,
        array             $results,
        bool              $localChecksPassed,
        int               $now,
        int               $expiresAt,
    ): ActivationReadinessAttempt {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $id) !== 1 || $actorId < 1 || $now < 1 || $expiresAt <= $now) {
            throw new \InvalidArgumentException('The ActivityPub activation attempt parameters are invalid.');
        }

        $this->dbLayer->update(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE)
            ->set('state', ':superseded')->setParameter('superseded', ActivationReadinessState::SUPERSEDED->value)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->set('completed_at', ':completed_at')->setParameter('completed_at', $now)
            ->where('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->andWhere('state IN (:checking, :ready)')
            ->setParameter('checking', ActivationReadinessState::CHECKING->value)
            ->setParameter('ready', ActivationReadinessState::READY->value)
            ->execute()
        ;

        $state = $localChecksPassed ? ActivationReadinessState::CHECKING : ActivationReadinessState::FAILED;
        $this->dbLayer->insert(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE)
            ->values([
                'id'               => ':id',
                'actor_id'         => ':actor_id',
                'canonical_origin' => ':canonical_origin',
                'base_path'        => ':base_path',
                'state'            => ':state',
                'next_step'        => '0',
                'results_json'     => ':results_json',
                'created_at'       => ':created_at',
                'updated_at'       => ':updated_at',
                'expires_at'       => ':expires_at',
                'completed_at'     => ':completed_at',
            ])
            ->execute([
                'id'               => $id,
                'actor_id'         => $actorId,
                'canonical_origin' => $origin->value,
                'base_path'        => $basePath->value,
                'state'            => $state->value,
                'results_json'     => $this->encodeResults($results),
                'created_at'       => $now,
                'updated_at'       => $now,
                'expires_at'       => $expiresAt,
                'completed_at'     => $localChecksPassed ? null : $now,
            ])
        ;

        return $this->find($id) ?? throw new \RuntimeException('The ActivityPub activation attempt cannot be reloaded.');
    }

    public function find(string $id): ?ActivationReadinessAttempt
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $id) !== 1) {
            return null;
        }

        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE)
            ->where('id = :id')->setParameter('id', $id)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function latest(): ?ActivationReadinessAttempt
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE)
            ->orderBy('created_at DESC, id DESC')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function usableProbe(string $id, string $actorPublicId, int $now): ?ActivationReadinessAttempt
    {
        $attempt = $this->find($id);
        if (!$attempt instanceof ActivationReadinessAttempt) {
            return null;
        }

        if ($attempt->state !== ActivationReadinessState::CHECKING || $attempt->isExpired($now)) {
            return null;
        }

        $actorId = $this->dbLayer->select('id')
            ->from(ActivityPubSchema::ACTOR_TABLE)
            ->where('public_id = :public_id')->setParameter('public_id', $actorPublicId)
            ->execute()
            ->result()
        ;

        return is_numeric($actorId) && (int)$actorId === $attempt->actorId ? $attempt : null;
    }

    /** @param list<ActivationCheckResult> $results */
    public function advance(
        string                   $id,
        int                      $expectedStep,
        array                    $results,
        ActivationReadinessState $nextState,
        int                      $nextStep,
        int                      $now,
    ): bool {
        $attempt = $this->find($id);
        if (!$attempt instanceof ActivationReadinessAttempt) {
            return false;
        }

        if ($attempt->state !== ActivationReadinessState::CHECKING || $attempt->nextStep !== $expectedStep) {
            return false;
        }

        $merged = [];
        foreach ($attempt->results() as $result) {
            $merged[$result->check->value] = $result;
        }

        foreach ($results as $result) {
            $merged[$result->check->value] = $result;
        }

        $completedAt = $nextState === ActivationReadinessState::CHECKING ? null : $now;

        return $this->dbLayer->update(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE)
            ->set('results_json', ':results_json')->setParameter('results_json', $this->encodeResults(array_values($merged)))
            ->set('state', ':state')->setParameter('state', $nextState->value)
            ->set('next_step', ':next_step')->setParameter('next_step', $nextStep)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->set('completed_at', ':completed_at')->setParameter('completed_at', $completedAt)
            ->where('id = :id')->setParameter('id', $id)
            ->andWhere('state = :checking')->setParameter('checking', ActivationReadinessState::CHECKING->value)
            ->andWhere('next_step = :expected_step')->setParameter('expected_step', $expectedStep)
            ->execute()
            ->affectedRows() === 1
        ;
    }

    public function recordSignedProbe(string $id, int $actorId, int $now): bool
    {
        return $this->dbLayer->update(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE)
            ->set('signed_probe_received_at', ':received_at')->setParameter('received_at', $now)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->where('id = :id')->setParameter('id', $id)
            ->andWhere('actor_id = :actor_id')->setParameter('actor_id', $actorId)
            ->andWhere('state = :checking')->setParameter('checking', ActivationReadinessState::CHECKING->value)
            ->andWhere('next_step = 2')
            ->andWhere('expires_at >= :now')->setParameter('now', $now)
            ->execute()
            ->affectedRows() === 1
        ;
    }

    public function markActivated(string $id, int $now): bool
    {
        return $this->dbLayer->update(ActivityPubSchema::ACTIVATION_ATTEMPT_TABLE)
            ->set('state', ':activated')->setParameter('activated', ActivationReadinessState::ACTIVATED->value)
            ->set('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->set('completed_at', ':completed_at')->setParameter('completed_at', $now)
            ->where('id = :id')->setParameter('id', $id)
            ->andWhere('state = :ready')->setParameter('ready', ActivationReadinessState::READY->value)
            ->execute()
            ->affectedRows() === 1
        ;
    }

    /** @param list<ActivationCheckResult> $results */
    private function encodeResults(array $results): string
    {
        return json_encode(array_map(static fn(ActivationCheckResult $result): array => [
            'check'  => $result->check->value,
            'passed' => $result->passed,
            'detail' => $result->detail,
        ], $results), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ActivationReadinessAttempt
    {
        try {
            $decoded = json_decode((string)$row['results_json'], true, 16, JSON_THROW_ON_ERROR);
            if (!\is_array($decoded) || !array_is_list($decoded)) {
                throw new \JsonException('Expected an activation result list.');
            }

            $results = [];
            foreach ($decoded as $entry) {
                if (!\is_array($entry)
                    || !\is_string($entry['check'] ?? null)
                    || !\is_bool($entry['passed'] ?? null)
                    || !\is_string($entry['detail'] ?? null)
                ) {
                    throw new \JsonException('An activation result is invalid.');
                }

                $results[] = new ActivationCheckResult(
                    ActivationReadinessCheck::from($entry['check']),
                    $entry['passed'],
                    $entry['detail'],
                );
            }

            return new ActivationReadinessAttempt(
                (string)$row['id'],
                (int)$row['actor_id'],
                new CanonicalOrigin((string)$row['canonical_origin']),
                new CanonicalBasePath((string)$row['base_path']),
                ActivationReadinessState::from((string)$row['state']),
                (int)$row['next_step'],
                $results,
                $row['signed_probe_received_at'] === null ? null : (int)$row['signed_probe_received_at'],
                (int)$row['created_at'],
                (int)$row['updated_at'],
                (int)$row['expires_at'],
                $row['completed_at'] === null ? null : (int)$row['completed_at'],
            );
        } catch (\JsonException | \ValueError | \InvalidArgumentException $exception) {
            throw new \RuntimeException('A stored ActivityPub activation attempt is invalid.', 0, $exception);
        }
    }
}
