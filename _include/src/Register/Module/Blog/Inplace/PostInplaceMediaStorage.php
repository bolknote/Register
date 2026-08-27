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

/** Stores public-editor media under the historical note-date naming convention. */
final readonly class PostInplaceMediaStorage
{
    private const int MAX_CANONICAL_ORDINAL = 10000;

    private string $mediaDirectory;

    private string $contentDirectory;

    private string $nameLockFile;

    public function __construct(
        private PictureFileNameHelper  $fileNameHelper,
        private PictureStorageQuota    $storageQuota,
        private TranslatorInterface    $translator,
        string                         $mediaDirectory,
        string                         $contentDirectory,
        string                         $cacheDirectory,
    ) {
        $this->mediaDirectory = rtrim($mediaDirectory, '/');
        $this->contentDirectory = $contentDirectory === ''
            ? ''
            : '/' . trim($contentDirectory, '/');
        $this->nameLockFile = rtrim($cacheDirectory, '/') . '/post-media-name.lock';
    }

    public function storeCanonical(
        UploadedFile $uploadedFile,
        int          $publishedAt,
        bool         $retina,
    ): string {
        $originalName = $uploadedFile->getClientOriginalName();
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK || !$uploadedFile->isValid()) {
            throw new \RuntimeException(
                \sprintf($this->translator->trans('Post media upload failed'), $originalName),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $sourceName = $this->fileNameHelper->normalizeFileName($originalName);
        $this->fileNameHelper->assertSafeUploadedFile($uploadedFile, $sourceName);
        $extension = mb_strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
        $date = date('Y.m.d', $publishedAt);
        $directory = $this->mediaDirectory . $this->contentDirectory;
        $this->ensureDirectoryExists($directory);

        return $this->withNameLock(function () use ($uploadedFile, $date, $extension, $retina, $directory): string {
            $storedName = $this->nextName($date, $extension, $retina);
            $this->storageQuota->store($uploadedFile, function () use ($uploadedFile, $directory, $storedName): void {
                $uploadedFile->move($directory, $storedName);
                register_call_without_warnings(static fn(): bool => chmod($directory . '/' . $storedName, 0644));
            });

            return $this->contentDirectory . '/' . $storedName;
        });
    }

    /**
     * Renames a pending upload when the note date changed before it was saved.
     *
     * @return array{from: string, to: string}
     */
    public function redateCanonical(string $storedFile, int $publishedAt): array
    {
        $parts = $this->canonicalParts($storedFile);
        $date = date('Y.m.d', $publishedAt);
        if ($parts['date'] === $date) {
            return ['from' => $storedFile, 'to' => $storedFile];
        }

        return $this->withNameLock(function () use ($storedFile, $parts, $date): array {
            $source = $this->fullPath($storedFile);
            if (!is_file($source)) {
                throw new \RuntimeException('The uploaded media file is missing.', Response::HTTP_SERVICE_UNAVAILABLE);
            }

            $storedName = $this->nextName($date, $parts['extension'], $parts['retina']);
            $targetFile = $this->contentDirectory . '/' . $storedName;
            $target = $this->fullPath($targetFile);
            if (!rename($source, $target)) {
                throw new \RuntimeException('Unable to rename the uploaded media file.', Response::HTTP_SERVICE_UNAVAILABLE);
            }

            register_call_without_warnings(static fn(): bool => chmod($target, 0644));
            return ['from' => $storedFile, 'to' => $targetFile];
        });
    }

    /** @param array{from: string, to: string} $move */
    public function rollbackRedate(array $move): void
    {
        if ($move['from'] === $move['to']) {
            return;
        }

        $this->withNameLock(function () use ($move): void {
            $source = $this->fullPath($move['to']);
            $target = $this->fullPath($move['from']);
            if (is_file($source) && !file_exists($target)) {
                register_call_without_warnings(static fn(): bool => rename($source, $target));
            }
        });
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

        $imageInfo = getimagesize($this->fullPath($storedFile));
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

    /** @return array{date: string, extension: string, retina: bool} */
    private function canonicalParts(string $storedFile): array
    {
        $prefix = preg_quote($this->contentDirectory, '~');
        if (preg_match(
            '~^' . $prefix . '/(?<date>\d{4}\.\d{2}\.\d{2})(?:\.\d+)?(?<retina>@2x)?\.(?<extension>[a-z0-9]+)$~D',
            $storedFile,
            $match,
        ) !== 1) {
            throw new \InvalidArgumentException('Invalid canonical media path.');
        }

        return [
            'date'      => $match['date'],
            'extension' => $match['extension'],
            'retina'    => ($match['retina'] ?? '') === '@2x',
        ];
    }

    private function nextName(string $date, string $extension, bool $retina): string
    {
        $used = [];
        $directory = $this->mediaDirectory . $this->contentDirectory;
        $pattern = '~^' . preg_quote($date, '~') . '(?:\.(?<number>\d+))?(?:@2x)?\.[a-z0-9]+$~D';
        foreach (new \DirectoryIterator($directory) as $item) {
            $match = [];
            if ($item->isLink() || !$item->isFile() || preg_match($pattern, $item->getFilename(), $match) !== 1) {
                continue;
            }

            $number = ($match['number'] ?? '') === '' ? 0 : (int)$match['number'];
            $used[$number] = true;
        }

        for ($index = 0; $index < self::MAX_CANONICAL_ORDINAL; ++$index) {
            if (isset($used[$index])) {
                continue;
            }

            return $date
                . ($index === 0 ? '' : '.' . $index)
                . ($retina ? '@2x' : '')
                . '.' . $extension;
        }

        throw new \RuntimeException('Unable to allocate a historical media name.', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory) && !is_link($directory)) {
            return;
        }

        if (file_exists($directory)) {
            throw new \RuntimeException('The media directory is invalid.', Response::HTTP_SERVICE_UNAVAILABLE);
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

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function withNameLock(\Closure $operation): mixed
    {
        $directory = dirname($this->nameLockFile);
        $this->ensureDirectoryExists($directory);
        $lock = $this->openNameLock();
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock historical media names.', Response::HTTP_SERVICE_UNAVAILABLE);
            }

            return $operation();
        } finally {
            register_call_without_warnings(static fn(): bool => flock($lock, LOCK_UN));
            fclose($lock);
        }
    }

    /** @return resource */
    private function openNameLock(): mixed
    {
        if (is_link($this->nameLockFile)) {
            throw new \RuntimeException('The media-name lock is invalid.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $lock = register_call_without_warnings(fn() => fopen($this->nameLockFile, 'c+b'));
        if ($lock === false) {
            throw new \RuntimeException('Unable to lock historical media names.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $handleStat = fstat($lock);
        $pathStat = register_call_without_warnings(fn(): array|false => lstat($this->nameLockFile));
        if (
            $handleStat === false
            || $pathStat === false
            || ($handleStat['mode'] & 0170000) !== 0100000
            || $handleStat['dev'] !== $pathStat['dev']
            || $handleStat['ino'] !== $pathStat['ino']
        ) {
            fclose($lock);
            throw new \RuntimeException('The media-name lock is invalid.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!register_call_without_warnings(fn(): bool => chmod($this->nameLockFile, 0600))) {
            fclose($lock);
            throw new \RuntimeException('Unable to protect historical media names.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $lock;
    }

    private function fullPath(string $storedFile): string
    {
        $canonicalPrefix = preg_quote($this->contentDirectory, '~');
        $canonical = '~^' . $canonicalPrefix
            . '/\d{4}\.\d{2}\.\d{2}(?:\.\d+)?(?:@2x)?\.[a-z0-9]+$~D';
        $legacy = '~^/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.[a-z0-9]+$~D';
        if (preg_match($canonical, $storedFile) !== 1 && preg_match($legacy, $storedFile) !== 1) {
            throw new \InvalidArgumentException('Invalid stored media path.');
        }

        return $this->mediaDirectory . $storedFile;
    }
}
