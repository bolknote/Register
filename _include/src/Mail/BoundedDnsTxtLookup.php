<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Mail;

/** Runs native TXT lookups outside the web process and enforces one deadline for the whole batch. */
final readonly class BoundedDnsTxtLookup implements DnsTxtLookupInterface
{
    private const float DEFAULT_TIMEOUT_SECONDS = 1.0;

    private const int MAX_OUTPUT_BYTES = 65_536;

    private const string CHILD_SCRIPT = <<<'PHP'
        $names = json_decode((string)stream_get_contents(STDIN), true);
        if (!is_array($names) || !function_exists('dns_get_record')) {
            exit(2);
        }

        $result = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                exit(3);
            }

            try {
                $records = @dns_get_record($name, DNS_TXT);
            } catch (Throwable) {
                $result[$name] = null;
                continue;
            }

            if (!is_array($records)) {
                $result[$name] = null;
                continue;
            }

            $texts = [];
            foreach ($records as $record) {
                $text = $record['txt'] ?? null;
                if (is_string($text)) {
                    $texts[$text] = $text;
                }
            }
            $result[$name] = array_values($texts);
        }

        echo json_encode($result, JSON_THROW_ON_ERROR);
        PHP;

    public function __construct(private float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS)
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('The DNS TXT lookup timeout must be positive and finite.');
        }
    }

    #[\Override]
    public function lookup(array $names): ?array
    {
        $names = $this->normalizeNames($names);
        if ($names === []) {
            return [];
        }

        $phpBinary = $this->phpBinary();
        if ($phpBinary === null || !\function_exists('proc_open')) {
            return null;
        }

        $pipes = [];
        try {
            $process = proc_open(
                [$phpBinary, '-r', self::CHILD_SCRIPT],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );
        } catch (\Throwable) {
            return null;
        }

        if (!\is_resource($process)) {
            return null;
        }

        if (\count($pipes) !== 3) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($process);
            return null;
        }

        try {
            $input = json_encode($names, JSON_THROW_ON_ERROR);
            if (!$this->writeInput($pipes[0], $input)) {
                $this->terminate($process);
                return null;
            }

            fclose($pipes[0]);
            unset($pipes[0]);

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $output = $this->readOutput($process, $pipes[1], $pipes[2]);
            if ($output === null) {
                return null;
            }
        } catch (\Throwable) {
            $this->terminate($process);
            return null;
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($process);
        }

        return $this->decode($output, $names);
    }

    /** @param list<string> $names
     * @return list<string>
     */
    private function normalizeNames(array $names): array
    {
        $normalized = [];
        foreach ($names as $name) {
            $name = strtolower(rtrim(trim($name), '.'));
            if ($name === '' || \strlen($name) > 253) {
                continue;
            }

            $valid = true;
            foreach (explode('.', $name) as $label) {
                if ($label === '' || \strlen($label) > 63 || preg_match('/^[a-z0-9_-]+$/D', $label) !== 1) {
                    $valid = false;
                    break;
                }
            }

            if ($valid) {
                $normalized[$name] = $name;
            }
        }

        return array_values($normalized);
    }

    /** @param resource $input */
    private function writeInput($input, string $payload): bool
    {
        $offset = 0;
        while ($offset < \strlen($payload)) {
            $written = fwrite($input, substr($payload, $offset));
            if (!\is_int($written) || $written <= 0) {
                return false;
            }

            $offset += $written;
        }

        return true;
    }

    /** @param resource $process
     * @param resource $stdout
     * @param resource $stderr
     */
    private function readOutput($process, $stdout, $stderr): ?string
    {
        $output   = '';
        $errorBytes = 0;
        $deadline = (float)hrtime(true) + $this->timeoutSeconds * 1_000_000_000.0;

        while (true) {
            $output .= (string)stream_get_contents($stdout);
            $errorBytes += \strlen((string)stream_get_contents($stderr));
            if (\strlen($output) + $errorBytes > self::MAX_OUTPUT_BYTES) {
                $this->terminate($process);
                return null;
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                return $output . (string)stream_get_contents($stdout);
            }

            $remainingNanoseconds = $deadline - (float)hrtime(true);
            if ($remainingNanoseconds <= 0.0) {
                $this->terminate($process);
                return null;
            }

            $read         = [$stdout, $stderr];
            $write        = null;
            $except       = null;
            $waitSeconds  = min(0.05, $remainingNanoseconds / 1_000_000_000.0);
            $seconds      = (int)$waitSeconds;
            $microseconds = (int)ceil(($waitSeconds - (float)$seconds) * 1_000_000.0);
            $selected = register_call_without_warnings(
                static fn(): int|false => stream_select($read, $write, $except, $seconds, $microseconds),
            );
            if ($selected === false) {
                $this->terminate($process);
                return null;
            }
        }
    }

    /** @param resource $process */
    private function terminate($process): void
    {
        register_call_without_warnings(static fn(): bool => proc_terminate($process));
        $status = proc_get_status($process);
        if ($status['running']) {
            register_call_without_warnings(static fn(): bool => proc_terminate($process, 9));
        }
    }

    /** @param list<string> $names
     * @return array<string, list<string>|null>|null
     */
    private function decode(string $output, array $names): ?array
    {
        try {
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $result = [];
        foreach ($names as $name) {
            $records = $decoded[$name] ?? null;
            if ($records === null) {
                $result[$name] = null;
                continue;
            }

            if (!\is_array($records)) {
                return null;
            }

            $texts = [];
            foreach ($records as $record) {
                if (!\is_string($record)) {
                    return null;
                }

                $texts[] = $record;
            }

            $result[$name] = $texts;
        }

        return $result;
    }

    private function phpBinary(): ?string
    {
        foreach ([PHP_BINDIR . DIRECTORY_SEPARATOR . 'php', PHP_BINARY] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
