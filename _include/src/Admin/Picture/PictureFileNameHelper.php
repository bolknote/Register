<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Picture;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class PictureFileNameHelper
{
    public const int MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

    public const int MAX_BATCH_UPLOAD_BYTES = 200 * 1024 * 1024;

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
        private TranslatorInterface $translator,
        private string              $allowedExtensions,
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
        if (
            trim($this->allowedExtensions) === ''
            || $filename === ''
            || str_starts_with($filename, '.')
            || !mb_check_encoding($filename, 'UTF-8')
            || preg_match('~[\\x00-\\x1f\\x7f/\\\\]~', $filename) === 1
            || str_contains($filename, '..')
        ) {
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

    /**
     * Applies every upload check except server-side image decoding.
     *
     * This is reserved for an authenticated editor upload accompanied by a separately validated,
     * browser-rendered preview. Active and mismatched file types are still rejected.
     */
    public function assertSafeBrowserDecodedImage(UploadedFile $uploadedFile, string $filename): void
    {
        $this->assertSafeFileContent($uploadedFile->getPathname(), $filename, false);
    }

    /** @param list<UploadedFile> $uploadedFiles */
    public function assertSafeBatchSize(array $uploadedFiles): void
    {
        $totalBytes = 0;
        foreach ($uploadedFiles as $uploadedFile) {
            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $fileSize = register_call_without_warnings(static fn(): int|false => $uploadedFile->getSize());
            if ($fileSize === false) {
                throw new \RuntimeException('Unable to determine the uploaded file size.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($fileSize > self::MAX_UPLOAD_BYTES) {
                throw new \RuntimeException('The uploaded file exceeds the application size limit.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }

            if ($fileSize > self::MAX_BATCH_UPLOAD_BYTES - $totalBytes) {
                throw new \RuntimeException('The uploaded files exceed the application batch size limit.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }

            $totalBytes += $fileSize;
        }
    }

    public function generateStorageFileName(string $sourceFilename): string
    {
        $normalized = $this->normalizeFileName($sourceFilename);
        $this->assertAllowedExtension($normalized);

        return bin2hex(random_bytes(16)) . '.' . $this->getExtension($normalized);
    }

    public function assertSafeFile(string $path, string $filename): void
    {
        $this->assertSafeFileContent($path, $filename, true);
    }

    private function assertSafeFileContent(string $path, string $filename, bool $requireImageDecode): void
    {
        $this->assertAllowedExtension($filename);

        $fileSize = register_call_without_warnings(static fn(): int|false => filesize($path));
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

        if ($requireImageDecode && str_starts_with($mimeType, 'image/')) {
            $imageInfo = register_call_without_warnings(static fn(): array|false => getimagesize($path));
            $imageMime = \is_array($imageInfo) ? mb_strtolower($imageInfo['mime']) : '';
            if ($imageMime === '' || !\in_array($imageMime, $allowedMimeTypes, true)) {
                throw new UploadedImageDecodeException(
                    'The uploaded image cannot be decoded safely.',
                    Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                );
            }
        }
    }

    private function baseName(string $dir): string
    {
        $dir = str_replace('\\', '/', $dir);

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

    public function detectMimeType(string $path): string
    {
        if (!class_exists(\finfo::class)) {
            throw new \RuntimeException('The Fileinfo extension is required for secure uploads.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = register_call_without_warnings(static fn(): string|false => $fileInfo->file($path));
        if (!\is_string($mimeType) || $mimeType === '') {
            throw new \RuntimeException('Unable to determine the uploaded file type.', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $avifMimeType = $this->detectAvifMimeType($path);
        if ($avifMimeType !== null) {
            return $avifMimeType;
        }

        return mb_strtolower(trim(explode(';', $mimeType, 2)[0]));
    }

    private function detectAvifMimeType(string $path): ?string
    {
        $header = register_call_without_warnings(
            static fn(): string|false => file_get_contents($path, false, null, 0, 4096),
        );
        if (!\is_string($header) || \strlen($header) < 16 || substr($header, 4, 4) !== 'ftyp') {
            return null;
        }

        $sizeData = unpack('Nsize', substr($header, 0, 4));
        if (!\is_array($sizeData)) {
            return null;
        }

        $boxSize = $sizeData['size'];
        if ($boxSize < 16 || $boxSize > \strlen($header)) {
            return null;
        }

        $majorBrand = substr($header, 8, 4);
        if ($majorBrand === 'avif') {
            return 'image/avif';
        }

        if ($majorBrand === 'avis') {
            return 'image/avif-sequence';
        }

        for ($offset = 16; $offset + 4 <= $boxSize; $offset += 4) {
            $brand = substr($header, $offset, 4);
            if ($brand === 'avif') {
                return 'image/avif';
            }

            if ($brand === 'avis') {
                return 'image/avif-sequence';
            }
        }

        return null;
    }
}
