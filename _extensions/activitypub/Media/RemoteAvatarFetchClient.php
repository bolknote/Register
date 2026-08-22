<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Media;

use Register\Core\HttpClient\Remote\SafeRemoteHttpClient;
use Register\Core\HttpClient\Remote\SafeRemoteRequestOptions;
use Register\Core\HttpClient\Remote\SafeRemoteResponse;
use Register\Core\Queue\QueueExecutionBudget;

final readonly class RemoteAvatarFetchClient
{
    public function __construct(private SafeRemoteHttpClient $httpClient)
    {
    }

    public function fetch(
        string               $url,
        ?string              $etag,
        ?string              $lastModified,
        QueueExecutionBudget $budget,
    ): SafeRemoteResponse {
        $headers = ['Accept' => 'image/png, image/jpeg; q=0.95, image/webp; q=0.9'];
        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }

        if ($lastModified !== null) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        return $this->httpClient->requestHop(
            'GET',
            $url,
            $headers,
            options: new SafeRemoteRequestOptions(
                connectTimeout: 2,
                readTimeout: 3,
                maxResponseBytes: RemoteAvatarImageInspector::MAX_BYTES + 1,
                requireHttps: true,
                deadlineSafetyMargin: 0.2,
            ),
            budget: $budget,
        );
    }
}
