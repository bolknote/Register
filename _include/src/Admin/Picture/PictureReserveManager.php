<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Picture;

use Register\AdminYard\Helper\RandomHelper;
use Symfony\Component\HttpFoundation\Response;

class PictureReserveManager
{
    private const int RESERVE_TTL_SECONDS = 900;

    private const string RESERVE_CACHE_SUBDIR = 'picture_reserve';

    private readonly string $imageDir;

    private readonly string $cacheDir;

    public function __construct(
        private readonly PictureFileNameHelper $fileNameHelper,
        string $imageDir,
        string $cacheDir,
    ) {
        $this->imageDir = rtrim($imageDir, '/');
        $this->cacheDir = rtrim($cacheDir, '/');
    }

    /**
     * @throws \JsonException
     * @throws \RuntimeException
     * @return array<string, string>
     */
    public function reserveFileName(string $path, string $suggestedName): array
    {
        $normalized = $this->fileNameHelper->normalizeFileName($suggestedName);
        if ($normalized === '') {
            throw new \RuntimeException('Empty file name.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->fileNameHelper->assertAllowedExtension($normalized);
        $this->ensureDirExists($this->imageDir . $path);
        $reserveDir = $this->getReservePathDir($path);
        $this->ensureDirExists($reserveDir);
        if (!is_writable($reserveDir)) {
            throw new \RuntimeException('Reserve directory is not writable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $this->cleanupExpiredReserves($path);

        $token    = RandomHelper::getRandomHexString32();
        $attempts = 0;

        while ($attempts++ < 100) {
            $candidate = $this->fileNameHelper->generateStorageFileName($normalized);
            if (is_file($this->imageDir . $path . '/' . $candidate)) {
                continue;
            }

            $reserveFile = $this->getReserveFilePath($path, $candidate);
            if ($this->tryCreateReserve($reserveFile, $token, $path, $candidate)) {
                return [
                    'name'  => $candidate,
                    'token' => $token,
                ];
            }

        }

        throw new \RuntimeException('Unable to reserve file name.', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * Reserves the next historical content-image name for one publication date.
     *
     * The unnumbered slot is zero; subsequent slots are .1, .2, and so on. A slot is shared by
     * every supported extension and density suffix, so concurrent encoders cannot create both
     * YYYY.MM.DD.webp and YYYY.MM.DD@2x.jpg for the same ordinal.
     *
     * @throws \JsonException
     * @throws \RuntimeException
     * @return array<string, string>
     */
    public function reserveCanonicalImageFileName(
        string $path,
        string $publicationDate,
        string $extension,
        bool   $retina,
    ): array {
        if (
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $publicationDate, $dateParts) !== 1
            || !checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1])
        ) {
            throw new \RuntimeException('Invalid publication date.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $extension = mb_strtolower(ltrim($extension, '.'));
        $probeName = 'image.' . $extension;
        $this->fileNameHelper->assertAllowedExtension($probeName);

        $this->ensureDirExists($this->imageDir . $path);
        $reserveDir = $this->getReservePathDir($path);
        $this->ensureDirExists($reserveDir);
        if (!is_writable($reserveDir)) {
            throw new \RuntimeException('Reserve directory is not writable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $lockPath = $reserveDir . '/.canonical-image-name.lock';
        $lock = register_call_without_warnings(static fn() => fopen($lockPath, 'c+b'));
        if ($lock === false) {
            throw new \RuntimeException('Unable to lock canonical image names.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        register_call_without_warnings(static fn(): bool => chmod($lockPath, 0600));

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock canonical image names.', Response::HTTP_SERVICE_UNAVAILABLE);
            }

            $this->cleanupExpiredReserves($path);
            $date = str_replace('-', '.', $publicationDate);
            $usedIndexes = $this->canonicalImageIndexes($path, $date);
            $token = RandomHelper::getRandomHexString32();

            for ($index = 0; $index < 1000; ++$index) {
                if (isset($usedIndexes[$index])) {
                    continue;
                }

                $candidate = $date
                    . ($index === 0 ? '' : '.' . $index)
                    . ($retina ? '@2x' : '')
                    . '.' . $extension;
                $reserveFile = $this->getReserveFilePath($path, $candidate);
                if ($this->tryCreateReserve($reserveFile, $token, $path, $candidate)) {
                    return [
                        'name'  => $candidate,
                        'token' => $token,
                    ];
                }
            }
        } finally {
            register_call_without_warnings(static fn(): bool => flock($lock, LOCK_UN));
            fclose($lock);
        }

        throw new \RuntimeException('Unable to reserve canonical image name.', Response::HTTP_SERVICE_UNAVAILABLE);
    }

    public function validateReserveToken(string $path, string $filename, string $token): bool
    {
        $reserveFile = $this->getReserveFilePath($path, $filename);
        if (!is_file($reserveFile)) {
            return false;
        }

        $payload = register_call_without_warnings(static fn(): string|false => file_get_contents($reserveFile));
        if ($payload === false) {
            return false;
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (!\is_array($data)) {
            return false;
        }

        $expiresAt = (int)($data['expires_at'] ?? 0);
        if ($expiresAt < time()) {
            register_call_without_warnings(static fn(): bool => unlink($reserveFile));
            return false;
        }

        return hash_equals((string)($data['token'] ?? ''), $token)
            && (string)($data['path'] ?? '') === $path
            && (string)($data['name'] ?? '') === $filename;
    }

    public function clearReserve(string $path, string $filename): void
    {
        $reserveFile = $this->getReserveFilePath($path, $filename);
        if (is_file($reserveFile)) {
            register_call_without_warnings(static fn(): bool => unlink($reserveFile));
        }
    }

    public function cleanupExpiredReserves(string $path): void
    {
        $reserveDir = $this->getReservePathDir($path);
        if (!is_dir($reserveDir)) {
            return;
        }

        $iterator = new \DirectoryIterator($reserveDir);
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $filename = $item->getFilename();
            if (!str_ends_with($filename, '.json')) {
                continue;
            }

            $reserveFile = $item->getPathname();
            if ($this->isReserveExpired($reserveFile)) {
                register_call_without_warnings(static fn(): bool => unlink($reserveFile));
            }
        }
    }

    private function getReserveFilePath(string $path, string $filename): string
    {
        $reserveRoot = $this->cacheDir . '/' . self::RESERVE_CACHE_SUBDIR;
        $safePath    = ltrim($path, '/');

        return $reserveRoot . ($safePath !== '' ? '/' . $safePath : '') . '/' . $filename . '.json';
    }

    private function getReservePathDir(string $path): string
    {
        $reserveRoot = $this->cacheDir . '/' . self::RESERVE_CACHE_SUBDIR;
        $safePath    = ltrim($path, '/');

        return $reserveRoot . ($safePath !== '' ? '/' . $safePath : '');
    }

    /** @return array<int, true> */
    private function canonicalImageIndexes(string $path, string $date): array
    {
        $pattern = '/^' . preg_quote($date, '/') . '(?:\.(?<number>\d+))?(?:@2x)?\.[a-z0-9]+$/Di';
        $used = [];

        foreach ([$this->imageDir . $path, $this->getReservePathDir($path)] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (new \DirectoryIterator($directory) as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                $filename = $item->getFilename();
                if ($directory === $this->getReservePathDir($path)) {
                    if (!str_ends_with($filename, '.json')) {
                        continue;
                    }

                    $filename = substr($filename, 0, -5);
                }

                if (preg_match($pattern, $filename, $match) !== 1) {
                    continue;
                }

                $number = ($match['number'] ?? '') === '' ? 0 : (int)$match['number'];
                $used[$number] = true;
            }
        }

        return $used;
    }

    private function tryCreateReserve(string $reserveFile, string $token, string $path, string $name): bool
    {
        if (is_file($reserveFile)) {
            if (!$this->isReserveExpired($reserveFile)) {
                return false;
            }

            register_call_without_warnings(static fn(): bool => unlink($reserveFile));
        } else {
            $dir = \dirname($reserveFile);
            $this->ensureDirExists($dir);
        }

        $fh = register_call_without_warnings(static fn() => fopen($reserveFile, 'xb'));
        if ($fh === false) {
            if ($this->isReserveExpired($reserveFile)) {
                register_call_without_warnings(static fn(): bool => unlink($reserveFile));
            }

            return false;
        }

        register_call_without_warnings(static fn(): bool => chmod($reserveFile, 0600));

        $payload = json_encode([
            'token'      => $token,
            'path'       => $path,
            'name'       => $name,
            'expires_at' => time() + self::RESERVE_TTL_SECONDS,
        ], JSON_THROW_ON_ERROR);

        fwrite($fh, $payload);
        fclose($fh);

        return true;
    }

    private function isReserveExpired(string $reserveFile): bool
    {
        $payload = register_call_without_warnings(static fn(): string|false => file_get_contents($reserveFile));
        if ($payload === false) {
            return true;
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return true;
        }

        if (!\is_array($data)) {
            return true;
        }

        $expiresAt = (int)($data['expires_at'] ?? 0);

        return $expiresAt < time();
    }

    private function ensureDirExists(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        $warning = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            $warning = $errstr;
            return true;
        });
        $created = mkdir($dir, 0700, true);
        restore_error_handler();

        if (!$created && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $dir) . ($warning !== null && $warning !== '' ? ' (' . $warning . ')' : ''));
        }

        chmod($dir, 0700);
    }
}
