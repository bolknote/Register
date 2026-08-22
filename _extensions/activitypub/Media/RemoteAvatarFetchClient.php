<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Media;

use S2\Cms\HttpClient\Remote\SafeRemoteHttpClient;
use S2\Cms\HttpClient\Remote\SafeRemoteRequestOptions;
use S2\Cms\HttpClient\Remote\SafeRemoteResponse;
use S2\Cms\Queue\QueueExecutionBudget;

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
