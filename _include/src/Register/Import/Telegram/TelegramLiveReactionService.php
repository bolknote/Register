<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Register\Import\ExternalImportMapRepository;

/** Reconciles absolute anonymous counts and personal reaction changes exactly once. */
final readonly class TelegramLiveReactionService
{
    public function __construct(private TelegramImportService $importer, private ExternalImportMapRepository $maps, private \PDO $pdo)
    {
    }

    /** @param array<string, mixed> $event */
    public static function validate(array $event, TelegramLiveImportConfig $config): void
    {
        if (!\in_array($event['chat_id'] ?? null, [$config->discussionChatId, $config->channelChatId], true)
            || !\in_array($event['type'] ?? null, ['count', 'change'], true)
        ) {
            throw new \UnexpectedValueException('The reaction chat or event type is invalid.');
        }

        foreach (['message_id', 'date', 'update_id'] as $field) {
            if (!\is_int($event[$field] ?? null) || $event[$field] <= 0 || $event[$field] > 9_007_199_254_740_991) {
                throw new \UnexpectedValueException('The reaction identity is invalid.');
            }
        }

        if ($event['type'] === 'count') {
            self::reactionList($event['reactions'] ?? null, true);
        } else {
            if (!\is_string($event['actor'] ?? null) || preg_match('/^(?:user[1-9][0-9]*|chat-?[1-9][0-9]*)$/D', $event['actor']) !== 1
                || \strlen($event['actor']) > 32
            ) {
                throw new \UnexpectedValueException('The reaction actor is invalid.');
            }

            self::reactionList($event['old_reaction'] ?? null, false);
            self::reactionList($event['new_reaction'] ?? null, false);
        }
    }

    /** @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function import(array $event): array
    {
        $scope = -(int)$event['chat_id'] - 1_000_000_000_000;
        $messageId = (int)$event['message_id'];
        $target = $this->importer->liveReactionTarget($scope, $messageId);
        if ($target === null) {
            return ['success' => false, 'error' => 'missing_target'];
        }

        $stateScope = (string)$target['scope'];
        $identity = $target['message_id'] . ':' . $target['type'];
        $version = [(int)$event['date'], (int)$event['update_id']];
        $startedTransaction = $this->beginImportTransaction();
        try {
            $current = $this->countMap($this->importer->liveReactionCounts($target));
            $stateMap = $this->maps->find('telegram', $stateScope, 'reaction_state', $identity);
            $state = $this->sourceData($stateMap);
            // A later ZIP import can replace the baseline. Start a new actor epoch then.
            if (($state['aggregate_hash'] ?? '') !== $this->countsHash($current)) {
                $state = ['counts' => $current, 'epoch' => bin2hex(random_bytes(16)), 'baseline' => [0, 0]];
            }

            $counts = (array)($state['counts'] ?? []);
            $baseline = (array)($state['baseline'] ?? [0, 0]);
            if ($event['type'] === 'count') {
                if ($version <= (array)($state['latest'] ?? [0, 0])) {
                    if ($startedTransaction) {
                        $this->pdo->commit();
                    }

                    return ['success' => true, 'stale' => true];
                }

                $counts = self::reactionList($event['reactions'], true);
                $state['baseline'] = $version;
                $state['epoch'] = bin2hex(random_bytes(16));
            } else {
                if ($version <= $baseline) {
                    if ($startedTransaction) {
                        $this->pdo->commit();
                    }

                    return ['success' => true, 'stale' => true];
                }

                $actorId = $identity . ':' . $event['actor'];
                $actorMap = $this->maps->find('telegram', $stateScope, 'reaction_actor', $actorId);
                $actor = $this->sourceData($actorMap);
                if (($actor['epoch'] ?? '') !== ($state['epoch'] ?? null)) {
                    $actor = [];
                }

                $before = $this->contribution($actor);
                if ($actor === [] || $version < (array)$actor['first']) {
                    $actor['first'] = $version;
                    $actor['old'] = self::reactionList($event['old_reaction'], false);
                }

                if (!isset($actor['last']) || $version > (array)$actor['last']) {
                    $actor['last'] = $version;
                    $actor['new'] = self::reactionList($event['new_reaction'], false);
                }

                $after = $this->contribution($actor);
                foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                    $descriptor = $after[$key] ?? $before[$key];
                    $delta = (int)($after[$key]['count'] ?? 0) - (int)($before[$key]['count'] ?? 0);
                    $counts[$key] = [...$descriptor, 'count' => (int)($counts[$key]['count'] ?? 0) + $delta];
                }

                $actor['epoch'] = $state['epoch'];
                $this->maps->store('telegram', $stateScope, 'reaction_actor', $actorId, $target['type'], $target['id'],
                    hash('sha256', json_encode($actor, JSON_THROW_ON_ERROR)), $actor, time());
            }

            ksort($counts);
            $positive = array_filter($counts, static fn(array $row): bool => (int)$row['count'] > 0);
            $changes = $this->importer->synchronizeLiveReactions($target, array_values($positive), $scope, $messageId);
            $state['counts'] = $counts; // Keep temporary negative contributions for reordered events.
            $latest = (array)($state['latest'] ?? [0, 0]);
            $state['latest'] = $version > $latest ? $version : $latest;
            $state['aggregate_hash'] = $this->countsHash($positive);
            $this->maps->store('telegram', $stateScope, 'reaction_state', $identity, $target['type'], $target['id'],
                hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), $state, time());
            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return ['success' => true, 'changes' => $changes];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function beginImportTransaction(): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }

        $this->pdo->beginTransaction();
        return true;
    }

    /** @param array<string, mixed>|null $mapping
     * @return array<string, mixed>
     */
    private function sourceData(?array $mapping): array
    {
        $data = $mapping['source_data'] ?? null;
        return \is_array($data) ? $data : [];
    }

    /** @return array<string, array<string, mixed>> */
    private static function reactionList(mixed $items, bool $withCounts): array
    {
        if (!\is_array($items) || !array_is_list($items) || \count($items) > 128) {
            throw new \UnexpectedValueException('The reaction list is invalid.');
        }

        $result = [];
        foreach ($items as $item) {
            $reaction = $withCounts && \is_array($item) ? ($item['type'] ?? null) : $item;
            if (!\is_array($reaction)) {
                throw new \UnexpectedValueException('The reaction is invalid.');
            }

            $type = $reaction['type'] ?? null;
            if ($type === 'emoji' && \is_string($reaction['emoji'] ?? null) && $reaction['emoji'] !== ''
                && mb_strlen($reaction['emoji']) <= 16 && preg_match('/[\x00-\x20\x7f<>]/u', $reaction['emoji']) === 0
            ) {
                $key = 'emoji:' . $reaction['emoji'];
                $row = ['type' => 'emoji', 'emoji' => $reaction['emoji']];
            } elseif ($type === 'custom_emoji' && \is_string($reaction['custom_emoji_id'] ?? null)
                && preg_match('/^[1-9][0-9]{0,24}$/D', $reaction['custom_emoji_id']) === 1
            ) {
                $key = 'custom:' . $reaction['custom_emoji_id'];
                $row = ['type' => 'custom_emoji', 'custom_emoji_id' => $reaction['custom_emoji_id'], 'emoji' => '✦'];
            } elseif ($type === 'paid') {
                $key = 'paid';
                $row = ['type' => 'paid', 'emoji' => '⭐'];
            } else {
                throw new \UnexpectedValueException('The reaction type is invalid.');
            }

            $count = $withCounts ? ($item['total_count'] ?? null) : 1;
            if (!\is_int($count) || $count <= 0 || $count > 2_147_483_647 || isset($result[$key])) {
                throw new \UnexpectedValueException('The reaction count is invalid.');
            }

            $result[$key] = [...$row, 'count' => $count];
        }

        ksort($result);
        return $result;
    }

    /** @param list<array<string, mixed>> $reactions
     * @return array<string, array<string, mixed>>
     */
    private function countMap(array $reactions): array
    {
        $result = [];
        foreach ($reactions as $row) {
            $custom = $row['custom_emoji_id'] ?? $row['document_id'] ?? null;
            $type = ($row['type'] ?? '') === 'paid' ? 'paid' : ($custom !== null ? 'custom_emoji' : 'emoji');
            $descriptor = ['type' => $type, 'emoji' => (string)($row['emoji'] ?? '✦')];
            $key = $type === 'paid' ? 'paid' : ($custom !== null ? 'custom:' . $custom : 'emoji:' . $descriptor['emoji']);
            if ($custom !== null) {
                $descriptor['custom_emoji_id'] = (string)$custom;
            }

            $previous = $result[$key]['count'] ?? 0;
            $result[$key] = [...$descriptor, 'count' => $previous + (int)$row['count']];
        }

        ksort($result);
        return $result;
    }

    /** @param array<string, mixed> $actor
     * @return array<string, array<string, mixed>>
     */
    private function contribution(array $actor): array
    {
        $result = (array)($actor['new'] ?? []);
        foreach ((array)($actor['old'] ?? []) as $key => $row) {
            if (!\is_array($row)) {
                throw new \UnexpectedValueException('The saved reaction actor is invalid.');
            }

            $result[$key] = [...$row, 'count' => (int)($result[$key]['count'] ?? 0) - 1];
        }

        return $result;
    }

    /** @param array<string, array<string, mixed>> $counts */
    private function countsHash(array $counts): string
    {
        $values = [];
        foreach ($counts as $key => $row) {
            if ((int)$row['count'] > 0) {
                $values[$key] = (int)$row['count'];
            }
        }

        ksort($values);
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
