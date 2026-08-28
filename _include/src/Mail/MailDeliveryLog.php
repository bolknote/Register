<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

/** Bounded privacy-minimized JSONL log. Recipient addresses and message bodies never enter it. */
final readonly class MailDeliveryLog
{
    public const string HASH_SECRET_KEY = 'REGISTER_EXTENSION_MAIL_LOG_SALT';

    private const int MAX_LOG_BYTES = 5_000_000;

    public function __construct(
        private string $logFile,
        #[\SensitiveParameter]
        private string $hashSecret,
    ) {
        if (\strlen($hashSecret) < 32) {
            throw new \InvalidArgumentException('The mail log hash secret is too short.');
        }
    }

    public function accepted(MailMessage $message, MailDelivery $delivery): void
    {
        $this->append([
            'at'             => gmdate(\DateTimeInterface::ATOM),
            'type'           => $message->type,
            'recipient'      => $this->recipientHash($message->recipientEmail),
            'status'         => 'accepted',
            'transport'      => $delivery->transport,
            'duration_ms'    => round($delivery->durationMs, 1),
            'message_id'     => mb_substr($delivery->messageId, 0, 255),
            'error_code'     => null,
            'error'          => null,
        ]);
    }

    public function failed(
        MailMessage $message,
        string      $transport,
        float       $durationMs,
        ?int        $errorCode,
        string      $error,
    ): void {
        $this->append([
            'at'             => gmdate(\DateTimeInterface::ATOM),
            'type'           => $message->type,
            'recipient'      => $this->recipientHash($message->recipientEmail),
            'status'         => 'failed',
            'transport'      => mb_substr($transport, 0, 32),
            'duration_ms'    => round(max(0.0, $durationMs), 1),
            'message_id'     => null,
            'error_code'     => $errorCode,
            'error'          => mb_substr($error, 0, 500),
        ]);
    }

    /** @return list<string> */
    public function lines(): array
    {
        if (!is_file($this->logFile) || is_link($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return \is_array($lines) ? $lines : [];
    }

    /** @param array<string, mixed> $record */
    private function append(array $record): void
    {
        $line = json_encode(
            $record,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
        $directory = dirname($this->logFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the mail log directory.');
        }

        if (is_link($this->logFile)) {
            throw new \RuntimeException('The mail log must not be a symbolic link.');
        }

        $handle = fopen($this->logFile, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the mail log.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the mail log.');
            }

            fseek($handle, 0, SEEK_END);
            $size = ftell($handle);
            if (\is_int($size) && $size >= self::MAX_LOG_BYTES) {
                ftruncate($handle, 0);
                rewind($handle);
            }

            if (fwrite($handle, $line) === false || !fflush($handle)) {
                throw new \RuntimeException('Unable to append the mail log.');
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (!chmod($this->logFile, 0600)) {
            throw new \RuntimeException('Unable to protect the mail log.');
        }
    }

    private function recipientHash(string $email): string
    {
        return substr(hash_hmac('sha256', mb_strtolower(trim($email)), $this->hashSecret), 0, 20);
    }
}
