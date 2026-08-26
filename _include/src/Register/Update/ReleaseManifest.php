<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class ReleaseManifest
{
    public const int FORMAT_VERSION = 2;

    public const string PRODUCT = 'register';

    public const string ARCHIVE_PATH = 'public_html/register-release.json';

    public const int MAX_FILES = 20_000;

    public const int MAX_TOTAL_BYTES = 512 * 1024 * 1024;

    /** @var array<string, int> */
    private const array CHANNEL_PRIORITIES = [
        'edge'   => 0,
        'rc'     => 1,
        'stable' => 2,
    ];

    /** @var list<ReleaseFile> */
    public array $files;

    /** @var array<string, ReleaseFile> */
    private array $filesByKey;

    /**
     * @param list<ReleaseFile> $files
     */
    public function __construct(
        public string $releaseId,
        public string $version,
        public string $baseVersion,
        public string $channel,
        public int    $buildNumber,
        public string $builtAt,
        public string $commit,
        public string $minimumPhp,
        public int    $schemaFrom,
        public int    $schemaTo,
        array         $files,
    ) {
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,95}$/D', $releaseId) !== 1) {
            throw new \InvalidArgumentException('The release ID is invalid.');
        }

        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/D', $version) !== 1
            || preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:-[0-9A-Za-z.-]+)?$/D', $baseVersion) !== 1
        ) {
            throw new \InvalidArgumentException('The release version is invalid.');
        }

        if (!isset(self::CHANNEL_PRIORITIES[$channel])) {
            throw new \InvalidArgumentException('The release channel is not supported.');
        }

        if ($buildNumber < 1) {
            throw new \InvalidArgumentException('The release build number is invalid.');
        }

        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $builtAt);
        if (!$date instanceof \DateTimeImmutable || $date->format(\DateTimeInterface::ATOM) !== $builtAt) {
            throw new \InvalidArgumentException('The release build timestamp is invalid.');
        }

        if (preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1) {
            throw new \InvalidArgumentException('The release commit is invalid.');
        }

        if (preg_match('/^[0-9]+(?:\.[0-9]+){1,3}$/D', $minimumPhp) !== 1) {
            throw new \InvalidArgumentException('The minimum PHP version is invalid.');
        }

        if ($schemaFrom < 1 || $schemaTo < $schemaFrom) {
            throw new \InvalidArgumentException('The release database generation range is invalid.');
        }

        if ($files === [] || \count($files) > self::MAX_FILES) {
            throw new \InvalidArgumentException('The release file list is empty or too large.');
        }

        $filesByKey = [];
        $totalBytes = 0;
        foreach ($files as $file) {
            if ($file->archivePath() === self::ARCHIVE_PATH) {
                throw new \InvalidArgumentException('The release file list contains the reserved manifest path.');
            }

            if (isset($filesByKey[$file->key()])) {
                throw new \InvalidArgumentException('The release file list contains a duplicate: ' . $file->key());
            }

            $filesByKey[$file->key()] = $file;
            $totalBytes += $file->size;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new \InvalidArgumentException('The release expands beyond the supported size.');
            }
        }

        $this->files      = $files;
        $this->filesByKey = $filesByKey;
    }

    public static function fromJson(string $json): self
    {
        if ($json === '' || \strlen($json) > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('The release manifest is empty or too large.');
        }

        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \InvalidArgumentException('The release manifest is not valid JSON.', 0, $jsonException);
        }

        if (!\is_array($data)) {
            throw new \InvalidArgumentException('The release manifest must be an object.');
        }

        if (($data['format'] ?? null) !== self::FORMAT_VERSION || ($data['product'] ?? null) !== self::PRODUCT) {
            throw new \InvalidArgumentException('The release manifest format or product is not supported.');
        }

        $requirements = $data['requirements'] ?? null;
        $database     = $data['database'] ?? null;
        $fileData     = $data['files'] ?? null;
        if (!\is_array($requirements) || !\is_array($database) || !\is_array($fileData)) {
            throw new \InvalidArgumentException('The release manifest has incomplete metadata.');
        }

        $files = [];
        foreach ($fileData as $item) {
            if (!\is_array($item)) {
                throw new \InvalidArgumentException('The release manifest contains an invalid file entry.');
            }

            $files[] = ReleaseFile::fromArray($item);
        }

        return new self(
            self::requiredString($data, 'release_id'),
            self::requiredString($data, 'version'),
            self::requiredString($data, 'base_version'),
            self::requiredString($data, 'channel'),
            self::requiredInt($data, 'build_number'),
            self::requiredString($data, 'built_at'),
            self::requiredString($data, 'commit'),
            self::requiredString($requirements, 'php'),
            self::requiredInt($database, 'from_generation'),
            self::requiredInt($database, 'to_generation'),
            $files,
        );
    }

    public static function fromFile(string $filename): self
    {
        $json = file_get_contents($filename);
        if (!\is_string($json)) {
            throw new \RuntimeException('Unable to read the release manifest.');
        }

        return self::fromJson($json);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format'       => self::FORMAT_VERSION,
            'product'      => self::PRODUCT,
            'release_id'   => $this->releaseId,
            'version'      => $this->version,
            'base_version' => $this->baseVersion,
            'channel'      => $this->channel,
            'build_number' => $this->buildNumber,
            'built_at'     => $this->builtAt,
            'commit'       => $this->commit,
            'requirements' => [
                'php' => $this->minimumPhp,
            ],
            'database' => [
                'from_generation' => $this->schemaFrom,
                'to_generation'   => $this->schemaTo,
            ],
            'files' => array_map(static fn(ReleaseFile $file): array => $file->toArray(), $this->files),
        ];
    }

    /** @return array<string, ReleaseFile> */
    public function filesByKey(): array
    {
        return $this->filesByKey;
    }

    public function isNewerThan(self $installed): bool
    {
        $versionComparison = version_compare($this->baseVersion, $installed->baseVersion);
        if ($versionComparison !== 0) {
            return $versionComparison > 0;
        }

        $channelComparison = $this->channelPriority() <=> $installed->channelPriority();
        if ($channelComparison !== 0) {
            return $channelComparison > 0;
        }

        return $this->channel === $installed->channel && $this->buildNumber > $installed->buildNumber;
    }

    public function totalBytes(): int
    {
        return array_sum(array_map(static fn(ReleaseFile $file): int => $file->size, $this->files));
    }

    private function channelPriority(): int
    {
        return self::CHANNEL_PRIORITIES[$this->channel];
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Release manifest field "' . $key . '" must be a string.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function requiredInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw new \InvalidArgumentException('Release manifest field "' . $key . '" must be an integer.');
        }

        return $value;
    }
}
