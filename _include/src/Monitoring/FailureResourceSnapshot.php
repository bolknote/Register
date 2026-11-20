<?php
/**
 * @copyright 2026 Register contributors
 * @license https://opensource.org/license/mit MIT
 * @package Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

/** Samples local resource limits only when a request fails. */
final class FailureResourceSnapshot
{
    /** @return array<string, mixed> */
    public static function capture(string $procDirectory = '/proc', string $cgroupDirectory = '/sys/fs/cgroup'): array
    {
        $snapshot = [
            'php_memory_bytes' => memory_get_usage(true),
            'php_peak_memory_bytes' => memory_get_peak_usage(true),
            'php_memory_limit' => ini_get('memory_limit'),
            'host_load_average' => \function_exists('sys_getloadavg') ? sys_getloadavg() : false,
        ];
        foreach (['self/status', 'self/limits', 'sys/fs/file-nr'] as $file) {
            $text = self::read($procDirectory . '/' . $file);
            $split = preg_split('/\R/', $text);
            $lines = $split !== false ? $split : [];
            $snapshot[$file] = array_values(array_filter($lines, static fn(string $line): bool =>
                preg_match('/^(?:VmPeak:|VmRSS:|Threads:|Max processes\s|Max open files\s|[0-9]+\s)/', $line) === 1
            ));
        }

        $descriptors = $procDirectory . '/self/fd';
        if (is_dir($descriptors)) {
            $files = register_call_without_warnings(static fn(): array|false => scandir($descriptors));
            $snapshot['open_file_descriptors'] = \is_array($files) ? max(0, \count($files) - 2) : null;
        }

        // cgroup v2 exposes per-account limits independently of node-wide load.
        $membership = self::read($procDirectory . '/self/cgroup');
        if (preg_match('~^0::(/[^\r\n]*)$~m', $membership, $matches) === 1
            && !\in_array('..', explode('/', $matches[1]), true)
        ) {
            $directory = rtrim($cgroupDirectory . $matches[1], '/');
            foreach (['pids.current', 'pids.max', 'pids.events', 'memory.current', 'memory.max', 'memory.events'] as $file) {
                $value = self::read($directory . '/' . $file);
                if ($value !== '') {
                    $snapshot['cgroup'][$file] = trim($value);
                }
            }
        }

        return $snapshot;
    }

    private static function read(string $filename): string
    {
        if (!is_file($filename) || !is_readable($filename)) {
            return '';
        }

        $text = register_call_without_warnings(static fn(): string|false => file_get_contents($filename, length: 16384));

        return \is_string($text) ? $text : '';
    }
}
