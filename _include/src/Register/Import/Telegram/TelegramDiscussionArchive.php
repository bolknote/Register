<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

/** Parses a Telegram Desktop JSON export of a channel-linked discussion group. */
final class TelegramDiscussionArchive
{
    public const int MAX_BYTES = 25_000_000;

    /** @var array<int, array<string, mixed>> */
    private array $messagesById = [];

    /** @param array<string, mixed> $export */
    private function __construct(
        private readonly array  $export,
        private readonly string $sourceHash,
    ) {
        $messages = $export['messages'] ?? null;
        if (!\is_array($messages) || !array_is_list($messages)) {
            throw new \UnexpectedValueException('Telegram export has no message list.');
        }
        if (!\in_array((string)($export['type'] ?? ''), [
            'private_supergroup',
            'supergroup',
            'private_group',
            'group',
        ], true)) {
            throw new \UnexpectedValueException('Telegram export is not a discussion group.');
        }
        if (\count($messages) > 250_000) {
            throw new \UnexpectedValueException('Telegram export contains too many messages.');
        }

        foreach ($messages as $message) {
            if (!\is_array($message)) {
                throw new \UnexpectedValueException('Telegram export contains a malformed message.');
            }
            $id = self::positiveInt($message['id'] ?? null, 'message ID');
            if (isset($this->messagesById[$id])) {
                throw new \UnexpectedValueException('Telegram message identifiers are duplicated.');
            }
            $this->messagesById[$id] = $message;
        }
        ksort($this->messagesById);
    }

    public static function fromFile(string $path): self
    {
        $size = filesize($path);
        if (!\is_int($size) || $size <= 0 || $size > self::MAX_BYTES) {
            throw new \UnexpectedValueException('Telegram JSON must be a non-empty file smaller than 25 MB.');
        }
        $json = file_get_contents($path);
        if (!\is_string($json)) {
            throw new \RuntimeException('Unable to read the Telegram export.');
        }

        try {
            $export = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('Telegram export is not valid JSON.', 0, $exception);
        }
        if (!\is_array($export)) {
            throw new \UnexpectedValueException('Telegram export root is not an object.');
        }

        return new self($export, hash('sha256', $json));
    }

