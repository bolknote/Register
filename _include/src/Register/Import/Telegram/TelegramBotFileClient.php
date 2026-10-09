<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Register\Core\HttpClient\HttpClient;
use Register\Core\HttpClient\HttpClientInterface;

final readonly class TelegramBotFileClient implements TelegramFileClientInterface
{
    public const int MAX_BYTES = 20_000_000;

    public function __construct(private HttpClientInterface $http, private string $botToken)
    {
    }

    #[\Override]
    public function download(array $media): ?string
    {
        if ((int)($media['file_size'] ?? 0) > self::MAX_BYTES) {
            return null;
        }

        $fileId = $media['file_id'] ?? null;
        if (!\is_string($fileId) || preg_match('/^[A-Za-z0-9_-]{1,512}$/D', $fileId) !== 1
            || preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D', $this->botToken) !== 1
        ) {
            throw new TelegramMediaDownloadFailed();
        }

        try {
            $response = $this->http->request('POST', 'https://api.telegram.org/bot' . $this->botToken . '/getFile',
                ['Content-Type' => 'application/json'], json_encode(['file_id' => $fileId], JSON_THROW_ON_ERROR),
                [HttpClient::MAX_RESPONSE_BYTES => 16_384, HttpClient::FOLLOW_REDIRECTS => false]);
            $data = json_decode($response->content ?? '', true, 16, JSON_THROW_ON_ERROR);
            $file = \is_array($data) && ($data['ok'] ?? null) === true ? ($data['result'] ?? null) : null;
            if (!$response->isSuccessful() || !\is_array($file)) {
                throw new TelegramMediaDownloadFailed();
            }

            if ((int)($file['file_size'] ?? 0) > self::MAX_BYTES) {
                return null;
            }

            $path = $file['file_path'] ?? null;
            if (!\is_string($path) || preg_match('~^[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+$~D', $path) !== 1
                || str_contains($path, '..')
            ) {
                throw new TelegramMediaDownloadFailed();
            }

            $response = $this->http->request('GET', 'https://api.telegram.org/file/bot' . $this->botToken . '/' . $path,
                options: [HttpClient::MAX_RESPONSE_BYTES => self::MAX_BYTES, HttpClient::FOLLOW_REDIRECTS => false,
                    HttpClient::CONNECT_TIMEOUT => 5, HttpClient::READ_TIMEOUT => 20]);
            $bytes = $response->content;
            $expectedSize = (int)($file['file_size'] ?? $media['file_size'] ?? 0);
            if (!$response->isSuccessful() || !\is_string($bytes) || $bytes === ''
                || \strlen($bytes) > self::MAX_BYTES || ($expectedSize > 0 && \strlen($bytes) !== $expectedSize)
            ) {
                throw new TelegramMediaDownloadFailed();
            }

            return $bytes;
        } catch (\Throwable) {
            // The HTTP client's exceptions may contain the credential-bearing URL.
            throw new TelegramMediaDownloadFailed();
        }
    }
}
