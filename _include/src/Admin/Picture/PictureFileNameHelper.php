<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Picture;

use S2\AdminYard\Translator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

readonly class PictureFileNameHelper
{
    public const int MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

    /** @var list<string> */
    private const array FORBIDDEN_EXTENSION_SEGMENTS = [
        'asp', 'aspx', 'bash', 'bat', 'cgi', 'cmd', 'com', 'config', 'css', 'exe', 'htaccess', 'htm',
        'html', 'htpasswd', 'inc', 'ini', 'jar', 'js', 'jsp', 'mjs', 'phar', 'php', 'php2', 'php3',
        'php4', 'php5', 'php6', 'php7', 'php8',
        'pht', 'phtm', 'phtml', 'pl', 'py', 'rb', 'sh', 'shtml', 'svg', 'svgz', 'user.ini', 'xht',
        'xhtml', 'xml', 'xsl', 'xslt', 'wasm',
    ];

    /** @var array<string, non-empty-list<string>> */
    private const array MIME_TYPES_BY_EXTENSION = [
        '7z'   => ['application/x-7z-compressed'],
        'avi'  => ['video/avi', 'video/x-msvideo'],
        'avif' => ['image/avif', 'image/avif-sequence'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'csv'  => ['application/csv', 'text/csv', 'text/plain'],
        'doc'  => ['application/cdfv2', 'application/msword', 'application/vnd.ms-office', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'flv'  => ['video/x-flv'],
        'gif'  => ['image/gif'],
        'ico'  => ['image/vnd.microsoft.icon', 'image/x-icon'],
        'jpeg' => ['image/jpeg'],
        'jpg'  => ['image/jpeg'],
        'mkv'  => ['audio/x-matroska', 'video/x-matroska'],
        'mov'  => ['video/quicktime'],
        'mp3'  => ['audio/mp3', 'audio/mpeg'],
        'mp4'  => ['audio/mp4', 'video/mp4'],
        'mpeg' => ['video/mpeg'],
        'mpg'  => ['video/mpeg'],
        'odp'  => ['application/vnd.oasis.opendocument.presentation', 'application/zip', 'application/x-zip-compressed'],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip', 'application/x-zip-compressed'],
        'odt'  => ['application/vnd.oasis.opendocument.text', 'application/zip', 'application/x-zip-compressed'],
        'ogg'  => ['application/ogg', 'audio/ogg', 'video/ogg'],
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'ppt'  => ['application/cdfv2', 'application/vnd.ms-office', 'application/vnd.ms-powerpoint', 'application/x-ole-storage'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/x-zip-compressed'],
        'rar'  => ['application/vnd.rar', 'application/x-rar', 'application/x-rar-compressed'],
        'rtf'  => ['application/rtf', 'text/plain', 'text/rtf'],
        'txt'  => ['text/plain'],
        'wav'  => ['audio/vnd.wave', 'audio/wav', 'audio/x-wav'],
        'webm' => ['audio/webm', 'video/webm'],
        'webp' => ['image/webp'],
        'xls'  => ['application/cdfv2', 'application/vnd.ms-excel', 'application/vnd.ms-office', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'],
        'zip'  => ['application/x-zip', 'application/x-zip-compressed', 'application/zip'],
    ];

    public function __construct(
        private Translator $translator,
        private string     $allowedExtensions,
    ) {
    }

    public function normalizeFileName(string $filename): string
    {
        $filename = mb_strtolower($this->baseName($filename));
        $filename = str_replace("\0", '', $filename);
        while (str_contains($filename, '..')) {
            $filename = str_replace('..', '', $filename);
        }

        return $filename;
    }

    public function assertAllowedExtension(string $filename): void
    {
        if (!$this->isAllowedExtension($filename)) {
            $extension = $this->getExtension($filename);
            $errorMessage = $this->translator->trans('Forbidden extension', ['{{ ext }}' => $extension]);
            $error        = $filename !== '' ? \sprintf($this->translator->trans('Upload file error'), $filename, $errorMessage) : $errorMessage;
            throw new \RuntimeException($error, Response::HTTP_FORBIDDEN);
        }
    }

    public function isAllowedExtension(string $filename): bool
    {
        if (trim($this->allowedExtensions) === '' || $filename === '' || str_starts_with($filename, '.')) {
            return false;
        }

        $extension = $this->getExtension($filename);
        if ($extension === '' || $this->containsForbiddenExtensionSegment($filename)) {
            return false;
        }

        $allowedExtensions = preg_split('/\s+/', mb_strtolower(trim($this->allowedExtensions)), -1, PREG_SPLIT_NO_EMPTY);

        return \is_array($allowedExtensions) && \in_array($extension, $allowedExtensions, true);
    }

    public function assertSafeUploadedFile(UploadedFile $uploadedFile, string $filename): void
    {
        $this->assertSafeFile($uploadedFile->getPathname(), $filename);
    }

    public function assertSafeFile(string $path, string $filename): void
    {
        $this->assertAllowedExtension($filename);

        $fileSize = s2_call_without_warnings(static fn(): int|false => filesize($path));
        if ($fileSize === false) {
            throw new \RuntimeException('Unable to determine the uploaded file size.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($fileSize > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('The uploaded file exceeds the application size limit.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $extension       = $this->getExtension($filename);
        $allowedMimeTypes = self::MIME_TYPES_BY_EXTENSION[$extension] ?? null;
        if ($allowedMimeTypes === null) {
            throw new \RuntimeException('The uploaded file type is not supported securely.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $mimeType = $this->detectMimeType($path);
        if (!\in_array($mimeType, $allowedMimeTypes, true)) {
            throw new \RuntimeException('The uploaded file content does not match its extension.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if (str_starts_with($mimeType, 'image/')) {
            $imageInfo = s2_call_without_warnings(static fn(): array|false => getimagesize($path));
            $imageMime = \is_array($imageInfo) ? mb_strtolower($imageInfo['mime']) : '';
            if ($imageMime === '' || !\in_array($imageMime, $allowedMimeTypes, true)) {
                throw new \RuntimeException('The uploaded image cannot be decoded safely.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            }
        }
    }

    public function incrementCopySuffix(string $filename): string
    {
        return preg_replace_callback('#(?:|_copy|_copy\((\d+)\))(?=(?:\.[^\.]*)?$)#', static function (array $match): string {
            if ($match[0] === '') {
                return '_copy';
            }

            if ($match[0] === '_copy') {
                return '_copy(2)';
            }

            return '_copy(' . ((int)($match[1] ?? 1) + 1) . ')';
        }, $filename, 1) ?? throw new \RuntimeException('Unable to increment the file copy suffix.');
    }

    private function baseName(string $dir): string
    {
        return false !== ($pos = strrpos($dir, '/')) ? substr($dir, $pos + 1) : $dir;
    }

    private function getExtension(string $filename): string
    {
        $dotPos = strrpos($filename, '.');

        return $dotPos === false ? '' : mb_strtolower(substr($filename, $dotPos + 1));
    }

    private function containsForbiddenExtensionSegment(string $filename): bool
    {
        $segments = explode('.', mb_strtolower($filename));
        array_shift($segments);

        foreach ($segments as $segment) {
            if (\in_array($segment, self::FORBIDDEN_EXTENSION_SEGMENTS, true)) {
                return true;
            }
        }

        return false;
    }

    private function detectMimeType(string $path): string
    {
        if (!class_exists(\finfo::class)) {
            throw new \RuntimeException('The Fileinfo extension is required for secure uploads.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = s2_call_without_warnings(static fn(): string|false => $fileInfo->file($path));
        if (!\is_string($mimeType) || $mimeType === '') {
            throw new \RuntimeException('Unable to determine the uploaded file type.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        return mb_strtolower(trim(explode(';', $mimeType, 2)[0]));
    }
}
