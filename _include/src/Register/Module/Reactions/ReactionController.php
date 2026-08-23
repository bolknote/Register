<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentRepository;
use Register\Content\ContentType;
use Register\Core\Model\AuthProvider;
use Register\Module\VisitorIdentity\JsonMutationGuard;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Core\Framework\ControllerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ReactionController implements ControllerInterface
{
    private const int MAX_BODY_BYTES = 1024;

    public function __construct(
        private ReactionRepository     $repository,
        private ContentRepository      $contentRepository,
        private VisitorIdentityManager $identityManager,
        private JsonMutationGuard      $mutationGuard,
        private AuthProvider            $authProvider,
    ) {
    }

    #[\Override]
    public function handle(Request $request): JsonResponse
    {
        $type = ContentType::tryFrom($request->attributes->getString('type'));
        $id   = $request->attributes->getInt('id');
        if ($type === null || $id <= 0) {
            return $this->error('Invalid content identifier.', Response::HTTP_BAD_REQUEST);
        }

        $contentId = new ContentId($type, $id);
        if (!$this->contentRepository->find($contentId) instanceof ContentItem) {
            return $this->error('Content not found.', Response::HTTP_NOT_FOUND);
        }

        if ($request->isMethod(Request::METHOD_GET)) {
            return $this->stateResponse($this->repository->state(
                $contentId->value,
                $this->identityManager->visitorIdFromRequest($request),
            ));
        }

        if (!$request->isMethod(Request::METHOD_POST)) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Only GET and POST requests are allowed.'],
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => 'GET, POST'],
            );
        }

        $violation = $this->mutationGuard->violation($request);
        if ($violation instanceof JsonResponse) {
            return $violation;
        }

        $visitorId = $this->identityManager->visitorIdFromRequest($request);
        if ($visitorId === null) {
            return $this->error('A visitor identity is required.', Response::HTTP_UNAUTHORIZED);
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->error('The request is too large.', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $payload = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error('Malformed JSON.', Response::HTTP_BAD_REQUEST);
        }

        $reaction = \is_array($payload) && \is_string($payload['reaction'] ?? null)
            ? ReactionType::tryFrom($payload['reaction'])
            : null;
        if (!$reaction instanceof ReactionType) {
            return $this->error('Unknown reaction.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $userId = $this->authProvider->getAuthenticatedPublicUser($request)?->id;
        $this->identityManager->recordInteraction($request, $userId);

        return $this->stateResponse($this->repository->toggle(
            $contentId->value,
            $visitorId,
            $reaction,
            $userId,
        ));
    }

    private function stateResponse(ReactionState $state): JsonResponse
    {
        $response = new JsonResponse(['success' => true, ...$state->toArray()]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function error(string $message, int $status): JsonResponse
    {
        $response = new JsonResponse(['success' => false, 'message' => $message], $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
