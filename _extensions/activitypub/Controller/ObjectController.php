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
use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Infrastructure\StoredLocalNoteRepresentation;
use s2_extensions\activitypub\Infrastructure\StoredObjectRepresentation;
use s2_extensions\activitypub\Presentation\ActivityStreamsContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ObjectController implements ControllerInterface
{
    public function __construct(
        private LocalFederationRepository    $repository,
        private PublicFederationAccess       $access,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private ActivityPubResponseFactory   $responseFactory,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (!$this->access->installationIsPublic()) {
            return $this->responseFactory->notFound($request);
        }

        $object = $this->repository->findObject($request->attributes->getString('publicId'));
        if (!$object instanceof StoredObjectRepresentation) {
            $note = $this->repository->findLocalNote($request->attributes->getString('publicId'));
            if (!$note instanceof StoredLocalNoteRepresentation) {
                return $this->responseFactory->notFound($request);
            }

            if ($note->visibility === 'direct') {
                return $this->responseFactory->notFound($request);
            }

            if ($note->state === 'tombstoned') {
                return $this->responseFactory->activity($request, [
                    '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
                    'id'        => $this->urlGeneratorFactory->create()->object($note->publicId),
                    'type'      => 'Tombstone',
                    'formerType' => 'Note',
                    'deleted'   => gmdate('Y-m-d\TH:i:s\Z', $note->deletedAt ?? $note->updatedAt),
                ], Response::HTTP_GONE);
            }

            return $note->state === 'live'
                ? $this->responseFactory->serializedActivity($request, $note->snapshotJson)
                : $this->responseFactory->notFound($request);
        }

        if ($object->state === 'tombstoned') {
            return $this->responseFactory->activity($request, [
                '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
                'id'        => $this->urlGeneratorFactory->create()->object($object->publicId),
                'type'      => 'Tombstone',
                'formerType' => $object->objectType,
                'deleted'   => gmdate('Y-m-d\TH:i:s\Z', $object->deletedAt ?? $object->updatedAt),
            ], Response::HTTP_GONE);
        }

        if ($object->state !== 'live') {
            return $this->responseFactory->notFound($request);
        }

        return $this->responseFactory->serializedActivity($request, $object->snapshotJson);
    }
}
