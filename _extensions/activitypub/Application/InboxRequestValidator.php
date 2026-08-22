<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Extension\activitypub\Domain\ProtocolLimits;
use Register\Extension\activitypub\Security\HttpSignatureRequest;
use Register\Extension\activitypub\Security\LegacyHttpSignature;
use Register\Extension\activitypub\Security\Rfc9421HttpSignature;
use Register\Extension\activitypub\Security\SignatureVerificationFailed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Performs only bounded local work before the inbox returns 202. */
final readonly class InboxRequestValidator
{
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param null|\Closure(): int $clock */
    public function __construct(
        private LegacyHttpSignature  $legacySignature,
        private Rfc9421HttpSignature $rfc9421Signature,
        ?\Closure                    $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function validate(Request $request, string $targetUri): ValidatedInboxRequest
    {
        $this->validateContentType($request);
        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null
            && (preg_match('/^[0-9]{1,10}$/D', $contentLength) !== 1
                || (int)$contentLength > ProtocolLimits::INBOX_BODY_BYTES)
        ) {
            throw new InboxRequestException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'The ActivityPub inbox body is too large.');
        }

        $body = $request->getContent();
        if ($body === '' || \strlen($body) > ProtocolLimits::INBOX_BODY_BYTES) {
            throw new InboxRequestException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'The ActivityPub inbox body is empty or too large.');
        }

        try {
            $activity = IncomingActivity::fromJson($body);
        } catch (\InvalidArgumentException $exception) {
            throw new InboxRequestException(Response::HTTP_BAD_REQUEST, $exception->getMessage());
        }

        $headers = $this->capturedHeaders($request);
        try {
            $signatureRequest = new HttpSignatureRequest('POST', $targetUri, $headers, $body);
            [$signatureType, $keyId] = $this->signatureIdentity($signatureRequest, $headers);
        } catch (SignatureVerificationFailed | \InvalidArgumentException $exception) {
            throw new InboxRequestException(Response::HTTP_UNAUTHORIZED, $exception->getMessage());
        }

        $transportJson = json_encode([
            'method'     => 'POST',
            'target_uri' => $targetUri,
            'headers'    => $headers,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new ValidatedInboxRequest(
            $activity,
            $keyId,
            $signatureType,
            $this->httpsOrigin($activity->actorUrl),
            $body,
            $transportJson,
        );
    }

    private function validateContentType(Request $request): void
    {
        $contentType = $request->headers->get('Content-Type');
        $mediaType   = strtolower(trim(explode(';', $contentType ?? '', 2)[0]));
        if (!\in_array($mediaType, [
            'application/activity+json',
            'application/ld+json',
            'application/json',
        ], true)) {
            throw new InboxRequestException(
                Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                'The ActivityPub inbox requires an ActivityStreams JSON Content-Type.',
            );
        }
    }

    /** @return array<string, string> */
    private function capturedHeaders(Request $request): array
    {
        $headers = [];
        foreach ([
            'Host',
            'Date',
            'Digest',
            'Content-Digest',
            'Content-Type',
            'Signature',
            'Signature-Input',
        ] as $name) {
            $value = $request->headers->get($name);
            if ($value === null) {
                continue;
            }

            if (\strlen($value) > ProtocolLimits::SIGNATURE_HEADER_BYTES || preg_match('/[\r\n]/', $value) === 1) {
                throw new InboxRequestException(Response::HTTP_BAD_REQUEST, 'An ActivityPub transport header is invalid.');
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     * @return array{'legacy'|'rfc9421', string}
     */
    private function signatureIdentity(HttpSignatureRequest $request, array $headers): array
    {
        $signature      = $headers['Signature'] ?? '';
        $signatureInput = $headers['Signature-Input'] ?? null;
        if ($signatureInput !== null) {
            return ['rfc9421', $this->rfc9421Signature->selectCandidateKeyId(
                $request,
                $signatureInput,
                $signature,
                ($this->clock)(),
            )];
        }

        if ($signature === '' || !isset($headers['Digest'], $headers['Date'])) {
            throw new SignatureVerificationFailed('A legacy ActivityPub POST requires Signature, Date, and Digest headers.');
        }

        return ['legacy', $this->legacySignature->extractKeyId($signature)];
    }

    private function httpsOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !\is_string($parts['host'] ?? null)) {
            throw new InboxRequestException(Response::HTTP_BAD_REQUEST, 'The ActivityPub actor origin is invalid.');
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        return 'https://' . $host . ($port === null || $port === 443 ? '' : ':' . $port);
    }
}
