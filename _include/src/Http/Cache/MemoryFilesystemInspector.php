<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

/** Identifies Linux tmpfs/ramfs mounts without invoking platform-specific shell commands. */
final readonly class MemoryFilesystemInspector
{
    public function __construct(private string $mountInfoFilename = '/proc/self/mountinfo')
    {
    }

    /** Returns null when the platform exposes no usable mount table. */
    public function isMemoryBacked(string $path): ?bool
    {
        $path = realpath($path);
        if ($path === false) {
            return false;
        }

        $lines = register_call_without_warnings(fn(): array|false => file(
            $this->mountInfoFilename,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
        ));
        if (!\is_array($lines)) {
            return null;
        }

        $bestMount = null;
        $bestType  = null;
        foreach ($lines as $line) {
            $sections = explode(' - ', $line, 2);
            if (\count($sections) !== 2) {
                continue;
            }

            $mountFields = preg_split('/\s+/', trim($sections[0]));
            $typeFields  = preg_split('/\s+/', trim($sections[1]));
            if (!\is_array($mountFields) || !isset($mountFields[4])
                || !\is_array($typeFields) || !isset($typeFields[0])
            ) {
                continue;
            }

            $mount = $this->decodeMountPath($mountFields[4]);
            if (!$this->contains($mount, $path)
                || $bestMount !== null && \strlen($bestMount) >= \strlen($mount)
            ) {
                continue;
            }

            $bestMount = $mount;
            $bestType  = strtolower($typeFields[0]);
        }

        return $bestType === null ? null : \in_array($bestType, ['ramfs', 'tmpfs'], true);
    }

    private function decodeMountPath(string $path): string
    {
        return strtr($path, [
            '\\040' => ' ',
            '\\011' => "\t",
            '\\012' => "\n",
            '\\134' => '\\',
        ]);
    }

    private function contains(string $mount, string $path): bool
    {
        return $mount === '/'
            || $path === $mount
            || str_starts_with($path, rtrim($mount, '/') . '/');
    }
}
