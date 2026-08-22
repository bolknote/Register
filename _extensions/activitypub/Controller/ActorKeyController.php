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
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorKey;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Presentation\ActorKeyDocumentBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ActorKeyController implements ControllerInterface
{
    public function __construct(
        private LocalActorRepository       $actorRepository,
        private PublicFederationAccess     $access,
        private ActorKeyDocumentBuilder    $documentBuilder,
        private ActivityPubResponseFactory $responseFactory,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $key = $this->actorRepository->keyByPublicId($request->attributes->getString('publicId'));
        if (!$key instanceof LocalActorKey) {
            return $this->responseFactory->notFound($request);
        }

        if ($key->destroyedAt !== null) {
            return $this->responseFactory->notFound($request);
        }

        $actor = $this->actorRepository->findById($key->actorId);
        if (!$actor instanceof LocalActor || !$this->access->actorIsPublic($actor)) {
            return $this->responseFactory->notFound($request);
        }

        return $this->responseFactory->activity($request, $this->documentBuilder->build($key));
    }
}
