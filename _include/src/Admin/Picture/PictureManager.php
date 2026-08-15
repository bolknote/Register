<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Picture;

use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\Form\FormParams;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\AdminYard\Translator;
use S2\Cms\AdminYard\CustomTemplateRenderer;
use S2\Cms\Framework\Exception\AccessDeniedException;
use S2\Cms\Image\ThumbnailGenerator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class PictureManager
{
    private const array EXTENSIONS_FOR_PREVIEW = ['avif', 'bmp', 'gif', 'jpeg', 'jpg', 'png', 'webp'];

    public function __construct(
        private readonly Translator              $translator,
        private readonly CustomTemplateRenderer  $customTemplateRenderer,
        private readonly SettingStorageInterface $settingStorage,
        private readonly PictureFileNameHelper   $fileNameHelper,
        private readonly string                  $basePath,
        private string                           $imageDir, // filesystem
    ) {
        $this->imageDir = rtrim($imageDir, '/');
    }

    public function getThumbnailResponse(string $file, int $maxSize = 100, float $maxZoom = 2.0): Response
    {
        if ($maxSize < 1 || $maxZoom <= 0.0) {
            throw new \InvalidArgumentException('Thumbnail dimensions must be positive.');
        }

        $filename = $this->imageDir . $file;

        $image = ThumbnailGenerator::createImageFromFile($filename);
        $sx    = imagesx($image);
        $sy    = imagesy($image);

        $originalSize = max($sx, $sy);

        $zoom = min((float)$maxSize / (float)$originalSize, $maxZoom);

        $thumbnail = imagecreatetruecolor($maxSize, $maxSize);
        if (!$thumbnail instanceof \GdImage) {
            throw new \RuntimeException('Unable to create a thumbnail image.');
        }

        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $white = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
        if ($white === false) {
            throw new \RuntimeException('Unable to allocate a thumbnail color.');
        }

        imagefilledrectangle($thumbnail, 0, 0, $maxSize, $maxSize, $white);
        imagecolortransparent($thumbnail, $white);

        $dst_width  = (int)((float)$sx * $zoom);
        $dst_height = (int)((float)$sy * $zoom);
        $dst_x      = intdiv(max(0, $maxSize - $dst_width), 2);
        $dst_y      = max(0, $maxSize - $dst_height);
        // TODO chess-like background for transparent images
        // imagefilledrectangle($thumbnail, $dst_x, $dst_y, $dst_x + $dst_width, $dst_y + $dst_height, imagecolorallocatealpha($thumbnail, 255, 0, 0, 0));
        // imagealphablending($thumbnail, true);
        imagecopyresampled($thumbnail, $image, $dst_x, $dst_y, 0, 0, $dst_width, $dst_height, $sx, $sy);

        ob_start();
        if (\function_exists('imageavif')) {
            $contentType = 'image/avif';
            imageavif($thumbnail);
        } else {
            $contentType = 'image/jpeg';
            imagejpeg($thumbnail);
        }

        $content = ob_get_clean();
        if (!\is_string($content)) {
            throw new \RuntimeException('Unable to render the thumbnail.');
        }

        return new Response($content, Response::HTTP_OK, ['Content-Type' => $contentType]);
    }

    /**
     * @return array<mixed>
     */
    public function getDirContentRecursive(string $dir): array
    {
        $dirHandle = opendir($this->imageDir . $dir);
        if ($dirHandle === false) {
            throw new \RuntimeException($this->translator->trans('Directory not open', ['{{ dir }}' => $this->imageDir . $dir]), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $output = [];
        $dirs   = [];

        while (($item = readdir($dirHandle)) !== false) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (is_dir($this->imageDir . $dir . '/' . $item)) {
                $dirs[] = $item;
            }
        }

        closedir($dirHandle);

        sort($dirs);

        foreach ($dirs as $item) {
            $output[] = [
                'data'     => $item,
                'attr'     => [
                    'data-path'       => $dir . '/' . $item,
                    'data-csrf-token' => $this->getFolderCsrfToken($dir . '/' . $item),
                ],
                'children' => $this->getDirContentRecursive($dir . '/' . $item)
            ];
        }

        if ($dir === '') {
            return [
                'data'     => $this->translator->trans('Pictures'),
                'attr'     => [
                    'id'              => 'node_1',
                    'data-path'       => '',
                    'data-csrf-token' => $this->getFolderCsrfToken(''),
                ],
                'children' => $output,
            ];
        }

        return $output;
    }


    public function createSubfolder(string $path, string $name): string
    {
        if (file_exists($this->imageDir . $path . '/' . $name)) {
            $i = 1;
            while (file_exists($this->imageDir . $path . '/' . $name . $i)) {
                ++$i;
            }

            $name .= $i;
        }

        $concurrentDirectory = $this->imageDir . $path . '/' . $name;
        if (!mkdir($concurrentDirectory) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException($this->translator->trans('Error creating folder', ['{{ dir }}' => $this->imageDir . $path . '/' . $name]), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        chmod($this->imageDir . $path . '/' . $name, 0755);

        return $name;
    }

    public function deleteFolder(string $dir, bool $deleteRoot = true): void
    {
        $fullDir = $this->imageDir . $dir;
        $dirHandle = s2_call_without_warnings(static fn() => opendir($fullDir));
        if ($dirHandle === false) {
            return;
        }

        while (true) {
            $item = readdir($dirHandle);
            if ($item === false) {
                break;
            }

            if ($item === '.' || $item === '..') {
                continue;
            }

            if (is_dir($fullDir . '/' . $item) || !s2_call_without_warnings(static fn(): bool => unlink($fullDir . '/' . $item))) {
                $this->deleteFolder($dir . '/' . $item);
            }
        }

        closedir($dirHandle);

        if ($deleteRoot) {
            s2_call_without_warnings(static fn(): bool => rmdir($fullDir));
        }
    }

    public function deleteFile(string $path): void
    {
        if (file_exists($this->imageDir . $path)) {
            s2_call_without_warnings(fn(): bool => unlink($this->imageDir . $path));
        }
    }

    public function renameFolder(string $path, string $newName): string
    {
        $parentPath = $this->s2_dirname($path);

        $newFullName = $this->imageDir . $parentPath . '/' . $newName;
        if (file_exists($newFullName)) {
            throw new \RuntimeException($this->translator->trans('Rename file exists', ['{{ dir }}' => $newName]), Response::HTTP_CONFLICT);
        }

        $oldFullName = $this->imageDir . $path;
        if (!is_dir($oldFullName)) {
            throw new \RuntimeException($this->translator->trans('Directory not found', ['{{ dir }}' => $oldFullName]), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!rename($oldFullName, $newFullName)) {
            throw new \RuntimeException($this->translator->trans('Rename error'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $parentPath . '/' . $newName;
    }

    public function renameFile(string $path, string $newName): string
    {
        $parentPath = $this->s2_dirname($path);

        $newFullPath = $this->imageDir . $parentPath . '/' . $newName;
        if (file_exists($newFullPath)) {
            throw new \RuntimeException($this->translator->trans('Rename file exists', ['{{ dir }}' => $newName]), Response::HTTP_CONFLICT);
        }

        $oldFullPath = $this->imageDir . $path;
        $this->fileNameHelper->assertSafeFile($oldFullPath, $newName);
        // TODO check if $oldFullPath exists
        if (!rename($oldFullPath, $newFullPath)) {
            throw new \RuntimeException($this->translator->trans('Rename error'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $newName;
    }

    public function moveFolder(string $sourcePath, string $destPath): string
    {
        $fullSourcePath = $this->imageDir . $sourcePath;
        $fullDestPath   = $this->imageDir . $destPath . '/' . $this->s2_basename($sourcePath);

        if (file_exists($fullDestPath)) {
            throw new \RuntimeException($this->translator->trans('Move file exists', ['{{ dir }}' => $fullDestPath]), Response::HTTP_CONFLICT);
        }

        // TODO check if $fullSourcePath exists
        if (!rename($fullSourcePath, $fullDestPath)) {
            throw new \RuntimeException($this->translator->trans('Move error'), Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $destPath . '/' . $this->s2_basename($sourcePath);
    }

    /**
     * @param array<mixed> $fileNames
     */
    public function moveFiles(string $sourcePath, string $destPath, array $fileNames): void
    {
        $skippedFiles = [];
        foreach ($fileNames as $fileName) {
            $fileName       = $this->s2_basename($fileName);
            $fullSourcePath = $this->imageDir . $sourcePath . '/' . $fileName;
            $fullDestPath   = $this->imageDir . $destPath . '/' . $fileName;

            if (file_exists($fullDestPath)) {
                $skippedFiles[] = $fileName;
                continue;
            }

            if (!rename($fullSourcePath, $fullDestPath)) {
                throw new \RuntimeException($this->translator->trans('Move error'), Response::HTTP_SERVICE_UNAVAILABLE);
            }
        }

        if (\count($skippedFiles) > 0) {
            throw new \RuntimeException($this->translator->trans('Move file exists', ['{{ dir }}' => implode(', ', $skippedFiles)]), Response::HTTP_CONFLICT);
        }
    }

    /**
     * @return array<mixed>
     */
    public function getFiles(string $dir): array
    {
        $displayPreview = \function_exists('imagetypes');

        clearstatcache();

        if (!is_dir($this->imageDir . $dir)) {
            return ['message' => 'Invalid directory'];
        }

        $dirHandle = opendir($this->imageDir . $dir);
        if ($dirHandle === false) {
            return ['message' => '<p>' . $this->translator->trans('Directory not open', ['{{ dir }}' => $this->imageDir . $dir]) . '</p>'];
        }

        $files = [];
        while (($item = readdir($dirHandle)) !== false) {
            if (
                $item === '.'
                || $item === '..'
                || is_dir($this->imageDir . $dir . '/' . $item)
                || !$this->fileNameHelper->isAllowedExtension($item)
            ) {
                continue;
            }

            $files[] = $item;
        }

        closedir($dirHandle);

        sort($files);

        $output = [];
        foreach ($files as $item) {
            $bits = '';
            $dimensions = '';
            if (str_contains($item, '.') && \in_array(pathinfo($item, PATHINFO_EXTENSION), self::EXTENSIONS_FOR_PREVIEW, true)) {
                $imageInfo = getimagesize($this->imageDir . $dir . '/' . $item);
                if ($imageInfo !== false) {
                    $dimensions = $imageInfo[0] . '*' . $imageInfo[1];
                    $bits       = ($imageInfo['bits'] ?? 0) * ($imageInfo['channels'] ?? 1);
                }
            }

            $fileSize = filesize($this->imageDir . $dir . '/' . $item);
            $modifiedAt = filemtime($this->imageDir . $dir . '/' . $item);
            $cacheBuster = $modifiedAt === false ? 0 : $modifiedAt;
            $output[] = [
                'data' => [
                    'title' => $item,
                    'icon'  => $displayPreview && $dimensions !== '' ? $this->basePath . '/_admin/ajax.php?action=preview&file=' . urlencode($dir . '/' . $item) . '&nocache=' . $cacheBuster : 'no-preview'
                ],
                'attr' => [
                    'data-fname' => $item,
                    'data-dim'   => $dimensions,
                    'data-bits'  => $bits,
                    'data-fsize' => $this->customTemplateRenderer->friendlyFilesize($fileSize !== false ? $fileSize : 0)
                ]
            ];
        }

        return \count($output) > 0 ? $output : ['message' => $this->translator->trans('Empty directory')];
    }

    public function processUploadedFile(UploadedFile $uploadedFile, string $path, bool $createDir): string
    {
        $filename = $uploadedFile->getClientOriginalName();
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $errorMessage = $this->translator->trans('Upload error ' . $uploadedFile->getError());
            $error        = $filename !== '' ? sprintf($this->translator->trans('Upload file error'), $filename, $errorMessage) : $errorMessage;
            throw new \RuntimeException($error);
        }

        if (!$uploadedFile->isValid()) {
            $errorMessage = $this->translator->trans('Is upload file error');
            $errors       = $filename !== '' ? sprintf($this->translator->trans('Upload file error'), $filename, $errorMessage) : $errorMessage;
            throw new \RuntimeException($errors);
        }

        $sourceFilename = $this->fileNameHelper->normalizeFileName($filename);
        $this->fileNameHelper->assertSafeUploadedFile($uploadedFile, $sourceFilename);

        do {
            $filename = $this->fileNameHelper->generateStorageFileName($sourceFilename);
        } while (is_file($this->imageDir . $path . '/' . $filename));

        if ($createDir) {
            $this->ensureDirExists($this->imageDir . $path);
        }

        $uploadedFile->move($this->imageDir . $path, $filename);
        chmod($this->imageDir . $path . '/' . $filename, 0644);

        return $path . '/' . $filename;
    }

    public function processUploadedFileWithReservedName(UploadedFile $uploadedFile, string $path, string $filename, bool $createDir): string
    {
        $originalName = $uploadedFile->getClientOriginalName();
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $errorMessage = $this->translator->trans('Upload error ' . $uploadedFile->getError());
            $error        = $originalName !== '' ? sprintf($this->translator->trans('Upload file error'), $originalName, $errorMessage) : $errorMessage;
            throw new \RuntimeException($error);
        }

        if (!$uploadedFile->isValid()) {
            $errorMessage = $this->translator->trans('Is upload file error');
            $errors       = $originalName !== '' ? sprintf($this->translator->trans('Upload file error'), $originalName, $errorMessage) : $errorMessage;
            throw new \RuntimeException($errors);
        }

        $normalized = $this->fileNameHelper->normalizeFileName($filename);
        if ($normalized !== $filename) {
            throw new \RuntimeException('Invalid reserved file name.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->fileNameHelper->assertSafeUploadedFile($uploadedFile, $filename);

        if ($createDir) {
            $this->ensureDirExists($this->imageDir . $path);
        }

        if (is_file($this->imageDir . $path . '/' . $filename)) {
            throw new \RuntimeException('File already exists.', Response::HTTP_CONFLICT);
        }

        $uploadedFile->move($this->imageDir . $path, $filename);
        chmod($this->imageDir . $path . '/' . $filename, 0644);

        return $path . '/' . $filename;
    }



    /**
     * @return array<mixed>
     */
    public function getImageInfo(string $fileName): array
    {
        if (!\function_exists('getimagesize')) {
            return [];
        }

        $imageInfo = getimagesize($this->imageDir . $fileName);
        return $imageInfo !== false ? $imageInfo : [];
    }

    public function getFolderCsrfToken(string $path): string
    {
        $formParams = new FormParams(
            'PictureManager',
            [],
            $this->settingStorage,
            FieldConfig::ACTION_DELETE,
            ['scope' => 'folder', 'path' => $this->getFolderTokenKey($path)],
        );

        return $formParams->getCsrfToken();
    }

    public function assertFolderCsrfToken(string $path, string $csrfToken): void
    {
        if ($csrfToken === '' || !hash_equals($this->getFolderCsrfToken($path), $csrfToken)) {
            throw new AccessDeniedException('Invalid CSRF token!');
        }
    }

    public function assertFileCsrfToken(string $filePath, string $csrfToken): void
    {
        $this->assertFolderCsrfToken($this->s2_dirname($filePath), $csrfToken);
    }

    private function getFolderTokenKey(string $path): string
    {
        $fullPath = $this->imageDir . $path;
        clearstatcache(false, $fullPath);

        $realPath = realpath($fullPath);
        if ($realPath === false) {
            $realPath = $fullPath;
        }

        $inode = s2_call_without_warnings(static fn(): int|false => fileinode($fullPath));
        if ($inode === false) {
            return $realPath;
        }

        return 'inode:' . $inode;
    }

    private function s2_basename(string $dir): string
    {
        return false !== ($pos = strrpos($dir, '/')) ? substr($dir, $pos + 1) : $dir;
    }

    private function s2_dirname(string $dir): string
    {
        return preg_replace('#/[^/]*$#', '', $dir) ?? $dir;
    }

    private function ensureDirExists(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $warning = null;
        set_error_handler(function (int $_errno, string $errstr, string $_file, int $_line) use (&$warning): bool {
            $warning = $errstr;
            return true;
        });
        $created = mkdir($dir, 0755, true);
        restore_error_handler();

        if (!$created && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir) . ($warning !== null ? ' (' . $warning . ')' : ''));
        }

        chmod($dir, 0755);
    }
}
