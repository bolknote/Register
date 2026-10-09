<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Import\Telegram;

/** Provides result.json and, for ZIP exports, safe read-only access to referenced media. */
final class TelegramExportPackage
{
    public const int MAX_PACKAGE_BYTES = 500_000_000;

    private const int MAX_ENTRIES = 250_000;

    private const int MAX_MEDIA_BYTES = 200_000_000;

    /** @var array<string, array{index: int, size: int}> */
    private array $entries = [];

    /** @var array<string, array<string, mixed>> */
    private array $liveMedia = [];

    /** @var array<string, resource|null> */
    private array $liveFiles = [];

    private function __construct(
        private readonly string       $json,
        private readonly string       $jsonDirectory,
        private readonly ?\ZipArchive $zip,
        private readonly ?TelegramFileClientInterface $fileClient = null,
    ) {
    }

    public function __destruct()
    {
        $this->zip?->close();
        foreach ($this->liveFiles as $file) {
            if (\is_resource($file)) {
                fclose($file);
            }
        }
    }

    public static function fromFile(string $path, string $clientOriginalName = ''): self
    {
        $size = register_call_without_warnings(static fn(): int|false => filesize($path));
        if ($size === false || $size <= 0 || $size > self::MAX_PACKAGE_BYTES) {
            throw new \UnexpectedValueException('Telegram export is too large or empty.');
        }

        $signature = register_call_without_warnings(
            static fn(): string|false => file_get_contents($path, false, null, 0, 4),
        );
        $extension = mb_strtolower(pathinfo($clientOriginalName, PATHINFO_EXTENSION));
        if ($extension === 'zip' || $signature === "PK\x03\x04") {
            return self::fromZip($path);
        }

        if ($size > TelegramDiscussionArchive::MAX_BYTES) {
            throw new \UnexpectedValueException('Telegram JSON must be a non-empty file smaller than 25 MB.');
        }

        $json = register_call_without_warnings(static fn(): string|false => file_get_contents($path));
        if (!\is_string($json)) {
            throw new \RuntimeException('Unable to read the Telegram export.');
        }

        return new self($json, '', null);
    }

    public function discussionArchive(): TelegramDiscussionArchive
    {
        return TelegramDiscussionArchive::fromJson($this->json);
    }

    public static function fromJson(string $json): self
    {
        TelegramDiscussionArchive::fromJson($json);

        return new self($json, '', null);
    }

    public static function fromLiveSnapshot(string $json, TelegramFileClientInterface $fileClient): self
    {
        TelegramDiscussionArchive::fromJson($json);
        $package = new self($json, '', null, $fileClient);
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        foreach ((array)($data['messages'] ?? []) as $message) {
            foreach ((array)($message['telegram_media'] ?? []) as $media) {
                $package->liveMedia[(string)$media['path']] = $media;
            }
        }

        return $package;
    }

    public function containsMedia(string $relativePath): bool
    {
        return $this->mediaEntry($relativePath) !== null;
    }

    public function mediaSize(string $relativePath): ?int
    {
        if ($this->fileClient !== null) {
            $this->prepareLiveFile($relativePath);
            $file = $this->liveFiles[$relativePath] ?? null;
            return \is_resource($file) ? (fstat($file)['size'] ?? 0) : null;
        }

        return $this->mediaEntry($relativePath)['size'] ?? null;
    }

    /** @return resource|null */
    public function openMediaStream(string $relativePath): mixed
    {
        if ($this->fileClient !== null) {
            $this->prepareLiveFile($relativePath);
            $file = $this->liveFiles[$relativePath] ?? null;
            if (!\is_resource($file)) {
                return null;
            }

            $path = stream_get_meta_data($file)['uri'] ?? null;
            if (!\is_string($path)) {
                return null;
            }

            $input = fopen($path, 'rb');
            return \is_resource($input) ? $input : null;
        }

        $entry = $this->mediaEntry($relativePath);
        if ($entry === null || !$this->zip instanceof \ZipArchive) {
            return null;
        }

        $this->assertZipRegularFile($entry['index'], $this->entryName($relativePath));
        $stream = $this->zip->getStream($this->entryName($relativePath));

        return \is_resource($stream) ? $stream : null;
    }

    private function prepareLiveFile(string $relativePath): void
    {
        if (array_key_exists($relativePath, $this->liveFiles)) {
            return;
        }

        $media = $this->liveMedia[$relativePath] ?? null;
        $bytes = $media !== null ? $this->fileClient?->download($media) : null;
        if ($bytes === null) {
            $this->liveFiles[$relativePath] = null;
            return;
        }

        $file = tmpfile();
        if (!\is_resource($file)) {
            throw new \RuntimeException('Unable to stage Telegram media.');
        }

        $this->liveFiles[$relativePath] = $file;
        $length = \strlen($bytes);
        for ($offset = 0; $offset < $length;) {
            $written = fwrite($file, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to stage Telegram media.');
            }

            $offset += $written;
        }
    }

