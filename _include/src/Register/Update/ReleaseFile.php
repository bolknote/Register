<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

final readonly class ReleaseFile
{
    public const string TARGET_APPLICATION = 'app';

    public const string TARGET_PUBLIC = 'public';

    public function __construct(
        public string $target,
        public string $path,
        public int    $size,
        public string $sha256,
        public int    $mode = 0644,
    ) {
        if (!\in_array($target, [self::TARGET_APPLICATION, self::TARGET_PUBLIC], true)) {
            throw new \InvalidArgumentException('A release file has an invalid target.');
        }

        if (!self::isSafeRelativePath($path)) {
            throw new \InvalidArgumentException('A release file has an invalid path: ' . $path);
        }

        if ($size < 0) {
            throw new \InvalidArgumentException('A release file has an invalid size: ' . $path);
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('A release file has an invalid SHA-256 digest: ' . $path);
        }

        if (!\in_array($mode, [0644, 0755], true)) {
            throw new \InvalidArgumentException('A release file has an unsupported mode: ' . $path);
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::requiredString($data, 'target'),
            self::requiredString($data, 'path'),
            self::requiredInt($data, 'size'),
            self::requiredString($data, 'sha256'),
            self::requiredInt($data, 'mode'),
        );
    }

    /** @return array{target: string, path: string, size: int, sha256: string, mode: int} */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'path'   => $this->path,
            'size'   => $this->size,
            'sha256' => $this->sha256,
            'mode'   => $this->mode,
        ];
    }

    public function key(): string
    {
        return $this->target . ':' . $this->path;
    }

    public function archivePath(): string
    {
        $prefix = $this->target === self::TARGET_APPLICATION ? 'register-app/' : 'public_html/';

        return $prefix . $this->path;
    }

    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === ''
            || \strlen($path) > 512
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

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Release file field "' . $key . '" must be a string.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function requiredInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw new \InvalidArgumentException('Release file field "' . $key . '" must be an integer.');
        }

        return $value;
    }
}
