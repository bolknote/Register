<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Register\Core\Framework\ControllerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Receives scoped binary chunks from Telegram; the blog makes no outgoing request. */
final readonly class TelegramLiveMediaController implements ControllerInterface
{
    public const string PATH = '/_live/telegram/media';

    public function __construct(private TelegramLiveImportConfig $config, private TelegramMediaUploadStorage $storage)
    {
    }

    #[\Override]
    public function handle(Request $request): JsonResponse
    {
        if (!$this->config->enabled()) {
            return $this->response(['success' => false, 'error' => 'disabled'], 404);
        }

        if (!hash_equals($this->config->token, $request->headers->get('X-Register-Telegram-Token', '') ?? '')) {
            return $this->response(['success' => false, 'error' => 'unauthorized'], 401);
        }

        if (!$request->isMethod('POST')) {
            return $this->response(['success' => false, 'error' => 'method_not_allowed'], 405);
        }

        $content = $request->getContent();
        if (\strlen($content) > TelegramMediaUploadStorage::CHUNK_BYTES) {
            return $this->response(['success' => false, 'error' => 'payload_size'], 413);
        }

        try {
            $probe = $request->getContentTypeFormat() === 'json';
            $data = $probe ? json_decode($content, true, 8, JSON_THROW_ON_ERROR) : [
                'chat_id' => filter_var($request->headers->get('X-Register-Telegram-Chat'), FILTER_VALIDATE_INT),
                'message_id' => filter_var($request->headers->get('X-Register-Telegram-Message'), FILTER_VALIDATE_INT),
                'file_unique_id' => $request->headers->get('X-Register-Telegram-File'),
                'file_size' => filter_var($request->headers->get('X-Register-Telegram-Size'), FILTER_VALIDATE_INT),
                'offset' => filter_var($request->headers->get('X-Register-Telegram-Offset'), FILTER_VALIDATE_INT),
            ];
            if (!\is_array($data) || ($data['chat_id'] ?? null) !== $this->config->discussionExportId()
                || !\is_int($data['message_id'] ?? null) || $data['message_id'] <= 0 || $data['message_id'] > 9_007_199_254_740_991
                || !\is_string($data['file_unique_id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $data['file_unique_id']) !== 1) {
                throw new \UnexpectedValueException('The media identity is invalid.');
            }

            if ($probe) {
                $status = $this->storage->status($data['message_id'], $data['file_unique_id']);
            } else {
                if ($request->headers->get('Content-Type') !== 'application/octet-stream'
                    || !\is_int($data['file_size']) || !\is_int($data['offset'])) {
                    throw new \UnexpectedValueException('The media chunk format is invalid.');
                }

                $status = $this->storage->append($data['message_id'], $data['file_unique_id'], $data['file_size'], $data['offset'], $content);
            }

            return $this->response(['success' => true, ...$status]);
        } catch (\JsonException|\UnexpectedValueException) {
            return $this->response(['success' => false, 'error' => 'invalid_media'], 422);
        } catch (\RuntimeException) {
            return $this->response(['success' => false, 'error' => 'busy'], 503);
        }
    }

    /** @param array<string, mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
