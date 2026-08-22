<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class BuildInfo
{
    public const string DEVELOPMENT_VERSION = '2.0dev';

    public const string FILENAME = 'register-build.json';

    private const int FORMAT_VERSION = 1;

    private const int MAX_BYTES = 16 * 1024;

    private function __construct()
    {
        throw new \LogicException('BuildInfo is a static metadata reader.');
    }

    public static function version(string $applicationRoot): string
    {
        $data = self::readBuildData($applicationRoot);
        $version = $data['version'] ?? null;

        return \is_string($version) && self::validVersion($version)
            ? $version
            : self::DEVELOPMENT_VERSION;
    }

    public static function manifest(string $applicationRoot): ?ReleaseManifest
    {
        $filename = rtrim($applicationRoot, '/\\') . '/register-release.json';
        if (!is_file($filename) || is_link($filename)) {
            return null;
        }

        try {
            return ReleaseManifest::fromFile($filename);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function toJson(
        string $releaseId,
        string $version,
        string $builtAt,
        string $commit,
    ): string {
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,95}$/D', $releaseId) !== 1
            || !self::validVersion($version)
            || preg_match('/^[a-f0-9]{40}$/D', $commit) !== 1
        ) {
            throw new \InvalidArgumentException('The release build metadata is invalid.');
        }

        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $builtAt);
        if (!$date instanceof \DateTimeImmutable || $date->format(\DateTimeInterface::ATOM) !== $builtAt) {
            throw new \InvalidArgumentException('The release build timestamp is invalid.');
        }

        return json_encode([
            'format'     => self::FORMAT_VERSION,
            'product'    => ReleaseManifest::PRODUCT,
            'release_id' => $releaseId,
            'version'    => $version,
            'built_at'   => $builtAt,
            'commit'     => $commit,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array<string, mixed> */
    private static function readBuildData(string $applicationRoot): array
    {
        $filename = rtrim($applicationRoot, '/\\') . '/' . self::FILENAME;
        if (!is_file($filename) || is_link($filename)) {
            return [];
        }

        $size = filesize($filename);
        if (!\is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            return [];
        }

        $json = file_get_contents($filename);
        if (!\is_string($json)) {
            return [];
        }

        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($data)
            || ($data['format'] ?? null) !== self::FORMAT_VERSION
            || ($data['product'] ?? null) !== ReleaseManifest::PRODUCT
        ) {
            return [];
        }

        return $data;
    }

    private static function validVersion(string $version): bool
    {
        return preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,63}$/D', $version) === 1;
    }
}