    private static function fromZip(string $path): self
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \UnexpectedValueException('ZIP support is not available on this server.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \UnexpectedValueException('Telegram ZIP export cannot be opened.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new \UnexpectedValueException('Telegram ZIP export contains too many entries.');
            }

            $entries = [];
            $jsonEntries = [];
            $totalBytes = 0;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index, \ZipArchive::FL_UNCHANGED);
                $name = \is_array($stat) ? $stat['name'] : null;
                $entrySize = \is_array($stat) ? $stat['size'] : null;
                if (!\is_string($name) || !\is_int($entrySize) || $entrySize < 0) {
                    throw new \UnexpectedValueException('Telegram ZIP export contains a malformed entry.');
                }

                $pathWithoutSlash = rtrim($name, '/');
                if (!self::isSafeRelativePath($pathWithoutSlash)) {
                    throw new \UnexpectedValueException('Telegram ZIP export contains an unsafe entry path.');
                }

                if (str_ends_with($name, '/')) {
                    continue;
                }

                if (isset($entries[$name])) {
                    throw new \UnexpectedValueException('Telegram ZIP export contains duplicate entries.');
                }

                $totalBytes += $entrySize;
                if ($totalBytes > self::MAX_PACKAGE_BYTES * 4) {
                    throw new \UnexpectedValueException('Telegram ZIP export expands to an unsafe size.');
                }

                $entries[$name] = ['index' => $index, 'size' => $entrySize];
                if (basename($name) === 'result.json') {
                    $jsonEntries[] = $name;
                }
            }

            if (\count($jsonEntries) !== 1) {
                throw new \UnexpectedValueException('Telegram ZIP export must contain exactly one result.json.');
            }

            $jsonName = $jsonEntries[0];
            $jsonEntry = $entries[$jsonName];
            if ($jsonEntry['size'] <= 0 || $jsonEntry['size'] > TelegramDiscussionArchive::MAX_BYTES) {
                throw new \UnexpectedValueException('Telegram result.json is too large or empty.');
            }

            self::assertZipRegularFileStatic($zip, $jsonEntry['index'], $jsonName);
            $json = $zip->getFromIndex(
                $jsonEntry['index'],
                TelegramDiscussionArchive::MAX_BYTES,
                \ZipArchive::FL_UNCHANGED,
            );
            if (!\is_string($json) || \strlen($json) !== $jsonEntry['size']) {
                throw new \UnexpectedValueException('Telegram result.json cannot be read from the ZIP export.');
            }

            $package = new self($json, dirname($jsonName) === '.' ? '' : dirname($jsonName), $zip);
            $package->entries = $entries;

            return $package;
        } catch (\Throwable $throwable) {
            $zip->close();
            throw $throwable;
        }
    }

    /** @return array{index: int, size: int}|null */
    private function mediaEntry(string $relativePath): ?array
    {
        if (!$this->zip instanceof \ZipArchive) {
            return null;
        }

        $entryName = $this->entryName($relativePath);
        $entry = $this->entries[$entryName] ?? null;
        if (!\is_array($entry) || $entry['size'] <= 0 || $entry['size'] > self::MAX_MEDIA_BYTES) {
            return null;
        }

        return $entry;
    }

    private function entryName(string $relativePath): string
    {
        $relativePath = trim($relativePath);
        if (!self::isSafeRelativePath($relativePath)) {
            return '';
        }

        return $this->jsonDirectory === ''
            ? $relativePath
            : $this->jsonDirectory . '/' . $relativePath;
    }

    private function assertZipRegularFile(int $index, string $name): void
    {
        if (!$this->zip instanceof \ZipArchive) {
            throw new \LogicException('Telegram media is not backed by a ZIP archive.');
        }

        self::assertZipRegularFileStatic($this->zip, $index, $name);
    }

    private static function assertZipRegularFileStatic(\ZipArchive $zip, int $index, string $name): void
    {
        $operatingSystem = 0;
        $attributes = 0;
        if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes, \ZipArchive::FL_UNCHANGED)) {
            $type = ($attributes >> 16) & 0170000;
            if ($type !== 0 && $type !== 0100000) {
                throw new \UnexpectedValueException('Telegram ZIP entry is not a regular file: ' . $name);
            }
        }
    }

    private static function isSafeRelativePath(string $path): bool
    {
        if ($path === ''
            || \strlen($path) > 1024
            || $path[0] === '/'
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
        ) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if (in_array($segment, ['', '.', '..'], true) || \strlen($segment) > 255) {
                return false;
            }
        }

        return true;
    }
}
