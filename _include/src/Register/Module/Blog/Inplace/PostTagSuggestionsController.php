<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Model\AuthProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Returns existing tag names to authenticated public-side content editors. */
final readonly class PostTagSuggestionsController implements ControllerInterface
{
    public function __construct(
        private AuthProvider  $authProvider,
        private TagRepository $tagRepository,
    ) {
    }

    #[\Override]
    public function handle(Request $request): JsonResponse
    {
        if ($this->authProvider->getAuthenticatedContentEditor($request) === null) {
            return $this->response(
                ['success' => false, 'message' => 'Post editing forbidden'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $this->response([
            'success' => true,
            'tags'    => array_map(
                static fn(\Register\Content\TagUsage $usage): string => $usage->tag->name,
                $this->tagRepository->findAllUsage(ContentType::POST),
            ),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function response(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
