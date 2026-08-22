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
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ActivityController implements ControllerInterface
{
    public function __construct(
        private LocalFederationRepository  $repository,
        private PublicFederationAccess     $access,
        private ActivityPubResponseFactory $responseFactory,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (!$this->access->installationIsPublic()) {
            return $this->responseFactory->notFound($request);
        }

        $activity = $this->repository->findActivity($request->attributes->getString('publicId'));
        if (!$activity instanceof StoredActivityRepresentation) {
            return $this->responseFactory->notFound($request);
        }

        return $this->responseFactory->serializedActivity($request, $activity->serializedBody);
    }
}
