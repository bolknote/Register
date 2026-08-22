<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Inbox;

use Register\Core\HttpClient\Remote\SafeRemoteHttpClient;
use Register\Core\HttpClient\Remote\SafeRemoteRequestOptions;
use Register\Core\HttpClient\Remote\SafeRemoteResponse;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Domain\ProtocolLimits;
use Register\Extension\activitypub\Security\HttpSignatureRequest;
use Register\Extension\activitypub\Security\LocalActorSigningService;

final readonly class RemoteActorFetchClient
{
    public function __construct(
        private SafeRemoteHttpClient     $httpClient,
        private LocalActorSigningService $signingService,
    )
    {
    }

    public function fetch(
        string               $url,
        QueueExecutionBudget $budget,
        ?int                 $signingActorId = null,
        ?int                 $now = null,
    ): SafeRemoteResponse
    {
        return $this->fetchDocument($url, ProtocolLimits::ACTOR_DOCUMENT_BYTES, $budget, $signingActorId, $now);
    }

    public function fetchObject(
        string               $url,
        QueueExecutionBudget $budget,
        ?int                 $signingActorId = null,
        ?int                 $now = null,
    ): SafeRemoteResponse
    {
        return $this->fetchDocument($url, ProtocolLimits::OBJECT_DOCUMENT_BYTES, $budget, $signingActorId, $now);
    }

    private function fetchDocument(
        string               $url,
        int                  $maxResponseBytes,
        QueueExecutionBudget $budget,
        ?int                 $signingActorId,
        ?int                 $now,
    ): SafeRemoteResponse {
        $headers = ['Accept' => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE . ', application/ld+json; q=0.9'];
        if ($signingActorId !== null) {
            if ($now === null || $now < 1) {
                throw new \InvalidArgumentException('A signed ActivityPub fetch requires a valid creation time.');
            }

            $signed = $this->signingService->signLegacy(
                $signingActorId,
                new HttpSignatureRequest('GET', $url, []),
                $now,
            );
            foreach ($signed->headers as $name => $value) {
                if (strtolower($name) !== 'host') {
                    $headers[$name] = $value;
                }
            }
        }

        return $this->httpClient->requestHop(
            'GET',
            $url,
            $headers,
            options: new SafeRemoteRequestOptions(
                connectTimeout: 2,
                readTimeout: 3,
                maxResponseBytes: $maxResponseBytes,
                requireHttps: true,
                deadlineSafetyMargin: 0.2,
            ),
            budget: $budget,
        );
    }
}
