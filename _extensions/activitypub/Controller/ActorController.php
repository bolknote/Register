<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Controller;

use Register\Core\Framework\ControllerInterface;
use Register\Extension\activitypub\Application\PublicFederationAccess;
use Register\Extension\activitypub\Application\ActivationProbeService;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Presentation\ActorDocumentBuilder;
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
