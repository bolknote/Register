<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Security;

/** Fediverse-compatible cavage-style HTTP Signatures with strict coverage and digest policy. */
final readonly class LegacyHttpSignature
{
    private const int MAX_HEADER_BYTES = 8_192;

    private const int MAX_CLOCK_SKEW_SECONDS = 5 * 60;

    public function __construct(private RsaCrypto $rsaCrypto)
    {
    }

    public function sign(
        HttpSignatureRequest $request,
        string               $keyId,
        string               $privateKeyPem,
        ?int                 $createdAt = null,
    ): SignedHttpHeaders {
        $this->validateKeyId($keyId);
        $timestamp = $createdAt ?? time();
        $headers   = [
            'Host' => $request->authority,
            'Date' => gmdate('D, d M Y H:i:s \G\M\T', $timestamp),
        ];
        $covered = ['(request-target)', 'host', 'date'];
        if ($request->requiresBodyDigest()) {
            $headers['Digest'] = 'SHA-256=' . base64_encode(hash('sha256', $request->body, true));
            $covered[]         = 'digest';
        }

        $prepared      = $request->withHeaders($headers);
        $signatureBase = $this->signatureBase($prepared, $covered);
        $signature     = base64_encode($this->rsaCrypto->sign($privateKeyPem, $signatureBase));
        $headers['Signature'] = 'keyId="' . $this->escapeQuotedString($keyId)
            . '",algorithm="rsa-sha256",headers="' . implode(' ', $covered)
            . '",signature="' . $signature . '"';

        return new SignedHttpHeaders($headers, $signatureBase);
    }

    public function verify(
        HttpSignatureRequest $request,
        string               $signatureHeader,
        string               $publicKeyPem,
        ?int                 $now = null,
    ): VerifiedHttpSignature {
        $parameters = $this->parseParameters($signatureHeader);
        $keyId      = $parameters['keyid'] ?? null;
        $signature  = $parameters['signature'] ?? null;
        $headerList = $parameters['headers'] ?? null;
        $algorithm  = strtolower($parameters['algorithm'] ?? 'rsa-sha256');
        if ($keyId === null || $signature === null || $headerList === null) {
            throw new SignatureVerificationFailed('The legacy HTTP Signature header is incomplete.');
        }

        $this->validateKeyId($keyId);
        if (!\in_array($algorithm, ['rsa-sha256', 'hs2019'], true)) {
            throw new SignatureVerificationFailed('The legacy HTTP signature algorithm is unsupported.');
        }

        $covered = preg_split('/ +/', strtolower(trim($headerList)), -1, PREG_SPLIT_NO_EMPTY);
        if (!\is_array($covered) || $covered === [] || \count($covered) !== \count(array_unique($covered))) {
            throw new SignatureVerificationFailed('The legacy HTTP signature component list is invalid.');
        }

        $required = ['(request-target)', 'host', 'date'];
        if ($request->requiresBodyDigest()) {
            $required[] = 'digest';
        }

        if (array_diff($required, $covered) !== []) {
            throw new SignatureVerificationFailed('The legacy HTTP signature does not cover all required components.');
        }

        $host = $request->header('host');
        if ($host === null || !hash_equals(strtolower($request->authority), strtolower($host))) {
            throw new SignatureVerificationFailed('The signed Host header does not match the target URI.');
        }

        $date = $request->header('date');
        if ($date === null) {
            throw new SignatureVerificationFailed('The signed HTTP Date header is invalid.');
        }

        $createdAt = strtotime($date);
        if ($createdAt === false) {
            throw new SignatureVerificationFailed('The signed HTTP Date header is invalid.');
        }

        $verificationTime = $now ?? time();
        if (abs($verificationTime - $createdAt) > self::MAX_CLOCK_SKEW_SECONDS) {
            throw new SignatureVerificationFailed('The legacy HTTP signature is outside the accepted clock window.');
        }

        if ($request->requiresBodyDigest()) {
            $digest = $request->header('digest');
            if ($digest === null || !$this->verifyDigest($digest, $request->body)) {
                throw new SignatureVerificationFailed('The legacy HTTP body digest is invalid.');
            }
        }

        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false || !hash_equals(base64_encode($decodedSignature), $signature)) {
            throw new SignatureVerificationFailed('The legacy HTTP signature encoding is invalid.');
        }

        $signatureBase = $this->signatureBase($request, $covered);
        if (!$this->rsaCrypto->verify($publicKeyPem, $signatureBase, $decodedSignature)) {
            throw new SignatureVerificationFailed('The legacy HTTP signature is cryptographically invalid.');
        }

        return new VerifiedHttpSignature(HttpSignatureKind::LEGACY, $keyId, $covered, $createdAt);
    }

    /** Performs bounded structural parsing only; cryptographic verification remains in verify(). */
    public function extractKeyId(string $signatureHeader): string
    {
        $keyId = $this->parseParameters($signatureHeader)['keyid'] ?? null;
        if ($keyId === null) {
            throw new SignatureVerificationFailed('The legacy HTTP Signature header has no keyId.');
        }

        $this->validateKeyId($keyId);

        return $keyId;
    }

    /** @param list<string> $covered */
    private function signatureBase(HttpSignatureRequest $request, array $covered): string
    {
        $lines = [];
        foreach ($covered as $component) {
            if ($component === '(request-target)') {
                $value = strtolower($request->method) . ' ' . $request->requestTarget;
            } elseif (preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $component) === 1) {
                $value = $request->header($component);
                if ($value === null) {
                    throw new SignatureVerificationFailed('A covered legacy HTTP header is absent.');
                }
            } else {
                throw new SignatureVerificationFailed('A legacy HTTP signature component is unsupported.');
            }

            $lines[] = $component . ': ' . $value;
        }

        return implode("\n", $lines);
    }

    /** @return array<string, string> */
    private function parseParameters(string $header): array
    {
        if ($header === '' || \strlen($header) > self::MAX_HEADER_BYTES) {
            throw new SignatureVerificationFailed('The legacy HTTP Signature header has an invalid size.');
        }

        $result = [];
        $offset = 0;
        $length = \strlen($header);
        while ($offset < $length) {
            while ($offset < $length && ($header[$offset] === ' ' || $header[$offset] === "\t")) {
                ++$offset;
            }

            if (preg_match('/\G([A-Za-z][A-Za-z0-9_-]*)=/A', $header, $match, 0, $offset) !== 1) {
                throw new SignatureVerificationFailed('The legacy HTTP Signature parameters are malformed.');
            }

            $name    = strtolower($match[1]);
            $offset += \strlen($match[0]);
            if ($offset >= $length || $header[$offset] !== '"' || isset($result[$name])) {
                throw new SignatureVerificationFailed('The legacy HTTP Signature parameters are malformed or duplicated.');
            }

            ++$offset;

            $value = '';
            $closed = false;
            while ($offset < $length) {
                $character = $header[$offset++];
                if ($character === '"') {
                    $closed = true;
                    break;
                }

                if ($character === '\\') {
                    if ($offset >= $length || !\in_array($header[$offset], ['"', '\\'], true)) {
                        throw new SignatureVerificationFailed('The legacy HTTP Signature escape is invalid.');
                    }

                    $character = $header[$offset++];
                }

                if (ord($character) < 0x20 || ord($character) === 0x7f) {
                    throw new SignatureVerificationFailed('The legacy HTTP Signature contains a control character.');
                }

                $value .= $character;
            }

            if (!$closed) {
                throw new SignatureVerificationFailed('The legacy HTTP Signature quoted value is unterminated.');
            }

            $result[$name] = $value;

            while ($offset < $length && ($header[$offset] === ' ' || $header[$offset] === "\t")) {
                ++$offset;
            }

            if ($offset === $length) {
                break;
            }

            if ($header[$offset++] !== ',') {
                throw new SignatureVerificationFailed('The legacy HTTP Signature parameter separator is invalid.');
            }

            if ($offset === $length) {
                throw new SignatureVerificationFailed('The legacy HTTP Signature has a trailing separator.');
            }
        }

        return $result;
    }

    private function verifyDigest(string $header, string $body): bool
    {
        foreach (explode(',', $header) as $member) {
            if (preg_match('/^\s*sha-256=([A-Za-z0-9+\/]+={0,2})\s*$/Di', $member, $match) !== 1) {
                continue;
            }

            $decoded = base64_decode($match[1], true);
            if ($decoded !== false
                && \strlen($decoded) === 32
                && hash_equals(base64_encode($decoded), $match[1])
                && hash_equals(hash('sha256', $body, true), $decoded)
            ) {
                return true;
            }
        }

        return false;
    }

    private function validateKeyId(string $keyId): void
    {
        $parts = parse_url($keyId);
        if (\strlen($keyId) > 2_048
            || !\is_array($parts)
            || !\in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !\is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f]/', $keyId) === 1
        ) {
            throw new SignatureVerificationFailed('The HTTP signature key identifier is invalid.');
        }
    }

    private function escapeQuotedString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
