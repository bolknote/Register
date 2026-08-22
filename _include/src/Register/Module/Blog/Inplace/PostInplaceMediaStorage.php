<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use Register\Core\Admin\Picture\PictureFileNameHelper;
use Register\Core\Admin\Picture\PictureStorageQuota;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Stores media accepted by the public inplace editor using the common upload safety checks. */
final readonly class PostInplaceMediaStorage
{
    private string $mediaDirectory;

    public function __construct(
        private PictureFileNameHelper  $fileNameHelper,
        private PictureStorageQuota    $storageQuota,
        private TranslatorInterface    $translator,
        string                         $mediaDirectory,
    ) {
        $this->mediaDirectory = rtrim($mediaDirectory, '/');
    }

    public function store(UploadedFile $uploadedFile, string $path): string
    {
        $originalName = $uploadedFile->getClientOriginalName();
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK || !$uploadedFile->isValid()) {
            throw new \RuntimeException(
                \sprintf($this->translator->trans('Post media upload failed'), $originalName),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $sourceName = $this->fileNameHelper->normalizeFileName($originalName);
        $this->fileNameHelper->assertSafeUploadedFile($uploadedFile, $sourceName);

        do {
            $storedName = $this->fileNameHelper->generateStorageFileName($sourceName);
        } while (is_file($this->mediaDirectory . $path . '/' . $storedName));

        $directory = $this->mediaDirectory . $path;
        $this->ensureDirectoryExists($directory);
        $this->storageQuota->store($uploadedFile, function () use ($uploadedFile, $directory, $storedName): void {
            $uploadedFile->move($directory, $storedName);
            register_call_without_warnings(static fn(): bool => chmod($directory . '/' . $storedName, 0644));
        });

        return $path . '/' . $storedName;
    }

    public function normalizeName(string $originalName): string
    {
        return $this->fileNameHelper->normalizeFileName($originalName);
    }

    public function detectMimeType(UploadedFile $uploadedFile): string
    {
        return $this->fileNameHelper->detectMimeType($uploadedFile->getPathname());
    }

    /** @return array<mixed> */
    public function getImageInfo(string $storedFile): array
    {
        if (!\function_exists('getimagesize')) {
            return [];
        }

        $imageInfo = getimagesize($this->mediaDirectory . $storedFile);
        return $imageInfo !== false ? $imageInfo : [];
    }

    public function fileSize(string $storedFile): int
    {
        $size = filesize($this->fullPath($storedFile));
        if ($size === false) {
            throw new \RuntimeException('Unable to read the uploaded media file.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $size;
    }

    public function replace(string $destinationFile, string $sourceFile): void
    {
        $destination = $this->fullPath($destinationFile);
        $source      = $this->fullPath($sourceFile);
        $temporary   = $destination . '.replace-' . bin2hex(random_bytes(8));

        if (!copy($source, $temporary)) {
            throw new \RuntimeException('Unable to replace the uploaded media file.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        register_call_without_warnings(static fn(): bool => chmod($temporary, 0644));
        if (!rename($temporary, $destination)) {
            register_call_without_warnings(static fn(): bool => unlink($temporary));
            throw new \RuntimeException('Unable to replace the uploaded media file.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!register_call_without_warnings(static fn(): bool => unlink($source))) {
            throw new \RuntimeException('Unable to remove the replaced media file.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    public function delete(string $storedFile): void
    {
        $filename = $this->fullPath($storedFile);
        if (!is_file($filename)) {
            return;
        }

        if (!register_call_without_warnings(static fn(): bool => unlink($filename))) {
            throw new \RuntimeException('Unable to remove the unused media file.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        $created = register_call_without_warnings(static fn(): bool => mkdir($directory, 0755, true));
        if (!$created && !is_dir($directory)) {
            throw new \RuntimeException(
                \sprintf('Directory "%s" was not created.', $directory),
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        register_call_without_warnings(static fn(): bool => chmod($directory, 0755));
    }

    private function fullPath(string $storedFile): string
    {
        if (preg_match('~^/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.[a-z0-9]+$~D', $storedFile) !== 1) {
            throw new \InvalidArgumentException('Invalid stored media path.');
        }

        return $this->mediaDirectory . $storedFile;
    }
}
