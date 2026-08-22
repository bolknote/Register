<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\HttpClient\Remote;

/** Minimal recursive DNS codec for bounded A/AAAA lookups through the host's configured resolver. */
final class DnsMessageCodec
{
    public const int TYPE_A = 1;

    public const int TYPE_AAAA = 28;

    private const int TYPE_CNAME = 5;

    private const int CLASS_IN = 1;

    private const int FLAG_RESPONSE = 0x8000;

    private const int FLAG_TRUNCATED = 0x0200;

    private const int RESPONSE_CODE_MASK = 0x000f;

    private const int RESPONSE_CODE_NAME_ERROR = 3;

    private const int MAX_POINTERS = 32;

    public function createQuery(string $host, int $type, int $transactionId): string
    {
        if (!\in_array($type, [self::TYPE_A, self::TYPE_AAAA], true)
            || $transactionId < 0
            || $transactionId > 0xffff
        ) {
            throw new \InvalidArgumentException('DNS query arguments are invalid.');
        }

        $question = '';
        foreach ($this->labels($host) as $label) {
            $question .= \chr(\strlen($label)) . $label;
        }

        return pack('nnnnnn', $transactionId, 0x0100, 1, 0, 0, 0)
            . $question . "\0" . pack('nn', $type, self::CLASS_IN);
    }

    public function transactionId(string $message): ?int
    {
        if (\strlen($message) < 2) {
            return null;
        }

        $header = unpack('ntransaction_id', substr($message, 0, 2));
        return \is_array($header) ? $header['transaction_id'] : null;
    }

    public function decodeResponse(
        string $message,
        int    $transactionId,
        string $host,
        int    $type,
    ): DnsResponse {
        if (\strlen($message) < 12) {
            throw new \UnexpectedValueException('A DNS response header is truncated.');
        }

        $header = unpack(
            'ntransaction_id/nflags/nquestions/nanswers/nauthorities/nadditionals',
            substr($message, 0, 12),
        );
        if (!\is_array($header)
            || !\is_int($header['transaction_id'])
            || !\is_int($header['flags'])
            || !\is_int($header['questions'])
            || !\is_int($header['answers'])
            || !\is_int($header['authorities'])
            || !\is_int($header['additionals'])
            || $header['transaction_id'] !== $transactionId
            || ($header['flags'] & self::FLAG_RESPONSE) === 0
            || $header['questions'] !== 1
        ) {
            throw new \UnexpectedValueException('A DNS response does not match its query.');
        }

        $offset       = 12;
        $questionName = $this->decodeName($message, $offset);
        if ($offset + 4 > \strlen($message)) {
            throw new \UnexpectedValueException('A DNS response question is truncated.');
        }

        $question = unpack('ntype/nclass', substr($message, $offset, 4));
        $offset  += 4;
        if (!\is_array($question)
            || $this->normalizeName($questionName) !== $this->normalizeName($host)
            || $question['type'] !== $type
            || $question['class'] !== self::CLASS_IN
        ) {
            throw new \UnexpectedValueException('A DNS response question does not match its query.');
        }

        $responseCode = $header['flags'] & self::RESPONSE_CODE_MASK;
        if (($header['flags'] & self::FLAG_TRUNCATED) !== 0
            || ($responseCode !== 0 && $responseCode !== self::RESPONSE_CODE_NAME_ERROR)
        ) {
            return new DnsResponse(DnsResponseStatus::TEMPORARY_FAILURE);
        }

        if ($responseCode === self::RESPONSE_CODE_NAME_ERROR) {
            return new DnsResponse(DnsResponseStatus::EMPTY);
        }

        /** @var array<string, array<string, string>> $addressesByName */
        $addressesByName = [];
        /** @var array<string, string> $canonicalNames */
        $canonicalNames  = [];
        $recordCount     = $header['answers'] + $header['authorities'] + $header['additionals'];
        for ($index = 0; $index < $recordCount; ++$index) {
            $owner = $this->normalizeName($this->decodeName($message, $offset));
            if ($offset + 10 > \strlen($message)) {
                throw new \UnexpectedValueException('A DNS resource record is truncated.');
            }

            $recordHeader = unpack('ntype/nclass/Nttl/nlength', substr($message, $offset, 10));
            $offset      += 10;
            if (!\is_array($recordHeader)
                || !\is_int($recordHeader['type'])
                || !\is_int($recordHeader['class'])
                || !\is_int($recordHeader['length'])
                || $offset + $recordHeader['length'] > \strlen($message)
            ) {
                throw new \UnexpectedValueException('DNS resource-record data is truncated.');
            }

            $dataOffset = $offset;
            $offset    += $recordHeader['length'];
            if ($recordHeader['class'] !== self::CLASS_IN) {
                continue;
            }

            if ($recordHeader['type'] === self::TYPE_A && $recordHeader['length'] === 4) {
                $this->addAddress($addressesByName, $owner, substr($message, $dataOffset, 4));
            } elseif ($recordHeader['type'] === self::TYPE_AAAA && $recordHeader['length'] === 16) {
                $this->addAddress($addressesByName, $owner, substr($message, $dataOffset, 16));
            } elseif ($recordHeader['type'] === self::TYPE_CNAME) {
                $nameOffset             = $dataOffset;
                $canonicalNames[$owner] = $this->normalizeName($this->decodeName($message, $nameOffset));
            }
        }

        $addresses = $this->addressesForChain($host, $addressesByName, $canonicalNames);
        return new DnsResponse(
            $addresses === [] ? DnsResponseStatus::EMPTY : DnsResponseStatus::ANSWER,
            $addresses,
        );
    }

