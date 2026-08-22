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
use Register\Extension\activitypub\Domain\FederationUrlGenerator;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\LocalActorState;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Register\Extension\activitypub\Infrastructure\LocalActorRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\StoredActivityRepresentation;
use Register\Extension\activitypub\Infrastructure\StoredObjectRepresentation;
use Register\Extension\activitypub\Presentation\ActivityStreamsContext;
use Register\Extension\activitypub\Security\CollectionCursorCodec;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ActorCollectionController implements ControllerInterface
{
    private const int PAGE_SIZE = 40;

    public function __construct(
        private LocalActorRepository          $actorRepository,
        private LocalFederationRepository     $federationRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
        private CollectionCursorCodec         $cursorCodec,
        private PublicFederationAccess        $access,
        private ActivityPubResponseFactory    $responseFactory,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $actor = $this->actorRepository->findByPublicId($request->attributes->getString('publicId'));
        if (!$actor instanceof LocalActor
            || !$this->access->actorIsPublic($actor)
            || !\in_array($actor->state, [LocalActorState::ACTIVE, LocalActorState::MOVED], true)
        ) {
            return $this->responseFactory->notFound($request);
        }

        $collection = $request->attributes->getString('collection');
        if (!\in_array($collection, ['outbox', 'followers', 'following', 'featured'], true)) {
            return $this->responseFactory->notFound($request);
        }

        $urls          = $this->urlGeneratorFactory->create();
        $collectionUrl = $this->collectionUrl($urls, $actor->publicId, $collection);
        $total         = $this->count($actor->id, $collection);

        if (\in_array($collection, ['followers', 'following'], true)) {
            // Membership is intentionally private; the addressing collection and count stay public.
            return $this->responseFactory->activity($request, [
                '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
                'id'        => $collectionUrl,
                'type'      => 'OrderedCollection',
                'totalItems' => $total,
            ]);
        }

        $query = $request->query->all();
        $cursorValue = $query['cursor'] ?? null;
        $pageValue   = $query['page'] ?? null;
        if (($cursorValue !== null && !\is_string($cursorValue))
            || ($pageValue !== null && !\is_string($pageValue))
        ) {
            return $this->responseFactory->badRequest($request, 'ActivityPub collection pagination parameters must be scalar.');
        }

        $isPage = $cursorValue !== null || \in_array($pageValue, ['1', 'true'], true);
        if (!$isPage) {
            return $this->responseFactory->activity($request, [
                '@context'  => ActivityStreamsContext::ACTIVITY_STREAMS,
                'id'        => $collectionUrl,
                'type'      => 'OrderedCollection',
                'totalItems' => $total,
                'first'     => $collectionUrl . '?page=true',
            ]);
        }

        $scope  = $collection . ':' . $actor->publicId;
        $anchor = null;
        if (\is_string($cursorValue)) {
            try {
                $anchor = $this->cursorCodec->decode($scope, $cursorValue);
            } catch (\InvalidArgumentException) {
                return $this->responseFactory->badRequest($request, 'The ActivityPub collection cursor is invalid or expired.');
            }
        }

        $pageUrl = \is_string($cursorValue)
            ? $this->nextUrl($collectionUrl, $cursorValue)
            : $collectionUrl . '?page=true';

        return $collection === 'outbox'
            ? $this->outboxPage($request, $actor, $collectionUrl, $pageUrl, $scope, $anchor)
            : $this->featuredPage($request, $actor, $collectionUrl, $pageUrl, $scope, $anchor);
    }

    private function outboxPage(
        Request           $request,
        LocalActor        $actor,
        string            $collectionUrl,
        string            $pageUrl,
        string            $scope,
        ?CollectionAnchor $anchor,
    ): Response {
        $rows    = $this->federationRepository->outboxPage($actor->id, $anchor, self::PAGE_SIZE + 1);
        $hasMore = \count($rows) > self::PAGE_SIZE;
        $rows    = array_slice($rows, 0, self::PAGE_SIZE);
        $items   = array_map(fn(StoredActivityRepresentation $activity): array => $this->decodeObject($activity->serializedBody), $rows);
        $next    = null;
        $last    = $rows === [] ? null : $rows[\count($rows) - 1];
        if ($hasMore && $last instanceof StoredActivityRepresentation) {
            $next = $this->nextUrl(
                $collectionUrl,
                $this->cursorCodec->encode($scope, new CollectionAnchor($last->publishedAt, $last->id)),
            );
        }

        return $this->responseFactory->activity($request, $this->pageDocument($collectionUrl, $pageUrl, $items, $next));
    }

    private function featuredPage(
        Request           $request,
        LocalActor        $actor,
        string            $collectionUrl,
        string            $pageUrl,
        string            $scope,
        ?CollectionAnchor $anchor,
    ): Response {
        $rows    = $this->federationRepository->featuredPage($actor->id, $anchor, self::PAGE_SIZE + 1);
        $hasMore = \count($rows) > self::PAGE_SIZE;
        $rows    = array_slice($rows, 0, self::PAGE_SIZE);
        $items   = array_map(fn(StoredObjectRepresentation $object): array => $this->decodeObject($object->snapshotJson), $rows);
        $next    = null;
        $last    = $rows === [] ? null : $rows[\count($rows) - 1];
        if ($hasMore && $last instanceof StoredObjectRepresentation && $last->featuredAt !== null) {
            $next = $this->nextUrl(
                $collectionUrl,
                $this->cursorCodec->encode($scope, new CollectionAnchor($last->featuredAt, $last->id)),
            );
        }

        return $this->responseFactory->activity($request, $this->pageDocument($collectionUrl, $pageUrl, $items, $next));
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function pageDocument(string $collectionUrl, string $pageUrl, array $items, ?string $next): array
    {
        $document = [
            '@context'    => ActivityStreamsContext::ACTIVITY_STREAMS,
            'id'          => $pageUrl,
            'type'        => 'OrderedCollectionPage',
            'partOf'      => $collectionUrl,
            'orderedItems' => $items,
        ];
        if ($next !== null) {
            $document['next'] = $next;
        }

        return $document;
    }

    private function collectionUrl(FederationUrlGenerator $urls, string $publicId, string $collection): string
    {
        return match ($collection) {
            'outbox'    => $urls->actorOutbox($publicId),
            'followers' => $urls->actorFollowers($publicId),
            'following' => $urls->actorFollowing($publicId),
            'featured'  => $urls->actorFeatured($publicId),
            default     => throw new \LogicException('Unknown local ActivityPub collection.'),
        };
    }

    private function count(int $actorId, string $collection): int
    {
        return match ($collection) {
            'outbox'    => $this->federationRepository->outboxCount($actorId),
            'followers' => $this->federationRepository->followCount($actorId, 'incoming'),
            'following' => $this->federationRepository->followCount($actorId, 'outgoing'),
            'featured'  => $this->federationRepository->featuredCount($actorId),
            default     => throw new \LogicException('Unknown local ActivityPub collection.'),
        };
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('A stored ActivityPub collection item is invalid JSON.', 0, $exception);
        }

        if (!\is_array($value) || array_is_list($value)) {
            throw new \RuntimeException('A stored ActivityPub collection item must be a JSON object.');
        }

        return $value;
    }

    private function nextUrl(string $collectionUrl, string $cursor): string
    {
        return $collectionUrl . '?page=true&cursor=' . rawurlencode($cursor);
    }
}
