<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Comment;

use Register\Core\Comment\CommentHtml;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Shared presentation data for public and administration deletion controls. */
final class CommentUndoView
{
    /** @return array<string, mixed> */
    public static function payload(Comment $comment, string $token, TranslatorInterface $translator): array
    {
        return [
            'undo_token' => $token,
            'undo_comment_id' => $comment->id,
            'undo_context' => self::context($comment->id, $comment->name, $translator),
            'undo_excerpt' => self::excerpt($comment->text),
            'undo_expires_at' => (int)explode('.', $token, 2)[0] * 1000,
            'undo_server_time' => (int)floor(microtime(true) * 1000.0),
            'undo_labels' => self::labels($translator),
            'message' => $translator->trans('Comment deleted with undo period'),
        ];
    }

    public static function context(int $id, string $author, TranslatorInterface $translator): string
    {
        return $translator->trans('Deleted comment context', ['%number%' => $id, '%author%' => $author]);
    }

    public static function excerpt(string $text): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', CommentHtml::plainText($text, false)) ?? '');

        return mb_substr($plain, 0, 160) . (mb_strlen($plain) > 160 ? '…' : '');
    }

    /** @return array<string, string> */
    public static function labels(TranslatorInterface $translator): array
    {
        $labels = [];
        foreach ([
            'deleted' => 'Comment deleted', 'restore' => 'Undo comment deletion',
            'restoring' => 'Restoring comment', 'restored' => 'Comment restored',
            'expired' => 'Comment undo expired', 'remaining' => 'Comment undo remaining',
            'history' => 'Recent comment deletions', 'collapse' => 'Collapse comment deletions',
            'network_error' => 'Comment undo network error', 'server_error' => 'Comment undo server error',
            'session_error' => 'Comment moderation token expired', 'changed' => 'Comment changed before undo',
            'already_restored' => 'Comment already restored', 'progress' => 'Comments restored progress',
            'restore_all' => 'Restore all deleted comments',
            'open' => 'Open restored comment', 'close' => 'Close',
        ] as $key => $message) {
            $labels[$key] = $translator->trans($message);
        }

        return $labels;
    }

    public static function errorMessage(string $code, TranslatorInterface $translator): string
    {
        return $translator->trans(match ($code) {
            'undo_expired' => 'Comment undo expired',
            'undo_already_restored' => 'Comment already restored',
            default => 'Comment changed before undo',
        });
    }
}
