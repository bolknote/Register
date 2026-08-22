<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Controller;

use S2\Cms\Framework\ControllerInterface;
use s2_extensions\activitypub\Application\PublicFederationAccess;
use s2_extensions\activitypub\Application\ActivationProbeService;
use s2_extensions\activitypub\Domain\CanonicalOrigin;
use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class WebFingerController implements ControllerInterface
{
    public function __construct(
        private FederationStateRepository    $stateRepository,
        private LocalActorRepository          $actorRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private PublicFederationAccess        $access,
        private ActivityPubResponseFactory    $responseFactory,
        private ?ActivationProbeService       $activationProbeService = null,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (!$this->access->installationIsPublic()) {
            $probe = $this->activationProbeService?->webFinger($request);
            if ($probe !== null) {
                return $this->responseFactory->activationProbeWebFinger($request, $probe);
            }

            return $this->responseFactory->notFound($request);
        }

        $resource = $request->query->getString('resource');
        if ($resource === '' || \strlen($resource) > 2_048) {
            return $this->responseFactory->badRequest($request, 'A bounded WebFinger resource query is required.');
        }

        $state  = $this->stateRepository->state();
        $origin = $state->canonicalOrigin;
        if (!$origin instanceof CanonicalOrigin) {
            return $this->responseFactory->notFound($request);
        }

        $actor = $this->resolveActor($resource, $origin);
        if (!$actor instanceof LocalActor || !$this->access->actorIsPublic($actor)) {
            return $this->responseFactory->notFound($request);
        }

        $urls      = $this->urlGeneratorFactory->create();
        $actorUrl  = $urls->actor($actor->publicId);
        $accounts  = array_map(
            static fn(string $handle): string => 'acct:' . $handle . '@' . $origin->authority(),
            $this->actorRepository->handlesForActor($actor->id),
        );
        $account   = $this->requestedAccount($resource, $origin) ?? 'acct:' . $actor->handle . '@' . $origin->authority();

        return $this->responseFactory->webFinger($request, [
            'subject' => $account,
            'aliases' => array_values(array_unique([$actorUrl, $actor->profileUrl, ...$accounts])),
            'links'   => [
                [
                    'rel'  => 'self',
                    'type' => ActivityPubResponseFactory::ACTIVITY_MEDIA_TYPE,
                    'href' => $actorUrl,
                ],
                [
                    'rel'  => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => $actor->profileUrl,
                ],
            ],
        ]);
    }

    private function resolveActor(string $resource, CanonicalOrigin $origin): ?LocalActor
    {
        if (preg_match('/^acct:([a-z0-9][a-z0-9_-]{0,31})@(.+)$/Di', $resource, $match) === 1) {
            if (!hash_equals(strtolower($origin->authority()), strtolower($match[2]))) {
                return null;
            }

            return $this->actorRepository->findByHandle($match[1]);
        }

        $prefix = $origin->value . $this->stateRepository->state()->basePath->value . '/activitypub/actors/';
        if (!str_starts_with($resource, $prefix)) {
            return null;
        }

        $publicId = substr($resource, \strlen($prefix));
        if (str_contains($publicId, '/')) {
            return null;
        }

        return $this->actorRepository->findByPublicId($publicId);
    }

    private function requestedAccount(string $resource, CanonicalOrigin $origin): ?string
    {
        if (preg_match('/^acct:([a-z0-9][a-z0-9_-]{0,31})@(.+)$/Di', $resource, $match) !== 1
            || !hash_equals(strtolower($origin->authority()), strtolower($match[2]))
        ) {
            return null;
        }

        return 'acct:' . strtolower($match[1]) . '@' . $origin->authority();
    }
}
