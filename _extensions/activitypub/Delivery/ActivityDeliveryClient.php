<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Delivery;

use Register\Core\HttpClient\Remote\SafeRemoteHttpClient;
use Register\Core\HttpClient\Remote\SafeRemoteRequestOptions;
use Register\Core\HttpClient\Remote\SafeRemoteResponse;
use Register\Core\Queue\QueueExecutionBudget;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Infrastructure\ClaimedDelivery;
use Register\Extension\activitypub\Security\HttpSignatureRequest;
use Register\Extension\activitypub\Security\LocalActorSigningService;

/** Performs one DNS-pinned, freshly signed HTTP hop. Redirects are persisted by the caller. */
final readonly class ActivityDeliveryClient
{
    public function __construct(
        private SafeRemoteHttpClient     $httpClient,
        private LocalActorSigningService $signingService,
    ) {
    }

    public function send(
        ClaimedDelivery     $delivery,
        QueueExecutionBudget $budget,
        int                 $now,
    ): SafeRemoteResponse {
        if (!hash_equals($delivery->activityBodyHash, hash('sha256', $delivery->activityBody))) {
            throw new \RuntimeException('The immutable ActivityPub activity body hash no longer matches.');
        }

        $request = new HttpSignatureRequest(
            'POST',
            $delivery->requestUrl,
            ['Content-Type' => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE],
            $delivery->activityBody,
        );
        $signed  = $this->signingService->signLegacy($delivery->actorId, $request, $now);
        $headers = [
            'Accept'       => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE,
            'Content-Type' => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE,
        ];
        foreach ($signed->headers as $name => $value) {
            if (strtolower($name) !== 'host') {
                $headers[$name] = $value;
            }
        }

        return $this->httpClient->requestHop(
            'POST',
            $delivery->requestUrl,
            $headers,
            $delivery->activityBody,
            new SafeRemoteRequestOptions(
                connectTimeout: 2,
                readTimeout: 3,
                maxResponseBytes: 65_536,
                requireHttps: true,
                deadlineSafetyMargin: 0.2,
            ),
            $budget,
        );
    }
}
