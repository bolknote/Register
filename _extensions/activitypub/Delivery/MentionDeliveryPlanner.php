<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Delivery;

use s2_extensions\activitypub\Domain\ActivityDeliveryIntent;
use s2_extensions\activitypub\Domain\ModerationAction;
use s2_extensions\activitypub\Domain\RemoteActor;
use s2_extensions\activitypub\Infrastructure\ModerationRuleRepository;
use s2_extensions\activitypub\Infrastructure\RemoteActorRepository;
use s2_extensions\activitypub\Infrastructure\StoredActivityRepresentation;

/** Plans Mention fan-out from immutable object snapshots without publication-time network I/O. */
final readonly class MentionDeliveryPlanner
{
    public function __construct(
        private RemoteActorRepository    $actorRepository,
        private ModerationRuleRepository $moderationRepository,
        private DeliveryPlanner          $deliveryPlanner,
        private MentionDeliveryQueue     $queue,
    ) {
    }

    /**
     * @param non-empty-list<array<string, mixed>> $objectDocuments
     */
    public function plan(
        StoredActivityRepresentation $activity,
        array                        $objectDocuments,
        int                          $now,
    ): int {
        if ($activity->deliveryIntent === ActivityDeliveryIntent::NONE) {
            return 0;
        }

        if ($activity->deliveryIntent !== ActivityDeliveryIntent::FOLLOWERS || $now < 1) {
            throw new \InvalidArgumentException('ActivityPub content Mentions require follower delivery and a valid timestamp.');
        }

        return $this->planRecipients($activity, $this->recipients($objectDocuments), $now);
    }

    /** @param list<string> $actorUrls */
    public function planRecipients(
        StoredActivityRepresentation $activity,
        array                        $actorUrls,
        int                          $now,
    ): int {
        if ($activity->deliveryIntent === ActivityDeliveryIntent::NONE) {
            return 0;
        }

        if ($activity->deliveryIntent !== ActivityDeliveryIntent::FOLLOWERS || $now < 1) {
            throw new \InvalidArgumentException('ActivityPub content Mentions require follower delivery and a valid timestamp.');
        }

        /** @var array<string, array<string, string>> $deliveries */
        $deliveries = [];
        foreach (array_values(array_unique($actorUrls)) as $actorUrl) {
            if (!$this->validActorUrl($actorUrl)) {
                continue;
            }

            $actor = $this->actorRepository->findByUrl($actorUrl);
            if (!$actor instanceof RemoteActor) {
                $this->queue->schedule($activity->id, $activity->actorId, $actorUrl);
                continue;
            }

            if ($actor->state !== 'active'
                || $this->moderationRepository->decision($actor) === ModerationAction::BLOCK
            ) {
                continue;
            }

            $inbox = $actor->sharedInboxUrl ?? $actor->inboxUrl;
            $deliveries[$inbox] ??= [];
            $deliveries[$inbox][$actor->actorUrl] = $actor->actorUrl;
        }

        $inserted = 0;
        ksort($deliveries, SORT_STRING);
        foreach ($deliveries as $inbox => $recipients) {
            ksort($recipients, SORT_STRING);
            $recipientList = array_values($recipients);
            if ($recipientList === []) {
                throw new \LogicException('An ActivityPub Mention delivery group cannot be empty.');
            }

            $inserted += $this->deliveryPlanner->planDirectRecipients(
                $activity,
                $inbox,
                $recipientList,
                $now,
            );
        }

        return $inserted;
    }

    /**
     * @param non-empty-list<array<string, mixed>> $objectDocuments
     * @return list<string>
     */
    public function recipients(array $objectDocuments): array
    {
        $recipients = [];
        foreach ($objectDocuments as $document) {
            $tags = $document['tag'] ?? [];
            $tags = \is_array($tags) && array_is_list($tags) ? $tags : [$tags];
            foreach ($tags as $tag) {
                if (!\is_array($tag) || array_is_list($tag)) {
                    continue;
                }

                $type = $tag['type'] ?? null;
                $href = $tag['href'] ?? null;
                if ($type !== 'Mention' || !\is_string($href) || !$this->validActorUrl($href)) {
                    continue;
                }

                $recipients[$href] = $href;
                if (\count($recipients) >= 64) {
                    break 2;
                }
            }
        }

        ksort($recipients, SORT_STRING);

        return array_values($recipients);
    }

    private function validActorUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \strlen($url) <= 2_048
            && \is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && \is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !str_contains($url, '\\')
            && preg_match('/[\x00-\x20\x7f]/', $url) !== 1;
    }
}
