<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\HttpClient\Remote;

/**
 * Uses non-blocking DNS datagrams to the host's configured recursive resolvers.
 *
 * No libc lookup, process, CLI binary, or external public resolver is involved, so the deadline is
 * enforceable inside an ordinary shared-hosting web request.
 */
final readonly class NativeHostResolver implements HostResolverInterface
{
    private const float DEFAULT_TIMEOUT_SECONDS = 1.0;

    private const int DNS_PORT = 53;

    private const int MAX_NAME_SERVERS = 3;

    private const int MAX_DATAGRAM_BYTES = 4096;

    /** @var list<string> */
    private array $nameServers;

    private DnsMessageCodec $messageCodec;

    /** @param null|list<string> $nameServers */
    public function __construct(
        ?array $nameServers = null,
        private float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?DnsMessageCodec $messageCodec = null,
    ) {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('The DNS lookup timeout must be positive and finite.');
        }

        $this->nameServers  = $nameServers ?? $this->systemNameServers();
        $this->messageCodec = $messageCodec ?? new DnsMessageCodec();
    }

    /** @return list<string> */
    #[\Override]
    public function resolve(string $host, ?float $timeoutSeconds = null): array
    {
        if ($timeoutSeconds !== null && (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0)) {
            throw new \InvalidArgumentException('The DNS lookup timeout override must be positive and finite.');
        }

        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($host === '') {
            return [];
        }

        if ($this->nameServers === [] || !\function_exists('stream_socket_client')) {
            throw new RemoteHostResolverUnavailable('No bounded system DNS transport is available.');
        }

        $firstId = random_int(0, 0xffff);
        do {
            $secondId = random_int(0, 0xffff);
        } while ($secondId === $firstId);

        $queries = [
            $firstId => [
                'type'   => DnsMessageCodec::TYPE_A,
                'packet' => $this->messageCodec->createQuery($host, DnsMessageCodec::TYPE_A, $firstId),
            ],
            $secondId => [
                'type'   => DnsMessageCodec::TYPE_AAAA,
                'packet' => $this->messageCodec->createQuery($host, DnsMessageCodec::TYPE_AAAA, $secondId),
            ],
        ];

        $timeout  = min($this->timeoutSeconds, $timeoutSeconds ?? $this->timeoutSeconds);
        $deadline = $this->monotonicNanoseconds() + $timeout * 1_000_000_000.0;
        $sockets  = $this->openSockets($queries, $deadline);
        if ($sockets === []) {
            if ($this->monotonicNanoseconds() >= $deadline) {
                throw new RemoteHostResolutionTimedOut('The system DNS resolver exceeded its hard deadline.');
            }

            throw new RemoteHostResolverUnavailable('The bounded system DNS transport cannot be opened.');
        }

        $completed = [$firstId => false, $secondId => false];
        $temporaryFailure = false;

        try {
            for ($poll = 0; $poll < 1024; ++$poll) {
                $remainingNanoseconds = $deadline - $this->monotonicNanoseconds();
                if ($remainingNanoseconds <= 0.0) {
                    break;
                }

                $read             = array_values($sockets);
                $write            = null;
                $except           = null;
                $remainingSeconds = $remainingNanoseconds / 1_000_000_000.0;
                $seconds          = (int)$remainingSeconds;
                $microseconds     = (int)(($remainingSeconds - (float)$seconds) * 1_000_000.0);
                $selected = s2_call_without_warnings(
                    static fn(): int|false => stream_select($read, $write, $except, $seconds, $microseconds),
                );
                if ($selected === false) {
                    throw new RemoteHostResolverUnavailable('The bounded system DNS transport failed while waiting.');
                }

                if ($selected === 0) {
                    break;
                }

                foreach ($read as $socket) {
                    $message = s2_call_without_warnings(
                        static fn(): string|false => fread($socket, self::MAX_DATAGRAM_BYTES),
                    );
                    if (!\is_string($message) || $message === '') {
                        unset($sockets[(int)$socket]);
                        fclose($socket);
                        continue;
                    }

                    $transactionId = $this->messageCodec->transactionId($message);
                    if ($transactionId === null || !isset($queries[$transactionId])) {
                        continue;
                    }

                    try {
                        $response = $this->messageCodec->decodeResponse(
                            $message,
                            $transactionId,
                            $host,
                            $queries[$transactionId]['type'],
                        );
                    } catch (\UnexpectedValueException) {
                        continue;
                    }

                    if ($response->status === DnsResponseStatus::ANSWER) {
                        return $response->addresses;
                    }

                    if ($response->status === DnsResponseStatus::EMPTY) {
                        $completed[$transactionId] = true;
                        if (!\in_array(false, $completed, true)) {
                            return [];
                        }
                    } else {
                        $temporaryFailure = true;
                    }
                }

                if ($sockets === []) {
                    throw new RemoteHostResolverUnavailable('All bounded system DNS transports failed.');
                }
            }
        } finally {
            foreach ($sockets as $socket) {
                fclose($socket);
            }
        }

        if ($temporaryFailure) {
            throw new RemoteHostResolverUnavailable('The system DNS resolver returned a temporary failure.');
        }

        throw new RemoteHostResolutionTimedOut('The system DNS resolver exceeded its hard deadline.');
    }

    /**
     * @param array<int, array{type: int, packet: string}> $queries
     * @return array<int, resource>
     */
    private function openSockets(array $queries, float $deadline): array
    {
        $sockets = [];
        foreach (array_slice($this->nameServers, 0, self::MAX_NAME_SERVERS) as $nameServer) {
            $remainingSeconds = ($deadline - $this->monotonicNanoseconds()) / 1_000_000_000.0;
            if ($remainingSeconds <= 0.0) {
                break;
            }

            $endpoint     = $this->endpoint($nameServer);
            $errorCode    = 0;
            $errorMessage = '';
            $socket = s2_call_without_warnings(
                static function () use ($endpoint, $remainingSeconds, &$errorCode, &$errorMessage) {
                    return stream_socket_client(
                        $endpoint,
                        $errorCode,
                        $errorMessage,
                        min(0.05, $remainingSeconds),
                        STREAM_CLIENT_CONNECT,
                    );
                },
            );
            if (!\is_resource($socket)) {
                continue;
            }

            stream_set_blocking($socket, false);
            $sent = true;
            foreach ($queries as $query) {
                $written = s2_call_without_warnings(static fn(): int|false => fwrite($socket, $query['packet']));
                if ($written !== \strlen($query['packet'])) {
                    $sent = false;
                    break;
                }
            }

            if (!$sent) {
                fclose($socket);
                continue;
            }

            $sockets[(int)$socket] = $socket;
        }

        return $sockets;
    }

    private function endpoint(string $nameServer): string
    {
        if (filter_var($nameServer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 'udp://' . $nameServer . ':' . self::DNS_PORT;
        }

        if (filter_var($nameServer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return 'udp://[' . $nameServer . ']:' . self::DNS_PORT;
        }

        if (preg_match('/^([0-9.]+):([1-9][0-9]{0,4})$/D', $nameServer, $matches) === 1
            && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && (int)$matches[2] <= 65_535
        ) {
            return 'udp://' . $nameServer;
        }

        if (preg_match('/^\[([0-9a-f:]+)]:([1-9][0-9]{0,4})$/Di', $nameServer, $matches) === 1
            && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && (int)$matches[2] <= 65_535
        ) {
            return 'udp://' . $nameServer;
        }

        throw new RemoteHostResolverUnavailable('A configured system DNS server is invalid.');
    }

    private function monotonicNanoseconds(): float
    {
        return (float)hrtime(true);
    }

    /** @return list<string> */
    private function systemNameServers(): array
    {
        $lines = s2_call_without_warnings(
            static fn(): array|false => file('/etc/resolv.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
        if (!\is_array($lines)) {
            return [];
        }

        $nameServers = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*nameserver\s+(\S+)/D', $line, $matches) !== 1) {
                continue;
            }

            $address = rtrim($matches[1], '.');
            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $nameServers[$address] = $address;
            }
        }

        return array_values($nameServers);
    }
}
