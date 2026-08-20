<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use S2\Cms\Admin\Picture\PictureFileNameHelper;
use S2\Cms\Admin\Picture\PictureStorageQuota;
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
            s2_call_without_warnings(static fn(): bool => chmod($directory . '/' . $storedName, 0644));
        });

        return $path . '/' . $storedName;
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

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        $created = s2_call_without_warnings(static fn(): bool => mkdir($directory, 0755, true));
        if (!$created && !is_dir($directory)) {
            throw new \RuntimeException(
                \sprintf('Directory "%s" was not created.', $directory),
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
        s2_call_without_warnings(static fn(): bool => chmod($directory, 0755));
    }
}
