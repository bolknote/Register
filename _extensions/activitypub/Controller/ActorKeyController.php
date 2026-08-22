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
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalActorKey;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Presentation\ActorKeyDocumentBuilder;
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
