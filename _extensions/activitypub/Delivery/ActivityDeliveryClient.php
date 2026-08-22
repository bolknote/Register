<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Delivery;

use S2\Cms\HttpClient\Remote\SafeRemoteHttpClient;
use S2\Cms\HttpClient\Remote\SafeRemoteRequestOptions;
use S2\Cms\HttpClient\Remote\SafeRemoteResponse;
use S2\Cms\Queue\QueueExecutionBudget;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Infrastructure\ClaimedDelivery;
use s2_extensions\activitypub\Security\HttpSignatureRequest;
use s2_extensions\activitypub\Security\LocalActorSigningService;

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
