<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\VisitorIdentity;

use Register\Core\Framework\ControllerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class ResolveVisitorController implements ControllerInterface
{
    private const int MAX_BODY_BYTES = 4096;

    public function __construct(
        private VisitorIdentityManager   $identityManager,
        private JsonMutationGuard        $mutationGuard,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[\Override]
    public function handle(Request $request): JsonResponse
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return new JsonResponse(
                ['success' => false, 'message' => 'Only POST requests are allowed.'],
                Response::HTTP_METHOD_NOT_ALLOWED,
                ['Allow' => Request::METHOD_POST],
            );
        }

        $violation = $this->mutationGuard->violation($request, requireBrowserEvidence: true);
        if ($violation instanceof JsonResponse) {
            return $violation;
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new JsonResponse(['success' => false, 'message' => 'The request is too large.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['success' => false, 'message' => 'Malformed JSON.'], Response::HTTP_BAD_REQUEST);
        }

        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'A JSON object is required.'], Response::HTTP_BAD_REQUEST);
        }

        $storageToken = isset($payload['token']) && \is_string($payload['token']) ? $payload['token'] : null;
        $resolved     = $this->identityManager->resolve($request, $storageToken);

        $this->eventDispatcher->dispatch(new VisitorResolvedEvent(
            $request,
            $resolved->visitorId,
            ($payload['trackPage'] ?? false) === true,
        ));

        $response = new JsonResponse([
            'success' => true,
            'token'   => $resolved->token,
            'source'  => $resolved->source,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->setCookie($this->identityManager->createCookie($resolved->token, $request));

        return $response;
    }
}