    /**
     * @param callable(string): ?array{content_id: int, canonical_path: string} $postResolver
     * @param list<string> $siteHosts
     * @return array<string, mixed>
     */
    public function extract(callable $postResolver, array $siteHosts): array
    {
        $siteHosts = self::normaliseHosts($siteHosts);
        if ($siteHosts === []) {
            throw new \InvalidArgumentException('The site host is not configured.');
        }

        $sourceChannelIds = [];
        foreach ($this->messagesById as $message) {
            if (!$this->isForwardedRoot($message)) {
                continue;
            }
            $link = $this->firstLineSiteLink($message, $siteHosts);
            if ($link['url'] !== null) {
                $sourceChannelIds[(string)$message['forwarded_from_id']] = true;
            }
        }
        if ($sourceChannelIds === []) {
            throw new \UnexpectedValueException(
                'No forwarded channel posts with first-line links to this site were found.',
            );
        }

        $allRoots = [];
        foreach ($this->messagesById as $id => $message) {
            if ($this->isForwardedRoot($message)
                && isset($sourceChannelIds[(string)$message['forwarded_from_id']])
            ) {
                $allRoots[$id] = true;
            }
        }

        $acceptedRoots = [];
        $excludedRoots = [];
        $threads = [];
        foreach (array_keys($allRoots) as $rootId) {
            $message = $this->messagesById[$rootId];
            $link = $this->firstLineSiteLink($message, $siteHosts);
            $path = $link['url'] === null ? null : self::normaliseSitePath($link['url'], $siteHosts);
            $post = $path === null ? null : $postResolver($path);
            if (!\is_array($post)) {
                $excludedRoots[$rootId] = [
                    'message_id'         => $rootId,
                    'date_unixtime'      => (int)($message['date_unixtime'] ?? 0),
                    'reason'             => $path === null ? $link['reason'] : 'post_not_found',
                    'url'                => $link['url'],
                    'path'               => $path,
                    'descendant_messages' => 0,
                ];
                continue;
            }

            $acceptedRoots[$rootId] = \count($threads);
            $threads[] = [
                'root_message_id'          => $rootId,
                'root_date_unixtime'       => (int)($message['date_unixtime'] ?? 0),
                'post_url'                 => $link['url'],
                'post_path'                => $path,
                'canonical_path'           => $post['canonical_path'],
                'content_id'               => $post['content_id'],
                'post_reactions'           => $this->normaliseReactions($message['reactions'] ?? []),
                'comments'                 => [],
            ];
        }

        $chatId = self::positiveInt($this->export['id'] ?? null, 'chat ID');
        $siteAuthorIds = $sourceChannelIds;
        $siteAuthorIds['channel' . $chatId] = true;
        $rootResolutionCache = [];
        $unthreaded = [];
        $rejectedDescendants = 0;
        $directComments = 0;
        $nestedComments = 0;
        $editedComments = 0;
        $mediaReferences = 0;

        foreach ($this->messagesById as $messageId => $message) {
            if (($message['type'] ?? null) !== 'message' || isset($allRoots[$messageId])) {
                continue;
            }

            $resolution = $this->resolveRoot($messageId, $allRoots, $rootResolutionCache);
            $rootId = $resolution['root_id'];
            if ($rootId === null) {
                $unthreaded[] = [
                    'message_id'          => $messageId,
                    'reply_to_message_id' => isset($message['reply_to_message_id'])
                        ? (int)$message['reply_to_message_id']
                        : null,
                    'reason'              => $resolution['reason'],
                ];
                continue;
            }
            if (!isset($acceptedRoots[$rootId])) {
                ++$rejectedDescendants;
                if (isset($excludedRoots[$rootId])) {
                    ++$excludedRoots[$rootId]['descendant_messages'];
                }
                continue;
            }

            $parentMessageId = (int)($message['reply_to_message_id'] ?? 0);
            $parentCommentMessageId = $parentMessageId === $rootId ? null : $parentMessageId;
            $parentCommentMessageId === null ? ++$directComments : ++$nestedComments;

            $createdAt = self::positiveInt($message['date_unixtime'] ?? null, 'message timestamp');
            $modifiedAt = (int)($message['edited_unixtime'] ?? 0);
            if ($modifiedAt <= $createdAt) {
                $modifiedAt = 0;
            } else {
                ++$editedComments;
            }
            $authorId = trim((string)($message['from_id'] ?? ''));
            $authorName = trim((string)($message['from'] ?? ''));
            if ($authorName === '') {
                $authorName = 'Telegram user';
            }
            $media = $this->normaliseMedia($message);
            $mediaReferences += \count($media);
            $comment = [
                'message_id'        => $messageId,
                'parent_message_id' => $parentCommentMessageId,
                'date_unixtime'     => $createdAt,
                'edited_unixtime'   => $modifiedAt,
                'author'            => [
                    'id'             => $authorId,
                    'name'           => $authorName,
                    'is_site_author' => isset($siteAuthorIds[$authorId]),
                ],
                'text'              => $this->plainText($message),
                'html'              => $this->messageHtml($message),
                'entities'          => \is_array($message['text_entities'] ?? null)
                    ? $message['text_entities']
                    : [],
                'media'             => $media,
                'reactions'         => $this->normaliseReactions($message['reactions'] ?? []),
            ];
            $comment['source_hash'] = self::commentHash($comment);
            $threads[$acceptedRoots[$rootId]]['comments'][] = $comment;
        }

        ksort($excludedRoots);
        $postReactionGroups = 0;
        $postReactionEvents = 0;
        $commentReactionGroups = 0;
        $commentReactionEvents = 0;
        $uniquePosts = [];
        foreach ($threads as $thread) {
            $threadContentId = $thread['content_id'] ?? null;
            $threadReactions = $thread['post_reactions'] ?? null;
            if (!\is_int($threadContentId) || !\is_array($threadReactions)) {
                throw new \UnexpectedValueException('An extracted Telegram thread is malformed.');
            }
            $uniquePosts[$threadContentId] = true;
            foreach ($threadReactions as $reaction) {
                ++$postReactionGroups;
                $postReactionEvents += (int)$reaction['count'];
            }
            foreach ($thread['comments'] as $comment) {
                foreach ($comment['reactions'] as $reaction) {
                    ++$commentReactionGroups;
                    $commentReactionEvents += (int)$reaction['count'];
                }
            }
        }

        return [
            'source' => [
                'sha256'             => $this->sourceHash,
                'chat_id'            => $chatId,
                'chat_name'          => (string)($this->export['name'] ?? ''),
                'chat_type'          => (string)($this->export['type'] ?? ''),
                'source_channel_ids' => array_keys($sourceChannelIds),
            ],
            'stats' => [
                'source_messages'             => \count($this->messagesById),
                'channel_roots'               => \count($allRoots),
                'accepted_threads'            => \count($threads),
                'accepted_unique_posts'       => \count($uniquePosts),
                'excluded_roots'              => \count($excludedRoots),
                'rejected_descendant_messages' => $rejectedDescendants,
                'unthreaded_messages'         => \count($unthreaded),
                'comments'                    => $directComments + $nestedComments,
                'direct_comments'             => $directComments,
                'nested_comments'             => $nestedComments,
                'edited_comments'             => $editedComments,
                'post_reaction_groups'        => $postReactionGroups,
                'post_reaction_events'        => $postReactionEvents,
                'comment_reaction_groups'     => $commentReactionGroups,
                'comment_reaction_events'     => $commentReactionEvents,
                'media_references'            => $mediaReferences,
            ],
            'threads'          => $threads,
            'excluded_roots'   => array_values($excludedRoots),
            'unthreaded_messages' => $unthreaded,
        ];
    }

