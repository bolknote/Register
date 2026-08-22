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
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\StoredLocalNoteRepresentation;
use Register\Extension\activitypub\Infrastructure\StoredObjectRepresentation;
use Register\Extension\activitypub\Presentation\ActivityStreamsContext;
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
