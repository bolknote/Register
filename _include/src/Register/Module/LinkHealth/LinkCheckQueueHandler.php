<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

use S2\Cms\Queue\QueueExecutionBudget;
use S2\Cms\Queue\QueueHandlerInterface;
use S2\Cms\Queue\QueuePublisher;

final readonly class LinkCheckQueueHandler implements QueueHandlerInterface
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private LinkHealthRepository     $repository,
        private LinkHealthPolicy         $policy,
        private LinkProbeInterface       $httpProbe,
        private HostRequestThrottle      $requestThrottle,
        private LinkHealthResultRecorder $resultRecorder,
        private QueuePublisher           $queuePublisher,
        ?\Closure                        $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** @return non-empty-list<non-empty-string> */
    #[\Override]
    public function codes(): array
    {
        return [LinkQueue::CHECK_CODE];
    }

    #[\Override]
    public function minimumExecutionTime(): float
    {
        return 4.25;
    }

    /** @param array<mixed> $payload */
    #[\Override]
    public function handle(string $id, string $code, array $payload, QueueExecutionBudget $budget): void
    {
        $targetId = $payload['target_id'] ?? null;
        $token    = $payload['token'] ?? null;
        $force    = $payload['force'] ?? false;
        $hasState = array_key_exists('probe', $payload);
        $statePayload = $payload['probe'] ?? null;
        if ($code !== LinkQueue::CHECK_CODE
            || !\is_int($targetId)
            || $targetId < 1
            || $id !== LinkQueue::targetJobId($targetId)
            || ($token !== null && !LinkQueue::isOperationToken($token))
            || !\is_bool($force)
            || ($hasState && !\is_array($statePayload))
            || array_diff_key($payload, ['target_id' => true, 'token' => true, 'force' => true, 'probe' => true]) !== []
        ) {
            throw new \InvalidArgumentException('Invalid link-check job.');
        }

        $target = $this->repository->findTarget($targetId);
        if (!$target instanceof LinkTargetState) {
            return;
        }

        if ($target->kind !== LinkKind::EXTERNAL
            || !$this->repository->hasUsages($targetId)
        ) {
            return;
        }

        if (!$force && \in_array($target->healthStatus, [
            LinkHealthStatus::BROKEN,
            LinkHealthStatus::BLOCKED,
            LinkHealthStatus::IGNORED,
        ], true)) {
            return;
        }

        $state = $hasState
            ? LinkProbeState::fromPayload($statePayload)
            : LinkProbeState::initial($target->url);

        // Jobs created before operation tokens were introduced are upgraded before any network I/O.
        if ($token === null) {
            $this->queuePublisher->publish(
                $id,
                $code,
                LinkQueue::checkPayload($targetId, $force, $state),
                ($this->clock)() + 1,
            );
            return;
        }

        if ($this->repository->probeWasRecorded($token)) {
            return;
        }

        $budget->checkpoint($this->minimumExecutionTime());
        $now     = ($this->clock)();
        $retryAt = $this->requestThrottle->claim($state->url, $now);
        if ($retryAt !== null) {
            $this->queuePublisher->publish(
                $id,
                $code,
                LinkQueue::checkPayload($targetId, $force, $state, $token),
                $retryAt,
            );
            return;
        }

        $step = $this->httpProbe->step($state);
        $now  = ($this->clock)();
        if ($step->nextState instanceof LinkProbeState) {
            $this->queuePublisher->publish(
                $id,
                $code,
                LinkQueue::checkPayload($targetId, $force, $step->nextState, $token),
                $now + 1,
            );
            return;
        }

        $probe = $step->result
            ?? throw new \LogicException('A completed link-probe step has no result.');
        $decision = $this->policy->decide($target, $probe, $now);
        $this->resultRecorder->recordProbe($token, $target, $probe, $decision, $now);
    }
}
