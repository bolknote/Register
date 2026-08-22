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
use Register\Extension\activitypub\Domain\CollectionAnchor;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Infrastructure\InteractionRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\RemoteObjectRepository;
use Register\Extension\activitypub\Infrastructure\StoredLocalNoteRepresentation;
use Register\Extension\activitypub\Infrastructure\StoredObjectRepresentation;
use Register\Extension\activitypub\Presentation\ActivityStreamsContext;
use Register\Extension\activitypub\Security\CollectionCursorCodec;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ObjectRepliesController implements ControllerInterface
{
    private const int PAGE_SIZE = 40;

    public function __construct(
        private LocalFederationRepository     $repository,
        private InteractionRepository         $interactionRepository,
        private RemoteObjectRepository        $remoteObjectRepository,
        private PublicFederationAccess        $access,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private CollectionCursorCodec         $cursorCodec,
        private ActivityPubResponseFactory    $responseFactory,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (!$this->access->installationIsPublic()) {
            return $this->responseFactory->notFound($request);
        }

        $publicId = $request->attributes->getString('publicId');
        $objectCandidate = $this->repository->findObject($publicId);
        $object = null;
        $note = null;
        if ($objectCandidate instanceof StoredObjectRepresentation) {
            if ($objectCandidate->state === 'live') {
                $object = $objectCandidate;
            }
        }

        if (!$object instanceof StoredObjectRepresentation) {
            $candidate = $this->repository->findLocalNote($publicId);
            if ($candidate instanceof StoredLocalNoteRepresentation) {
                if ($candidate->state === 'live' && $candidate->visibility !== 'direct') {
                    $note = $candidate;
                }
            }
        }

        if ($object instanceof StoredObjectRepresentation) {
            $parentPublicId = $object->publicId;
            $parentId       = $object->id;
            $isLocalNote    = false;
        } elseif ($note instanceof StoredLocalNoteRepresentation) {
            $parentPublicId = $note->publicId;
            $parentId       = $note->id;
            $isLocalNote    = true;
        } else {
            return $this->responseFactory->notFound($request);
        }

        $id = $this->urlGeneratorFactory->create()->objectReplies($parentPublicId);
        $total = $isLocalNote
            ? $this->interactionRepository->publicLocalNoteReplyCount($parentId)
            : $this->interactionRepository->publicReplyCount($parentId);
        $query       = $request->query->all();
        $cursorValue = $query['cursor'] ?? null;
        $pageValue   = $query['page'] ?? null;
        if (($cursorValue !== null && !\is_string($cursorValue))
            || ($pageValue !== null && !\is_string($pageValue))
        ) {
            return $this->responseFactory->badRequest($request, 'ActivityPub replies pagination parameters must be scalar.');
        }

        $isPage = $cursorValue !== null || \in_array($pageValue, ['1', 'true'], true);
        if (!$isPage) {
            return $this->responseFactory->activity($request, [
                '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
                'id'        => $id,
                'type'      => 'OrderedCollection',
                'totalItems' => $total,
                'first'     => $id . '?page=true',
            ]);
        }

        $scope = 'replies:' . $parentPublicId;
        $anchor = null;
        if (\is_string($cursorValue)) {
            try {
                $anchor = $this->cursorCodec->decode($scope, $cursorValue);
            } catch (\InvalidArgumentException) {
                return $this->responseFactory->badRequest($request, 'The ActivityPub replies cursor is invalid or expired.');
            }
        }

        $rows = $isLocalNote
            ? $this->interactionRepository->publicLocalNoteRepliesPage($parentId, $anchor, self::PAGE_SIZE + 1)
            : $this->interactionRepository->publicRepliesPage($parentId, $anchor, self::PAGE_SIZE + 1);
        $hasMore = \count($rows) > self::PAGE_SIZE;
        $rows    = array_slice($rows, 0, self::PAGE_SIZE);
        $items   = [];
        foreach ($rows as $interaction) {
            if ($interaction->remoteObjectUrl === null) {
                continue;
            }

            $remoteObject = $this->remoteObjectRepository->findByUrl($interaction->remoteObjectUrl);
            if (!$remoteObject instanceof \Register\Extension\activitypub\Domain\RemoteObject) {
                continue;
            }

            if ($remoteObject->state !== 'live') {
                continue;
            }

            $items[] = $this->remoteObjectRepository->snapshot($remoteObject)->document;
        }

        $pageUrl = \is_string($cursorValue)
            ? $id . '?page=true&cursor=' . rawurlencode($cursorValue)
            : $id . '?page=true';
        $document = [
            '@context'    => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'          => $pageUrl,
            'type'        => 'OrderedCollectionPage',
            'partOf'      => $id,
            'orderedItems' => $items,
        ];
        $last = $rows === [] ? null : $rows[\count($rows) - 1];
        if ($hasMore && $last !== null) {
            $cursor = $this->cursorCodec->encode($scope, new CollectionAnchor($last->createdAt, $last->id));
            $document['next'] = $id . '?page=true&cursor=' . rawurlencode($cursor);
        }

        return $this->responseFactory->activity($request, $document);
    }
}
