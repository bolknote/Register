<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\ActorKind;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Infrastructure\ActivityPubSchema;
use Register\Extension\activitypub\Infrastructure\ActivationReadinessRepository;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\PortableDatabaseTransaction;

/** Freezes public identity only after every local, external, signed, and release check passes. */
final readonly class FederationActivationService
{
    private const int MAX_REPORT_AGE_SECONDS = 15 * 60;

    public function __construct(
        private DbLayer                     $dbLayer,
        private FederationStateRepository   $stateRepository,
        private LocalActorRepository         $actorRepository,
        private PortableDatabaseTransaction $transaction,
        private ?ActivationReadinessRepository $attemptRepository = null,
    ) {
    }

    public function activate(ActivationReadinessReport $report, ?int $now = null): LocalActor
    {
        return $this->activateReport($report, null, $now);
    }

    public function activateAttempt(string $attemptId, ?int $now = null): LocalActor
    {
        if (!$this->attemptRepository instanceof ActivationReadinessRepository) {
            throw new \LogicException('The ActivityPub activation attempt repository is unavailable.');
        }

        $timestamp = $now ?? time();
        $attempt   = $this->attemptRepository->find($attemptId);
        if (!$attempt instanceof ActivationReadinessAttempt) {
            throw new \DomainException('The ActivityPub activation attempt is not ready or has expired.');
        }

        if ($attempt->state !== ActivationReadinessState::READY || $attempt->isExpired($timestamp)) {
            throw new \DomainException('The ActivityPub activation attempt is not ready or has expired.');
        }

        $actor = $this->actorRepository->findById($attempt->actorId);
        if (!$actor instanceof LocalActor) {
            throw new \DomainException('The ActivityPub activation attempt actor is missing.');
        }

        return $this->activateReport($attempt->report($actor->publicId), $attemptId, $timestamp);
    }

    private function activateReport(
        ActivationReadinessReport $report,
        ?string                   $attemptId,
        ?int                      $now,
    ): LocalActor
    {
        $timestamp = $now ?? time();
        if ($report->checkedAt > $timestamp + 30 || $report->checkedAt < $timestamp - self::MAX_REPORT_AGE_SECONDS) {
            throw new \DomainException('The ActivityPub activation readiness report has expired.');
        }

        $failures = $report->failures();
        if ($failures !== []) {
            throw new \DomainException('ActivityPub activation is blocked: ' . implode('; ', array_map(
                static fn(ActivationCheckResult $result): string => $result->check->value . ': ' . $result->detail,
                $failures,
            )));
        }

        return $this->transaction->run(function () use ($report, $attemptId, $timestamp): LocalActor {
            $state = $this->stateRepository->state();
            if ($state->lifecycle !== FederationLifecycleState::INSTALLED || $state->canonicalOrigin instanceof \Register\Extension\activitypub\Domain\CanonicalOrigin) {
                throw new \DomainException('The ActivityPub installation has already frozen a public identity.');
            }

            $actor = $this->actorRepository->findByPublicId($report->actorPublicId);
            if (!$actor instanceof LocalActor) {
                throw new \DomainException('The readiness report does not identify the unpublished site actor.');
            }

            if ($actor->kind !== ActorKind::SITE || $actor->state !== LocalActorState::DRAFT) {
                throw new \DomainException('The readiness report does not identify the unpublished site actor.');
            }

            if (!$this->actorRepository->currentKey($actor->id) instanceof \Register\Extension\activitypub\Domain\LocalActorKey) {
                throw new \DomainException('The site actor has no current signing key.');
            }

            if (!$this->actorRepository->activate($actor->id, $timestamp)) {
                throw new \RuntimeException('The ActivityPub site actor activation lost a concurrent state transition.');
            }

            $updated = $this->dbLayer->update(ActivityPubSchema::STATE_TABLE)
                ->set('lifecycle_state', ':active')->setParameter('active', FederationLifecycleState::ACTIVE->value)
                ->set('canonical_origin', ':origin')->setParameter('origin', $report->canonicalOrigin->value)
                ->set('base_path', ':base_path')->setParameter('base_path', $report->basePath->value)
                ->set('site_actor_type', ':actor_type')->setParameter('actor_type', $actor->type->value)
                ->set('activated_at', ':activated_at')->setParameter('activated_at', $timestamp)
                ->set('updated_at', ':updated_at')->setParameter('updated_at', $timestamp)
                ->where('id = :id')->setParameter('id', 'installation')
                ->andWhere('lifecycle_state = :installed')->setParameter('installed', FederationLifecycleState::INSTALLED->value)
                ->andWhere('canonical_origin IS NULL')
                ->execute()
                ->affectedRows()
            ;
            if ($updated !== 1) {
                throw new \RuntimeException('The ActivityPub installation activation lost a concurrent state transition.');
            }

            if ($attemptId !== null
                && (!$this->attemptRepository instanceof ActivationReadinessRepository
                    || !$this->attemptRepository->markActivated($attemptId, $timestamp))
            ) {
                throw new \RuntimeException('The ActivityPub activation attempt changed concurrently.');
            }

            return $this->actorRepository->findByPublicId($actor->publicId)
                ?? throw new \RuntimeException('The activated ActivityPub actor cannot be reloaded.');
        });
    }
}
