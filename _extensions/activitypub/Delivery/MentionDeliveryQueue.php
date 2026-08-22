<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Delivery;

use Register\Core\Queue\QueuePublisher;

/** Persists deferred actor discovery for a single immutable activity Mention. */
final readonly class MentionDeliveryQueue
{
    public const string CODE = 'register_activitypub_mention';

    public function __construct(private QueuePublisher $queuePublisher)
    {
    }

    public function schedule(
        int     $activityId,
        int     $localActorId,
        string  $remoteActorUrl,
        ?int    $availableAt = null,
    ): void {
        if ($activityId < 1 || $localActorId < 1) {
            throw new \InvalidArgumentException('An ActivityPub Mention discovery job has invalid local identifiers.');
        }

        $this->validateActorUrl($remoteActorUrl);
        $this->queuePublisher->publishIfAbsent(
            self::jobId($activityId, $remoteActorUrl),
            self::CODE,
            $this->payload($activityId, $localActorId, $remoteActorUrl),
            $availableAt,
        );
    }

    /** Replaces the currently executing generation so the generic consumer retains this job. */
    public function reschedule(int $activityId, int $localActorId, string $remoteActorUrl, int $availableAt): void
    {
        if ($activityId < 1 || $localActorId < 1 || $availableAt < 1) {
            throw new \InvalidArgumentException('An ActivityPub Mention retry job is invalid.');
        }

        $this->validateActorUrl($remoteActorUrl);
        $this->queuePublisher->publish(
            self::jobId($activityId, $remoteActorUrl),
            self::CODE,
            $this->payload($activityId, $localActorId, $remoteActorUrl),
            $availableAt,
        );
    }

    public static function jobId(int $activityId, string $remoteActorUrl): string
    {
        if ($activityId < 1 || $remoteActorUrl === '') {
            throw new \InvalidArgumentException('An ActivityPub Mention discovery job id is invalid.');
        }

        return 'activitypub-mention-' . $activityId . '-' . substr(hash('sha256', $remoteActorUrl), 0, 24);
    }

    private function validateActorUrl(string $url): void
    {
        $parts = parse_url($url);
        if (\strlen($url) > 2_048
            || !\is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new \InvalidArgumentException('An ActivityPub Mention actor must be bounded credential-free HTTPS.');
        }
    }

    /** @return array{activity_id: int, local_actor_id: int, remote_actor_url: string} */
    private function payload(int $activityId, int $localActorId, string $remoteActorUrl): array
    {
        return [
            'activity_id'      => $activityId,
            'local_actor_id'   => $localActorId,
            'remote_actor_url' => $remoteActorUrl,
        ];
    }
}
