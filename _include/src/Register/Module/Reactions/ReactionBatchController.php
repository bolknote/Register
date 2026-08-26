<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Reactions;

use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Core\Framework\ControllerInterface;
use Register\Core\Pdo\DbLayer;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Returns reaction state for every widget on a page with a bounded number of queries. */
final readonly class ReactionBatchController implements ControllerInterface
{
    private const int MAX_CONTENT_IDS = 100;

    private const int MAX_QUERY_BYTES = 4096;

    public function __construct(
        private DbLayer                $dbLayer,
        private ReactionRepository     $repository,
        private VisitorIdentityManager $identityManager,
    ) {
    }

    #[\Override]
    public function handle(Request $request): JsonResponse
    {
        $value = $request->query->get('content');
        if (!\is_string($value) || $value === '' || strlen($value) > self::MAX_QUERY_BYTES) {
            return $this->error('Invalid content identifiers.');
        }

        $parts = explode(',', $value);
        if (\count($parts) > self::MAX_CONTENT_IDS) {
            return $this->error('Too many content identifiers.');
        }

        $requested = [];
        try {
            foreach ($parts as $part) {
                $contentId = ContentId::fromString($part);
                $requested[(string)$contentId] = $contentId;
            }
        } catch (\InvalidArgumentException) {
            return $this->error('Invalid content identifiers.');
        }

        $parameters   = [];
        $placeholders = [];
        foreach (array_values($requested) as $index => $contentId) {
            $parameter              = 'content_id_' . $index;
            $parameters[$parameter] = $contentId->value;
            $placeholders[]         = ':' . $parameter;
        }

        $published = [];
        $rows = $this->dbLayer
            ->select('id', 'content_type')
            ->from(ContentSchema::TABLE_NAME)
            ->where('published = 1')
            ->andWhere('id IN (' . implode(', ', $placeholders) . ')')
            ->execute($parameters)
            ->fetchAssocAll()
        ;
        foreach ($rows as $row) {
            $key = (string)$row['content_type'] . ':' . (int)$row['id'];
            if (isset($requested[$key])) {
                $published[$key] = $requested[$key];
            }
        }

        $numericIds = array_values(array_map(
            static fn(ContentId $contentId): int => $contentId->value,
            $published,
        ));
        $states = $this->repository->states(
            $numericIds,
            $this->identityManager->visitorIdFromRequest($request),
        );

        $payload = [];
        foreach ($published as $key => $contentId) {
            $payload[$key] = $states[$contentId->value]->toArray();
        }

        $response = new JsonResponse(['success' => true, 'states' => $payload]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function error(string $message): JsonResponse
    {
        $response = new JsonResponse(
            ['success' => false, 'message' => $message],
            Response::HTTP_BAD_REQUEST,
        );
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
