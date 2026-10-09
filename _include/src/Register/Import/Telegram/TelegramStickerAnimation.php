<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

/** TGS contains gzip-compressed Lottie vectors, never executable code or remote assets. */
final class TelegramStickerAnimation
{
    public static function decode(string $bytes): string
    {
        if (\strlen($bytes) > 262_144 || !\function_exists('gzdecode')) {
            throw new \UnexpectedValueException('The animated Telegram sticker cannot be decoded.');
        }

        $json = register_call_without_warnings(static fn(): string|false => gzdecode($bytes, 2_000_000));
        if (!\is_string($json)) {
            throw new \UnexpectedValueException('The animated Telegram sticker is invalid.');
        }

        $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($data) || !\is_array($data['layers'] ?? null) || \count($data['layers']) > 1024) {
            throw new \UnexpectedValueException('The sticker has no valid vector animation.');
        }

        foreach (['w', 'h', 'fr', 'ip', 'op'] as $field) {
            if (!\is_int($data[$field] ?? null) && !\is_float($data[$field] ?? null)) {
                throw new \UnexpectedValueException('The sticker dimensions or timing are invalid.');
            }

            if (!is_finite((float)$data[$field])) {
                throw new \UnexpectedValueException('The sticker dimensions or timing are invalid.');
            }
        }

        if ($data['w'] <= 0 || $data['w'] > 1024 || $data['h'] <= 0 || $data['h'] > 1024
            || $data['fr'] <= 0 || $data['fr'] > 60 || $data['ip'] < 0
            || $data['op'] <= $data['ip'] || $data['op'] - $data['ip'] > 600
        ) {
            throw new \UnexpectedValueException('The sticker dimensions or duration exceed the limits.');
        }

        self::rejectExternalAssets($data);
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<mixed> $node */
    private static function rejectExternalAssets(array $node): void
    {
        foreach ($node as $key => $value) {
            if (\in_array($key, ['u', 'p', 'fPath'], true) && \is_string($value) && $value !== '') {
                throw new \UnexpectedValueException('A Telegram sticker must not load external assets.');
            }

            if (\is_array($value)) {
                self::rejectExternalAssets($value);
            }
        }
    }
}
