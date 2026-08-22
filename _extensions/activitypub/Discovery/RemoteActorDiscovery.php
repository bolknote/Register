<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Discovery;

use Register\Core\Queue\QueueExecutionBudget;
use Register\Extension\activitypub\Domain\RemoteActor;
use Register\Extension\activitypub\Domain\RemoteHandle;
use Register\Extension\activitypub\Inbox\RemoteActorDocumentValidator;
use Register\Extension\activitypub\Inbox\RemoteActorFetchClient;
use Register\Extension\activitypub\Infrastructure\RemoteActorRepository;

/** Resolves and caches a remote actor for an explicit admin preview. */
final readonly class RemoteActorDiscovery
{
    private const int MAX_REDIRECTS = 3;

    public function __construct(
        private WebFingerClient              $webFingerClient,
        private RemoteActorFetchClient       $actorFetchClient,
        private RemoteActorDocumentValidator $actorValidator,
        private RemoteActorRepository        $actorRepository,
    ) {
    }

    public function discover(string $handle, int $signingActorId, int $now): RemoteActor
    {
        if ($signingActorId < 1 || $now < 1) {
            throw new \InvalidArgumentException('Remote ActivityPub discovery requires an active local actor.');
        }

        $webFinger = $this->webFingerClient->discover(new RemoteHandle($handle));
        $url       = $webFinger->actorUrl;
        $chain     = [];
        $signed    = false;
        $redirects = 0;
        while (true) {
            $chain[$url] ??= true;
            $budget = new QueueExecutionBudget(4.0);
            $remote = $this->actorFetchClient->fetch(
                $url,
                $budget,
                $signed ? $signingActorId : null,
                $signed ? $now : null,
            );
            $status = $remote->response->statusCode;
            if ($status >= 200 && $status < 300) {
                $body = $remote->response->content;
                if (!\is_string($body)) {
                    throw new \DomainException('The remote actor response has no body.');
                }

                $fetched = $this->actorValidator->validateForDiscovery($url, $body, $now);
                if (!hash_equals($webFinger->handle->username, $fetched->preferredUsername)) {
                    throw new \DomainException('The actor preferredUsername does not match the requested WebFinger handle.');
                }

                return $this->actorRepository->save($fetched);
            }

            if ($status >= 300 && $status < 400) {
                if ($remote->redirectUrl === null) {
                    throw new \DomainException('The remote actor redirect has no usable Location.');
                }

                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new \DomainException('The remote actor endpoint exceeded the redirect limit.');
                }

                if (isset($chain[$remote->redirectUrl])) {
                    throw new \DomainException('The remote actor endpoint contains a redirect loop.');
                }

                $url = $remote->redirectUrl;
                ++$redirects;
                $signed = false;
                continue;
            }

            if (($status === 401 || $status === 403) && !$signed) {
                $signed = true;
                continue;
            }

            if ($status === 404 || $status === 410) {
                throw new \DomainException('The remote ActivityPub actor no longer exists.');
            }

            throw new \DomainException('The remote actor endpoint returned HTTP ' . $status . '.');
        }
    }
}
