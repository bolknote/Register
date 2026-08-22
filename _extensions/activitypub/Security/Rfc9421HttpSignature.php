<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Security;

/** RFC 9421 rsa-v1_5-sha256 profile with RFC 9530 Content-Digest binding. */
final readonly class Rfc9421HttpSignature
{
    private const string LABEL = 'sig1';

    private const int MAX_HEADER_BYTES = 16_384;

    private const int MAX_SIGNATURES = 5;

    private const int MAX_COMPONENTS = 16;

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
        $covered = ['@method', '@target-uri'];
        if ($request->requiresBodyDigest()) {
            $contentType = $request->header('content-type');
            if ($contentType === null || $contentType === '') {
                throw new \InvalidArgumentException('RFC 9421 signed requests with a body require Content-Type.');
            }

            $headers['Content-Digest'] = $this->contentDigest($request->body);
            $covered[]                 = 'content-digest';
            $covered[]                 = 'content-type';
        }

        $expiresAt       = $timestamp + self::MAX_CLOCK_SKEW_SECONDS;
        $signatureParams = $this->serializeSignatureParameters($covered, [
            ['created', (string)$timestamp],
            ['expires', (string)$expiresAt],
            ['keyid', '"' . $this->escapeSfString($keyId) . '"'],
            ['alg', '"rsa-v1_5-sha256"'],
        ]);
        $prepared      = $request->withHeaders($headers);
        $signatureBase = $this->signatureBase($prepared, $covered, $signatureParams);
        $signature     = base64_encode($this->rsaCrypto->sign($privateKeyPem, $signatureBase));

        $headers['Signature-Input'] = self::LABEL . '=' . $signatureParams;
        $headers['Signature']       = self::LABEL . '=:' . $signature . ':';

        return new SignedHttpHeaders($headers, $signatureBase);
    }

    public function verify(
        HttpSignatureRequest $request,
        string               $signatureInputHeader,
        string               $signatureHeader,
        string               $publicKeyPem,
        ?int                 $now = null,
        ?string              $expectedKeyId = null,
    ): VerifiedHttpSignature {
        $verificationTime = $now ?? time();
        $candidates       = $this->parseCandidates($request, $signatureInputHeader, $signatureHeader);
        $lastFailure      = 'No supported RFC 9421 signature candidate was found.';
        foreach ($candidates as $candidate) {
            if ($expectedKeyId !== null && !hash_equals($expectedKeyId, $candidate->keyId)) {
                continue;
            }

            try {
                $this->validateCandidate($request, $candidate, $verificationTime);
            } catch (SignatureVerificationFailed $exception) {
                $lastFailure = $exception->getMessage();
                continue;
            }

            if ($this->rsaCrypto->verify($publicKeyPem, $candidate->signatureBase, $candidate->signature)) {
                return new VerifiedHttpSignature(
                    HttpSignatureKind::RFC_9421,
                    $candidate->keyId,
                    $candidate->coveredComponents,
                    $candidate->createdAt,
                    $candidate->expiresAt,
                    $candidate->label,
                );
            }

            $lastFailure = 'The RFC 9421 signature is cryptographically invalid.';
        }

        throw new SignatureVerificationFailed($lastFailure);
    }

    /**
     * Performs bounded, non-cryptographic preflight and rejects ambiguous valid key identities.
     */
    public function selectCandidateKeyId(
        HttpSignatureRequest $request,
        string               $signatureInputHeader,
        string               $signatureHeader,
        ?int                 $now = null,
    ): string {
        $verificationTime = $now ?? time();
        $keyIds = [];
        $lastFailure = 'No supported and valid RFC 9421 signature candidate was supplied.';
        foreach ($this->parseCandidates($request, $signatureInputHeader, $signatureHeader) as $candidate) {
            try {
                $this->validateCandidate($request, $candidate, $verificationTime);
            } catch (SignatureVerificationFailed $exception) {
                $lastFailure = $exception->getMessage();
                continue;
            }

            $keyIds[$candidate->keyId] = $candidate->keyId;
        }

        if ($keyIds === []) {
            throw new SignatureVerificationFailed($lastFailure);
        }

        if (\count($keyIds) !== 1) {
            throw new SignatureVerificationFailed('Valid RFC 9421 signature candidates identify more than one key.');
        }

        return array_values($keyIds)[0];
    }

    /** @return list<Rfc9421SignatureCandidate> */
    public function parseCandidates(
        HttpSignatureRequest $request,
        string               $signatureInputHeader,
        string               $signatureHeader,
    ): array {
        $inputMembers     = $this->parseDictionary($signatureInputHeader, 'Signature-Input');
        $signatureMembers = $this->parseDictionary($signatureHeader, 'Signature');
        $inputLabels     = array_keys($inputMembers);
        $signatureLabels = array_keys($signatureMembers);
        sort($inputLabels);
        sort($signatureLabels);
        if ($inputLabels !== $signatureLabels) {
            throw new SignatureVerificationFailed('RFC 9421 Signature-Input and Signature labels do not match.');
        }

        $candidates = [];
        foreach ($inputMembers as $label => $inputValue) {
            [$components, $parameters, $serializedParameters] = $this->parseSignatureInput($inputValue);
            $createdAt = $this->requiredIntegerParameter($parameters, 'created');
            $expiresAt = isset($parameters['expires'])
                ? $this->requiredIntegerParameter($parameters, 'expires')
                : null;
            $keyId = $this->requiredStringParameter($parameters, 'keyid');
            $this->validateKeyId($keyId);
            if ($this->requiredStringParameter($parameters, 'alg') !== 'rsa-v1_5-sha256') {
                continue;
            }

            $signature = $this->parseByteSequence($signatureMembers[$label]);
            $candidates[] = new Rfc9421SignatureCandidate(
                $label,
                $keyId,
                $components,
                $createdAt,
                $expiresAt,
                $this->signatureBase($request, $components, $serializedParameters),
                $signature,
            );
        }

        return $candidates;
    }

    private function validateCandidate(
        HttpSignatureRequest          $request,
        Rfc9421SignatureCandidate     $candidate,
        int                           $now,
    ): void {
        $required = ['@method', '@target-uri'];
        if ($request->requiresBodyDigest()) {
            $required[] = 'content-digest';
            $required[] = 'content-type';
        }

        if (array_diff($required, $candidate->coveredComponents) !== []) {
            throw new SignatureVerificationFailed('The RFC 9421 signature does not cover all required components.');
        }

        if ($candidate->createdAt > $now + self::MAX_CLOCK_SKEW_SECONDS
            || $candidate->createdAt < $now - self::MAX_CLOCK_SKEW_SECONDS
        ) {
            throw new SignatureVerificationFailed('The RFC 9421 signature is outside the accepted creation window.');
        }

        if ($candidate->expiresAt !== null
            && ($candidate->expiresAt < $candidate->createdAt
                || $candidate->expiresAt < $now - self::MAX_CLOCK_SKEW_SECONDS)
        ) {
            throw new SignatureVerificationFailed('The RFC 9421 signature has expired or has an invalid lifetime.');
        }

        if ($request->requiresBodyDigest()) {
            $digest = $request->header('content-digest');
            if ($digest === null || !$this->verifyContentDigest($digest, $request->body)) {
                throw new SignatureVerificationFailed('The RFC 9530 HTTP body digest is invalid.');
            }
        }
    }

    /**
     * @param list<string> $covered
     * @param list<array{string, string}> $parameters
     */
    private function serializeSignatureParameters(array $covered, array $parameters): string
    {
        $serialized = '(' . implode(' ', array_map(
            fn(string $component): string => '"' . $this->escapeSfString($component) . '"',
            $covered,
        )) . ')';
        foreach ($parameters as [$name, $value]) {
            $serialized .= ';' . $name . '=' . $value;
        }

        return $serialized;
    }

    /** @param list<string> $covered */
    private function signatureBase(
        HttpSignatureRequest $request,
        array                $covered,
        string               $serializedParameters,
    ): string {
        $lines = [];
        foreach ($covered as $component) {
            $lines[] = '"' . $this->escapeSfString($component) . '": ' . $this->componentValue($request, $component);
        }

        $lines[] = '"@signature-params": ' . $serializedParameters;

        return implode("\n", $lines);
    }

    private function componentValue(HttpSignatureRequest $request, string $component): string
    {
        $path = parse_url($request->targetUri, PHP_URL_PATH);
        if (!\is_string($path) || $path === '') {
            $path = '/';
        }

        return match ($component) {
            '@method'         => $request->method,
            '@target-uri'     => $request->targetUri,
            '@authority'      => $request->authority,
            '@scheme'         => (string)parse_url($request->targetUri, PHP_URL_SCHEME),
            '@path'           => $path,
            '@request-target' => $request->requestTarget,
            default           => $request->header($component)
                ?? throw new SignatureVerificationFailed('A covered RFC 9421 HTTP field is absent.'),
        };
    }

    /** @return array<string, string> */
    private function parseDictionary(string $header, string $name): array
    {
        if ($header === '' || \strlen($header) > self::MAX_HEADER_BYTES) {
            throw new SignatureVerificationFailed('The RFC 9421 ' . $name . ' field has an invalid size.');
        }

        $members = $this->splitDictionaryMembers($header);
        if ($members === [] || \count($members) > self::MAX_SIGNATURES) {
            throw new SignatureVerificationFailed('The RFC 9421 ' . $name . ' field has an invalid member count.');
        }

        $result = [];
        foreach ($members as $member) {
            if (preg_match('/^([a-z*][a-z0-9_.*-]*)=(.+)$/D', $member, $match) !== 1
                || isset($result[$match[1]])
            ) {
                throw new SignatureVerificationFailed('The RFC 9421 ' . $name . ' dictionary is malformed or duplicated.');
            }

            $result[$match[1]] = $match[2];
        }

        return $result;
    }

    /** @return list<string> */
    private function splitDictionaryMembers(string $header): array
    {
        $members      = [];
        $start        = 0;
        $quoted       = false;
        $escaped      = false;
        $byteSequence = false;
        $parentheses  = 0;
        $length       = \strlen($header);
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $header[$offset];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }

                continue;
            }

            if ($character === '"') {
                $quoted = true;
            } elseif ($character === ':' && $parentheses === 0) {
                $byteSequence = !$byteSequence;
            } elseif (!$byteSequence && $character === '(') {
                ++$parentheses;
            } elseif (!$byteSequence && $character === ')') {
                --$parentheses;
                if ($parentheses < 0) {
                    throw new SignatureVerificationFailed('An RFC 9421 structured field has unbalanced parentheses.');
                }
            } elseif (!$byteSequence && $parentheses === 0 && $character === ',') {
                $member = trim(substr($header, $start, $offset - $start), " \t");
                if ($member === '') {
                    throw new SignatureVerificationFailed('An RFC 9421 structured field has an empty dictionary member.');
                }

                $members[] = $member;
                $start     = $offset + 1;
            }
        }

        if ($quoted || $escaped || $byteSequence || $parentheses !== 0) {
            throw new SignatureVerificationFailed('An RFC 9421 structured field is unterminated.');
        }

        $member = trim(substr($header, $start), " \t");
        if ($member === '') {
            throw new SignatureVerificationFailed('An RFC 9421 structured field has a trailing separator.');
        }

        $members[] = $member;

        return $members;
    }

    /**
     * @return array{
     *     list<string>,
     *     array<string, array{type: 'integer'|'string'|'token'|'boolean', value: int|string|bool, serialized: string}>,
     *     string
     * }
     */
    private function parseSignatureInput(string $value): array
    {
        if (!str_starts_with($value, '(')) {
            throw new SignatureVerificationFailed('An RFC 9421 signature input is not an inner list.');
        }

        $closing = $this->findClosingParenthesis($value);
        $components = $this->parseComponentList(substr($value, 1, $closing - 1));
        $parameters = $this->parseParameters(substr($value, $closing + 1));
        $serialized = $this->serializeSignatureParameters(
            $components,
            array_map(
                static fn(string $name, array $parameter): array => [$name, $parameter['serialized']],
                array_keys($parameters),
                array_values($parameters),
            ),
        );

        return [$components, $parameters, $serialized];
    }

    private function findClosingParenthesis(string $value): int
    {
        $quoted  = false;
        $escaped = false;
        $length  = \strlen($value);
        for ($offset = 1; $offset < $length; ++$offset) {
            $character = $value[$offset];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }
            } elseif ($character === '"') {
                $quoted = true;
            } elseif ($character === ')') {
                return $offset;
            } elseif ($character === '(') {
                throw new SignatureVerificationFailed('Nested RFC 9421 inner lists are invalid.');
            }
        }

        throw new SignatureVerificationFailed('The RFC 9421 signature input inner list is unterminated.');
    }

    /** @return list<string> */
    private function parseComponentList(string $value): array
    {
        $components = [];
        $offset     = 0;
        $length     = \strlen($value);
        while ($offset < $length) {
            if ($components !== []) {
                if ($value[$offset] !== ' ') {
                    throw new SignatureVerificationFailed('RFC 9421 component identifiers must use canonical spacing.');
                }

                while ($offset < $length && $value[$offset] === ' ') {
                    ++$offset;
                }
            }

            [$component, $offset] = $this->parseSfStringAt($value, $offset);
            if (preg_match('/^(?:@[a-z0-9_-]+|[a-z0-9!#$%&\'*+.^_`|~-]+)$/D', $component) !== 1
                || \in_array($component, $components, true)
            ) {
                throw new SignatureVerificationFailed('An RFC 9421 component identifier is invalid or duplicated.');
            }

            $components[] = $component;
            if (\count($components) > self::MAX_COMPONENTS) {
                throw new SignatureVerificationFailed('The RFC 9421 signature covers too many components.');
            }
        }

        if ($components === []) {
            throw new SignatureVerificationFailed('The RFC 9421 signature covers no components.');
        }

        return $components;
    }

    /**
     * @return array<string, array{type: 'integer'|'string'|'token'|'boolean', value: int|string|bool, serialized: string}>
     */
    private function parseParameters(string $value): array
    {
        $parameters = [];
        $offset     = 0;
        $length     = \strlen($value);
        while ($offset < $length) {
            if ($value[$offset++] !== ';'
                || preg_match('/\G([a-z*][a-z0-9_.*-]*)=/A', $value, $match, 0, $offset) !== 1
            ) {
                throw new SignatureVerificationFailed('The RFC 9421 signature parameters are malformed.');
            }

            $name    = $match[1];
            $offset += \strlen($match[0]);
            if (isset($parameters[$name]) || \count($parameters) >= self::MAX_COMPONENTS) {
                throw new SignatureVerificationFailed('An RFC 9421 signature parameter is duplicated or excessive.');
            }

            if ($offset < $length && $value[$offset] === '"') {
                [$parsed, $offset] = $this->parseSfStringAt($value, $offset);
                $parameters[$name] = [
                    'type'       => 'string',
                    'value'      => $parsed,
                    'serialized' => '"' . $this->escapeSfString($parsed) . '"',
                ];
            } elseif (preg_match('/\G-?(?:0|[1-9][0-9]*)/A', $value, $integer, 0, $offset) === 1) {
                $offset += \strlen($integer[0]);
                $parsed = filter_var($integer[0], FILTER_VALIDATE_INT);
                if (!\is_int($parsed) || (string)$parsed !== $integer[0]) {
                    throw new SignatureVerificationFailed('An RFC 9421 integer parameter is invalid.');
                }

                $parameters[$name] = ['type' => 'integer', 'value' => $parsed, 'serialized' => $integer[0]];
            } elseif (preg_match('/\G\?([01])/A', $value, $boolean, 0, $offset) === 1) {
                $offset += \strlen($boolean[0]);
                $parameters[$name] = [
                    'type'       => 'boolean',
                    'value'      => $boolean[1] === '1',
                    'serialized' => $boolean[0],
                ];
            } elseif (preg_match('/\G([A-Za-z*][A-Za-z0-9_.*:\/-]*)/A', $value, $token, 0, $offset) === 1) {
                $offset += \strlen($token[0]);
                $parameters[$name] = ['type' => 'token', 'value' => $token[0], 'serialized' => $token[0]];
            } else {
                throw new SignatureVerificationFailed('An RFC 9421 signature parameter value is unsupported.');
            }
        }

        return $parameters;
    }

    /** @return array{string, int} */
    private function parseSfStringAt(string $value, int $offset): array
    {
        $length = \strlen($value);
        if ($offset >= $length || $value[$offset++] !== '"') {
            throw new SignatureVerificationFailed('An RFC 9421 structured-field string is expected.');
        }

        $parsed = '';
        while ($offset < $length) {
            $character = $value[$offset++];
            if ($character === '"') {
                return [$parsed, $offset];
            }

            if ($character === '\\') {
                if ($offset >= $length || !\in_array($value[$offset], ['"', '\\'], true)) {
                    throw new SignatureVerificationFailed('An RFC 9421 structured-field escape is invalid.');
                }

                $character = $value[$offset++];
            }

            $ordinal = ord($character);
            if ($ordinal < 0x20 || $ordinal > 0x7e) {
                throw new SignatureVerificationFailed('An RFC 9421 structured-field string contains an invalid byte.');
            }

            $parsed .= $character;
        }

        throw new SignatureVerificationFailed('An RFC 9421 structured-field string is unterminated.');
    }

    /**
     * @param array<string, array{type: 'integer'|'string'|'token'|'boolean', value: int|string|bool, serialized: string}> $parameters
     */
    private function requiredIntegerParameter(array $parameters, string $name): int
    {
        $parameter = $parameters[$name] ?? null;
        if (!\is_array($parameter) || $parameter['type'] !== 'integer' || !\is_int($parameter['value'])) {
            throw new SignatureVerificationFailed('The RFC 9421 ' . $name . ' parameter must be an integer.');
        }

        return $parameter['value'];
    }

    /**
     * @param array<string, array{type: 'integer'|'string'|'token'|'boolean', value: int|string|bool, serialized: string}> $parameters
     */
    private function requiredStringParameter(array $parameters, string $name): string
    {
        $parameter = $parameters[$name] ?? null;
        if (!\is_array($parameter)
            || !\in_array($parameter['type'], ['string', 'token'], true)
            || !\is_string($parameter['value'])
        ) {
            throw new SignatureVerificationFailed('The RFC 9421 ' . $name . ' parameter must be a string or token.');
        }

        return $parameter['value'];
    }

    private function parseByteSequence(string $value): string
    {
        if (preg_match('/^:([A-Za-z0-9+\/]*={0,2}):$/D', $value, $match) !== 1) {
            throw new SignatureVerificationFailed('The RFC 9421 Signature value is not a byte sequence.');
        }

        $decoded = base64_decode($match[1], true);
        if ($decoded === false || !hash_equals(base64_encode($decoded), $match[1])) {
            throw new SignatureVerificationFailed('The RFC 9421 Signature byte sequence is not canonical base64.');
        }

        return $decoded;
    }

    private function contentDigest(string $body): string
    {
        return 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
    }

    private function verifyContentDigest(string $header, string $body): bool
    {
        foreach (explode(',', $header) as $member) {
            if (preg_match('/^\s*sha-256=:([A-Za-z0-9+\/]+={0,2}):\s*$/D', $member, $match) !== 1) {
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

    private function escapeSfString(string $value): string
    {
        if (preg_match('/[^\x20-\x7e]/', $value) === 1) {
            throw new SignatureVerificationFailed('An RFC 9421 structured-field string contains an invalid byte.');
        }

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
