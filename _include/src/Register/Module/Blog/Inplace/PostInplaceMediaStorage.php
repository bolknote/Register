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
use Register\Core\Admin\Picture\UploadedImageDecodeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Stores media accepted by the public inplace editor using the common upload safety checks. */
final readonly class PostInplaceMediaStorage
{
    private const int MAX_BROWSER_PREVIEW_BYTES = 8 * 1024 * 1024;

    private const int MAX_BROWSER_PREVIEW_DIMENSION = 2048;

    private string $mediaDirectory;

    public function __construct(
        private PictureFileNameHelper  $fileNameHelper,
        private PictureStorageQuota    $storageQuota,
        private TranslatorInterface    $translator,
        string                         $mediaDirectory,
    ) {
        $this->mediaDirectory = rtrim($mediaDirectory, '/');
    }

    public function store(UploadedFile $uploadedFile, string $path, bool $hasBrowserPreview = false): string
    {
        $originalName = $uploadedFile->getClientOriginalName();
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK || !$uploadedFile->isValid()) {
            throw new \RuntimeException(
                \sprintf($this->translator->trans('Post media upload failed'), $originalName),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $sourceName = $this->fileNameHelper->normalizeFileName($originalName);
        try {
            $this->fileNameHelper->assertSafeUploadedFile($uploadedFile, $sourceName);
        } catch (UploadedImageDecodeException $decodeException) {
            if (!$hasBrowserPreview) {
                throw $decodeException;
            }

            $this->fileNameHelper->assertSafeBrowserDecodedImage($uploadedFile, $sourceName);
        }

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

    /** @return array{0: int, 1: int} */
    public function validateBrowserPreview(UploadedFile $preview): array
    {
        if ($preview->getError() !== UPLOAD_ERR_OK || !$preview->isValid()) {
            throw new \RuntimeException(
                'The browser image preview is invalid.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $size = register_call_without_warnings(static fn(): int|false => $preview->getSize());
        if ($size === false || $size <= 0 || $size > self::MAX_BROWSER_PREVIEW_BYTES) {
            throw new \RuntimeException(
                'The browser image preview has an invalid size.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $detectedMime = $this->detectMimeType($preview);
        if (!\in_array($detectedMime, ['image/jpeg', 'image/png'], true)) {
            throw new \RuntimeException(
                'The browser image preview must be a JPEG or PNG image.',
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        $info = register_call_without_warnings(
            static fn(): array|false => getimagesize($preview->getPathname()),
        );
        if (!\is_array($info)) {
            throw new \RuntimeException(
                'The browser image preview cannot be decoded safely.',
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        $width  = $info[0];
        $height = $info[1];
        $mime   = mb_strtolower($info['mime']);
        if (
            $mime !== $detectedMime
            || $width <= 0
            || $height <= 0
            || $width > self::MAX_BROWSER_PREVIEW_DIMENSION
            || $height > self::MAX_BROWSER_PREVIEW_DIMENSION
        ) {
            throw new \RuntimeException(
                'The browser image preview cannot be decoded safely.',
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            );
        }

        return [$width, $height];
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
