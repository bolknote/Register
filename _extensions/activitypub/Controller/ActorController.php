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
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalActorState;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Presentation\ActorDocumentBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ActorController implements ControllerInterface
{
    public function __construct(
        private LocalActorRepository       $actorRepository,
        private PublicFederationAccess     $access,
        private ActorDocumentBuilder       $documentBuilder,
        private ActivityPubResponseFactory $responseFactory,
        private ?ActivationProbeService    $activationProbeService = null,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $publicId = $request->attributes->getString('publicId');
        $actor = $this->actorRepository->findByPublicId($publicId);
        if (!$actor instanceof LocalActor || !$this->access->actorIsPublic($actor)) {
            $probe = $this->activationProbeService?->actor($publicId, $request);
            if ($probe !== null) {
                return $this->responseFactory->activationProbeActivity($request, $probe);
            }

            return $this->responseFactory->notFound($request);
        }

        return $this->responseFactory->activity(
            $request,
            $this->documentBuilder->build($actor),
            $actor->state === LocalActorState::TOMBSTONED ? Response::HTTP_GONE : Response::HTTP_OK,
        );
    }
}