    /** @return non-empty-list<non-empty-string> */
    private function labels(string $host): array
    {
        $host = $this->normalizeName($host);
        if ($host === '' || \strlen($host) > 253) {
            throw new \InvalidArgumentException('A DNS host name is invalid.');
        }

        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if ($label === '' || \strlen($label) > 63 || preg_match('/^[a-z0-9_-]+$/Di', $label) !== 1) {
                throw new \InvalidArgumentException('A DNS host label is invalid.');
            }
        }

        /** @var non-empty-list<non-empty-string> $labels */
        return $labels;
    }

    private function decodeName(string $message, int &$offset): string
    {
        $labels  = [];
        $cursor  = $offset;
        $jumped  = false;
        $visited = [];

        for ($part = 0; $part < self::MAX_POINTERS; ++$part) {
            if ($cursor >= \strlen($message)) {
                throw new \UnexpectedValueException('A DNS name is truncated.');
            }

            $length = \ord($message[$cursor]);
            if (($length & 0xc0) === 0xc0) {
                if ($cursor + 1 >= \strlen($message)) {
                    throw new \UnexpectedValueException('A DNS compression pointer is truncated.');
                }

                $pointer = (($length & 0x3f) << 8) | \ord($message[$cursor + 1]);
                if (isset($visited[$pointer])) {
                    throw new \UnexpectedValueException('A DNS compression pointer contains a loop.');
                }

                $visited[$pointer] = true;
                if (!$jumped) {
                    $offset = $cursor + 2;
                }

                $cursor = $pointer;
                $jumped = true;
                continue;
            }

            if (($length & 0xc0) !== 0 || $length > 63) {
                throw new \UnexpectedValueException('A DNS name label is invalid.');
            }

            if ($length === 0) {
                if (!$jumped) {
                    $offset = $cursor + 1;
                }

                return implode('.', $labels);
            }

            ++$cursor;
            if ($cursor + $length > \strlen($message)) {
                throw new \UnexpectedValueException('A DNS name label is truncated.');
            }

            $labels[] = substr($message, $cursor, $length);
            $cursor  += $length;
            if (!$jumped) {
                $offset = $cursor;
            }
        }

        throw new \UnexpectedValueException('A DNS name has too many compression pointers.');
    }

    /** @param array<string, array<string, string>> $addressesByName */
    private function addAddress(array &$addressesByName, string $owner, string $packedAddress): void
    {
        $address = inet_ntop($packedAddress);
        if ($address !== false) {
            $addressesByName[$owner][$address] = $address;
        }
    }

    /**
     * @param array<string, array<string, string>> $addressesByName
     * @param array<string, string> $canonicalNames
     * @return list<string>
     */
    private function addressesForChain(string $host, array $addressesByName, array $canonicalNames): array
    {
        $current   = $this->normalizeName($host);
        $addresses = [];
        $visited   = [];
        for ($depth = 0; $depth < 16; ++$depth) {
            if (isset($visited[$current])) {
                break;
            }

            $visited[$current] = true;

            foreach ($addressesByName[$current] ?? [] as $address) {
                $addresses[$address] = $address;
            }

            if (!isset($canonicalNames[$current])) {
                break;
            }

            $current = $canonicalNames[$current];
        }

        return array_values($addresses);
    }

    private function normalizeName(string $name): string
    {
        return strtolower(rtrim($name, '.'));
    }
}