    /** @param array<string, mixed> $message */
    private function isForwardedRoot(array $message): bool
    {
        return ($message['type'] ?? null) === 'message'
            && !isset($message['reply_to_message_id'])
            && preg_match('/^channel[1-9][0-9]*$/D', (string)($message['forwarded_from_id'] ?? '')) === 1;
    }

    /**
     * @param array<int, true> $rootIds
     * @param array<int, array{root_id: ?int, reason: string}> $cache
     * @return array{root_id: ?int, reason: string}
     */
    private function resolveRoot(int $messageId, array $rootIds, array &$cache): array
    {
        if (isset($cache[$messageId])) {
            return $cache[$messageId];
        }

        $trail = [];
        $seen = [];
        $currentId = $messageId;
        $result = ['root_id' => null, 'reason' => 'not_in_discussion_thread'];
        while (true) {
            if (isset($rootIds[$currentId])) {
                $result = ['root_id' => $currentId, 'reason' => 'resolved'];
                break;
            }
            if (isset($seen[$currentId])) {
                $result = ['root_id' => null, 'reason' => 'reply_cycle'];
                break;
            }
            $seen[$currentId] = true;
            $trail[] = $currentId;
            $message = $this->messagesById[$currentId] ?? null;
            if (!\is_array($message)) {
                $result = ['root_id' => null, 'reason' => 'missing_reply_target'];
                break;
            }
            if (!isset($message['reply_to_message_id'])) {
                break;
            }
            $currentId = (int)$message['reply_to_message_id'];
        }
        foreach ($trail as $id) {
            $cache[$id] = $result;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $message
     * @param list<string> $siteHosts
     * @return array{url: ?string, reason: string}
     */
    private function firstLineSiteLink(array $message, array $siteHosts): array
    {
        $candidates = [];
        $entities = \is_array($message['text_entities'] ?? null) ? $message['text_entities'] : [];
        foreach ($entities as $entity) {
            if (!\is_array($entity)) {
                continue;
            }
            $text = (string)($entity['text'] ?? '');
            $type = (string)($entity['type'] ?? '');
            if ($type === 'link' || $type === 'text_link') {
                $url = (string)($entity['href'] ?? $text);
                $visibleLabel = preg_replace('/[\p{Cf}\p{Z}\s]+/u', '', $text);
                if ($visibleLabel !== '' && self::normaliseSitePath($url, $siteHosts) !== null) {
                    $candidates[$url] = true;
                }
            }
            if (str_contains($text, "\n")) {
                break;
            }
        }

        if ($candidates === []) {
            $blocks = $message['rich_message']['blocks'] ?? null;
            $firstBlock = \is_array($blocks) && array_is_list($blocks) ? ($blocks[0] ?? null) : null;
            if (\is_array($firstBlock) && ($firstBlock['type'] ?? null) === 'paragraph') {
                foreach (self::richInlineSegments($firstBlock['text'] ?? null) as $segment) {
                    if ($segment['url'] !== null
                        && preg_replace('/[\p{Cf}\p{Z}\s]+/u', '', $segment['text']) !== ''
                        && self::normaliseSitePath($segment['url'], $siteHosts) !== null
                    ) {
                        $candidates[$segment['url']] = true;
                    }
                    if (str_contains($segment['text'], "\n")) {
                        break;
                    }
                }
            }
        }

        if ($candidates === []) {
            return ['url' => null, 'reason' => 'missing_first_line_site_link'];
        }
        if (\count($candidates) !== 1) {
            return ['url' => null, 'reason' => 'ambiguous_first_line_site_link'];
        }

        return ['url' => array_key_first($candidates), 'reason' => 'resolved'];
    }

    /** @return list<array{text: string, url: ?string}> */
    private static function richInlineSegments(mixed $node): array
    {
        if (\is_string($node)) {
            return [['text' => $node, 'url' => null]];
        }
        if (!\is_array($node)) {
            return [];
        }
        if (array_is_list($node)) {
            $segments = [];
            foreach ($node as $child) {
                array_push($segments, ...self::richInlineSegments($child));
            }
            return $segments;
        }

        $type = (string)($node['type'] ?? '');
        if ($type === 'empty') {
            return [];
        }
        if ($type === 'plain') {
            return [['text' => (string)($node['text'] ?? ''), 'url' => null]];
        }
        if ($type === 'text_link' || $type === 'link') {
            $label = implode('', array_column(self::richInlineSegments($node['text'] ?? null), 'text'));
            $url = $type === 'text_link' ? (string)($node['href'] ?? '') : $label;
            return [['text' => $label, 'url' => $url !== '' ? $url : null]];
        }

        return self::richInlineSegments($node['text'] ?? null);
    }

    /** @param list<string> $siteHosts */
    public static function normaliseSitePath(string $url, array $siteHosts): ?string
    {
        $parts = parse_url(trim($url));
        if (!\is_array($parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        if (!\in_array($scheme, ['http', 'https'], true)
            || !\in_array($host, self::normaliseHosts($siteHosts), true)
        ) {
            return null;
        }
        $path = trim(rawurldecode($parts['path'] ?? ''), '/');
        return $path === '' ? null : $path;
    }

    /**
     * @param list<string> $hosts
     * @return list<string>
     */
    private static function normaliseHosts(array $hosts): array
    {
        $result = [];
        foreach ($hosts as $host) {
            $host = strtolower(rtrim(trim($host), '.'));
            if ($host === '' || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
                continue;
            }
            $result[$host] = true;
            $alternate = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
            $result[$alternate] = true;
        }
        return array_keys($result);
    }

    /** @param array<string, mixed> $message */
    private function plainText(array $message): string
    {
        $text = $message['text'] ?? '';
        if (\is_string($text)) {
            return $text;
        }
        if (\is_array($text)) {
            $result = '';
            foreach ($text as $part) {
                $result .= \is_string($part) ? $part : (string)($part['text'] ?? '');
            }
            return $result;
        }

        $segments = self::richInlineSegments($message['rich_message'] ?? null);
        return implode('', array_column($segments, 'text'));
    }

    /** @param array<string, mixed> $message */
    private function messageHtml(array $message): string
    {
        $entities = $message['text_entities'] ?? [];
        if (!\is_array($entities) || $entities === []) {
            return self::textWithBreaks($this->plainText($message));
        }

        $html = '';
        foreach ($entities as $entity) {
            if (!\is_array($entity)) {
                continue;
            }
            $type = (string)($entity['type'] ?? 'plain');
            $text = (string)($entity['text'] ?? '');
            $escaped = self::textWithBreaks($text);
            $html .= match ($type) {
                'bold' => '<strong>' . $escaped . '</strong>',
                'italic' => '<em>' . $escaped . '</em>',
                'underline' => '<u>' . $escaped . '</u>',
                'strikethrough' => '<s>' . $escaped . '</s>',
                'spoiler' => '<span>' . $escaped . '</span>',
                'code' => '<code>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>',
                'pre' => self::preHtml($text, (string)($entity['language'] ?? '')),
                'blockquote' => '<blockquote>' . $escaped . '</blockquote>',
                'link', 'text_link' => self::linkHtml((string)($entity['href'] ?? $text), $escaped),
                default => $escaped,
            };
        }
        return $html;
    }

    private static function textWithBreaks(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return str_replace("\n", "<br>\n", htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    private static function preHtml(string $text, string $language): string
    {
        $class = preg_match('/^[a-z0-9_+.-]+$/Di', $language) === 1
            ? ' class="language-' . htmlspecialchars(strtolower($language), ENT_QUOTES, 'UTF-8') . '"'
            : '';
        return '<pre><code' . $class . '>'
            . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</code></pre>';
    }

    private static function linkHtml(string $url, string $labelHtml): string
    {
        if (!\in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https', 'mailto', 'tel'], true)) {
            return $labelHtml;
        }
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . $labelHtml . '</a>';
    }

    /** @return list<array<string, mixed>> */
    private function normaliseReactions(mixed $source): array
    {
        if (!\is_array($source)) {
            return [];
        }
        $result = [];
        foreach ($source as $reaction) {
            if (!\is_array($reaction) || (int)($reaction['count'] ?? 0) <= 0) {
                continue;
            }
            $item = [
                'type'   => (string)($reaction['type'] ?? ''),
                'count'  => (int)$reaction['count'],
                'recent' => [],
            ];
            if ($item['type'] === 'emoji') {
                $item['emoji'] = (string)($reaction['emoji'] ?? '');
            } elseif ($item['type'] === 'custom_emoji') {
                $item['document_id'] = (string)($reaction['document_id'] ?? '');
            }
            foreach (($reaction['recent'] ?? []) as $recent) {
                if (\is_array($recent)) {
                    $item['recent'][] = [
                        'from'    => (string)($recent['from'] ?? ''),
                        'from_id' => (string)($recent['from_id'] ?? ''),
                        'date'    => (string)($recent['date'] ?? ''),
                    ];
                }
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $message
     * @return list<array<string, mixed>>
     */
    private function normaliseMedia(array $message): array
    {
        $result = [];
        foreach (['photo', 'file'] as $field) {
            $path = $message[$field] ?? null;
            if (!\is_string($path) || trim($path) === '') {
                continue;
            }
            $result[] = [
                'kind'      => $field,
                'path'      => $path,
                'file_name' => (string)($message['file_name'] ?? basename($path)),
                'mime_type' => (string)($message['mime_type'] ?? ''),
            ];
        }
        return $result;
    }

    /** @param array<string, mixed> $comment */
    public static function commentHash(array $comment): string
    {
        $payload = [
            'message_id'        => (int)($comment['message_id'] ?? 0),
            'parent_message_id' => $comment['parent_message_id'] ?? null,
            'date_unixtime'     => (int)($comment['date_unixtime'] ?? 0),
            'edited_unixtime'   => (int)($comment['edited_unixtime'] ?? 0),
            'author'            => $comment['author'] ?? [],
            'text'              => (string)($comment['text'] ?? ''),
            'entities'          => $comment['entities'] ?? [],
            'media'             => $comment['media'] ?? [],
        ];
        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function positiveInt(mixed $value, string $label): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new \UnexpectedValueException('Invalid Telegram ' . $label . '.');
        }
        return $value;
    }
}
