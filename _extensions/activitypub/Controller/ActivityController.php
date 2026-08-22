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
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Infrastructure\StoredActivityRepresentation;
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
