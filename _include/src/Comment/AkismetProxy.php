<?php
/**
 * @copyright 2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment;

use Psr\Log\LoggerInterface;
use S2\Cms\Config\StringProxy;
use S2\Cms\HttpClient\HttpClient;
use S2\Cms\HttpClient\HttpClientException;
use S2\Cms\Model\UrlBuilder;

readonly class AkismetProxy implements SpamDetectorInterface
{
    private const string SERVICE_ENDPOINT = "https://rest.akismet.com/1.1/comment-check";

    private const string TYPE_COMMENT     = 'comment';

    public function __construct(
        private HttpClient      $httpClient,
        private UrlBuilder      $urlBuilder,
        private LoggerInterface $logger,
        private StringProxy     $apiKey,
    ) {
    }

    #[\Override]
    public function getReport(SpamDetectorComment $comment, string $clientIp): SpamDetectorReport
    {
        $apiKey = $this->apiKey->get();
        if ($apiKey === '') {
            return SpamDetectorReport::disabled();
        }

        $data = [
            'api_key'              => $apiKey,
            'blog'                 => $this->urlBuilder->rawAbsLink('/'),
            'user_ip'              => $clientIp,
            'comment_type'         => self::TYPE_COMMENT,
            'comment_author'       => $comment->name,
            'comment_author_email' => $comment->email,
            'comment_content'      => $comment->text,
        ];
        if ($comment->userAgent !== null) {
            $data['user_agent'] = $comment->userAgent;
        }

        if ($comment->referrer !== null) {
            $data['referrer'] = $comment->referrer;
        }

        if ($comment->permalink !== null) {
            $data['permalink'] = $comment->permalink;
        }

        $this->logger->info('Sending comment to Akismet', [
            'permalink'      => $comment->permalink,
            'has_user_agent' => $comment->userAgent !== null,
            'has_referrer'   => $comment->referrer !== null,
        ]);
        try {
            $response = $this->httpClient->post(self::SERVICE_ENDPOINT, $data, [
                HttpClient::CONNECT_TIMEOUT => 2,
                HttpClient::READ_TIMEOUT    => 2,
            ]);
        } catch (HttpClientException $httpClientException) {
            $this->logger->error(\sprintf('Error requesting Akismet: %s', $httpClientException->getMessage()), ['exception' => $httpClientException]);

            return SpamDetectorReport::failed();
        }

        $this->logger->info('Akismet response', [
            'headers' => $response->headers,
            'body'    => $response->content,
        ]);

        $content = $response->content;
        if ($response->isSuccessful() && $content !== null) {
            if (trim($content) === 'true') {
                return $response->getHeader('X-akismet-pro-tip') === 'discard'
                    ? SpamDetectorReport::blatant()
                    : SpamDetectorReport::spam();
            }

            if (trim($content) === 'false') {
                return SpamDetectorReport::ham();
            }
        }

        return SpamDetectorReport::failed();
    }
}
