<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

use Register\Media\MediaMutationTracker;

/** Stores small, re-encoded images in the site's existing picture storage. */
final readonly class TelegramAvatarStorage
{
    public const int MAX_BYTES = 524_288;

    public function __construct(private string $imageDirectory, private MediaMutationTracker $mutations)
    {
    }

    /** @return array{storage_key: string, content_hash: string, mime_type: string, width: int, height: int, byte_size: int} */
    public function store(string $bytes): array
    {
        if ($bytes === '' || \strlen($bytes) > self::MAX_BYTES) {
            throw new \UnexpectedValueException('The Telegram avatar is too large or empty.');
        }

        $info = register_call_without_warnings(static fn(): array|false => getimagesizefromstring($bytes));
        if (!\is_array($info) || !\in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] <= 0 || $info[1] <= 0 || $info[0] > 1024 || $info[1] > 1024
        ) {
            throw new \UnexpectedValueException('The Telegram avatar must be a small raster image.');
        }

        $input = register_call_without_warnings(static fn(): \GdImage|false => imagecreatefromstring($bytes));
        if (!$input instanceof \GdImage) {
            throw new \UnexpectedValueException('The Telegram avatar cannot be decoded.');
        }

        $scale = min(1.0, 256.0 / (float)max($info[0], $info[1]));
        $width = max(1, (int)round((float)$info[0] * $scale));
        $height = max(1, (int)round((float)$info[1] * $scale));
        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('The Telegram avatar canvas cannot be created.');
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagecopyresampled($image, $input, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        ob_start();
        try {
            if (!imagepng($image)) {
                throw new \RuntimeException('The Telegram avatar cannot be encoded.');
            }

            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if (!\is_string($content) || $content === '' || \strlen($content) > self::MAX_BYTES) {
            throw new \UnexpectedValueException('The encoded Telegram avatar exceeds its limit.');
        }

        $hash = hash('sha256', $content);
        $key = 'telegram/avatars/' . substr($hash, 0, 2) . '/' . $hash . '.png';
        $this->mutations->run(function () use ($key, $hash, $content): void {
            $directory = rtrim($this->imageDirectory, '/');
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('The picture directory is unavailable.');
            }

            foreach (['telegram', 'avatars', substr($hash, 0, 2)] as $segment) {
                $directory .= '/' . $segment;
                if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0755) && !is_dir($directory))) {
                    throw new \RuntimeException('The Telegram avatar directory is unavailable.');
                }
            }

            $path = rtrim($this->imageDirectory, '/') . '/' . $key;
            if (is_link($path)) {
                throw new \RuntimeException('The Telegram avatar cannot be a symbolic link.');
            }

            if (is_file($path) && hash_file('sha256', $path) === $hash) {
                return;
            }

            $temporary = tempnam($directory, '.avatar-');
            if (!\is_string($temporary)) {
                throw new \RuntimeException('The Telegram avatar cannot be staged.');
            }

            try {
                if (file_put_contents($temporary, $content, LOCK_EX) !== \strlen($content)
                    || !chmod($temporary, 0644) || !rename($temporary, $path)
                ) {
                    throw new \RuntimeException('The Telegram avatar cannot be stored.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        });

        return ['storage_key' => $key, 'content_hash' => $hash, 'mime_type' => 'image/png',
            'width' => $width, 'height' => $height, 'byte_size' => \strlen($content)];
    }
}
