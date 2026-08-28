<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Admin\Picture;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PictureStorageQuota
{
    public function __construct(
        private TranslatorInterface $translator,
        private string              $imageDirectory,
        private string              $lockFile,
        private int                 $maximumBytes,
    ) {
        if ($maximumBytes < 1) {
            throw new \InvalidArgumentException('The upload storage quota must be positive.');
        }
    }

    /** @param callable(): void $store */
    public function store(UploadedFile $uploadedFile, callable $store): void
    {
        $uploadBytes = register_call_without_warnings(static fn(): int|false => $uploadedFile->getSize());
        if ($uploadBytes === false || $uploadBytes < 0) {
            throw new \RuntimeException(
                'Unable to determine the uploaded file size.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $lock = $this->openLockFile();
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException(
                    'Unable to lock the upload storage quota.',
                    Response::HTTP_SERVICE_UNAVAILABLE,
                );
            }

            try {
                $usedBytes = $this->usedBytes();
                if ($usedBytes >= $this->maximumBytes || $uploadBytes > $this->maximumBytes - $usedBytes) {
                    throw new \RuntimeException(
                        $this->translator->trans('Upload storage quota exceeded'),
                        Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                    );
                }

                $store();
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /** @return resource */
    private function openLockFile(): mixed
    {
        $directory = dirname($this->lockFile);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(
                'Unable to create the upload quota lock directory.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if (is_link($directory)) {
            throw new \RuntimeException(
                'The upload quota lock directory must not be a symbolic link.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if (is_link($this->lockFile)) {
            throw new \RuntimeException(
                'The upload quota lock must not be a symbolic link.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $lock = register_call_without_warnings(fn() => fopen($this->lockFile, 'c+b'));
        if ($lock === false) {
            throw new \RuntimeException(
                'Unable to open the upload quota lock.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $handleStat = fstat($lock);
        $pathStat   = register_call_without_warnings(fn(): array|false => lstat($this->lockFile));
        if (
            $handleStat === false
            || $pathStat === false
            || ($handleStat['mode'] & 0170000) !== 0100000
            || $handleStat['dev'] !== $pathStat['dev']
            || $handleStat['ino'] !== $pathStat['ino']
        ) {
            fclose($lock);
            throw new \RuntimeException(
                'The upload quota lock is not a regular file.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if (!register_call_without_warnings(fn(): bool => chmod($this->lockFile, 0600))) {
            fclose($lock);
            throw new \RuntimeException(
                'Unable to protect the upload quota lock.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $lock;
    }

    private function usedBytes(): int
    {
        if (!file_exists($this->imageDirectory)) {
            return 0;
        }

        if (!is_dir($this->imageDirectory) || is_link($this->imageDirectory)) {
            throw new \RuntimeException(
                'The upload storage path is not a regular directory.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->imageDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        $usedBytes = 0;
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }

            $fileBytes = register_call_without_warnings(static fn(): int|false => $file->getSize());
            if ($fileBytes === false || $fileBytes < 0) {
                throw new \RuntimeException(
                    'Unable to determine the upload storage usage.',
                    Response::HTTP_SERVICE_UNAVAILABLE,
                );
            }

            if ($usedBytes >= $this->maximumBytes || $fileBytes >= $this->maximumBytes - $usedBytes) {
                return $this->maximumBytes;
            }

            $usedBytes += $fileBytes;
        }

        return $usedBytes;
    }
}
