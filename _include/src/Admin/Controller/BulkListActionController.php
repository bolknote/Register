<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\Admin\Controller;

use Psr\Log\LoggerInterface;
use Register\Content\Admin\ContentBulkPublicationService;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentType;
use Register\Url\ContentUrlCollisionException;
use S2\AdminYard\Translator;
use S2\Cms\Admin\AdminPanelFactory;
use S2\Cms\AdminYard\BulkListActionProvider;
use S2\Cms\Framework\Exception\AccessDeniedException;
use S2\Cms\Model\PermissionChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final readonly class BulkListActionController
{
    public function __construct(
        private BulkListActionProvider          $actionProvider,
        private ContentBulkPublicationService   $publicationService,
        private AdminPanelFactory                $adminPanelFactory,
        private ContentChangeDispatcher          $contentChangeDispatcher,
        private RequestStack                     $requestStack,
        private \PDO                             $pdo,
        private Translator                       $translator,
        private LoggerInterface                  $logger,
    ) {
    }

    public function execute(PermissionChecker $permissionChecker, Request $request): JsonResponse
    {
        if ($request->getRealMethod() !== Request::METHOD_POST) {
            return $this->error('Only POST requests are allowed.', Response::HTTP_METHOD_NOT_ALLOWED);
        }

        if (!$permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW)) {
            return $this->error('No permission', Response::HTTP_FORBIDDEN);
        }

        $entityName = $request->request->getString('entity');
        $action     = $request->request->getString('bulk_action');
        try {
            if (!$this->actionProvider->csrfTokenMatches(
                $entityName,
                $request->request->getString('csrf_token'),
            )) {
                throw new AccessDeniedException('Unable to confirm security token.');
            }

            if (!$this->actionProvider->isAllowed($entityName, $action)) {
                throw new AccessDeniedException('This bulk action is not allowed.');
            }

            $items = $this->decodeItems($request->request->getString('items'));
            $count = $this->transactional(function () use ($entityName, $action, $items, $request): int {
                if ($action === BulkListActionProvider::ACTION_PUBLISH
                    || $action === BulkListActionProvider::ACTION_UNPUBLISH
                ) {
                    $contentType = $entityName === 'Article' ? ContentType::PAGE : ContentType::POST;

                    return $this->publicationService->setPublished(
                        $contentType,
                        array_map(static fn(array $item): int => $item['id'], $items),
                        $action === BulkListActionProvider::ACTION_PUBLISH,
                    );
                }

                return $this->runEntityActions($entityName, $action, $items, $request);
            });
        } catch (AccessDeniedException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (ContentUrlCollisionException|\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $throwable) {
            $this->logger->error('Unable to execute an administration bulk action.', ['exception' => $throwable]);
            $status = \is_int($throwable->getCode())
                && $throwable->getCode() >= 400
                && $throwable->getCode() <= 599
                    ? $throwable->getCode()
                    : Response::HTTP_INTERNAL_SERVER_ERROR;

            $message = $status >= Response::HTTP_INTERNAL_SERVER_ERROR
                ? 'Unable to perform a bulk action on one of the selected items.'
                : $throwable->getMessage();

            return $this->error($message, $status);
        }

        return new JsonResponse(['success' => true, 'updated' => $count]);
    }

    /**
     * @param list<array{id: int, csrf_token: string}> $items
     */
    private function runEntityActions(string $entityName, string $action, array $items, Request $parentRequest): int
    {
        $adminPanel = $this->adminPanelFactory->create();
        foreach ($items as $item) {
            $subRequest = Request::create(
                '/_admin/index.php?' . http_build_query([
                    'entity' => $entityName,
                    'action' => $action,
                    'id'     => $item['id'],
                ]),
                Request::METHOD_POST,
                ['csrf_token' => $item['csrf_token']],
                server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
            );
            $subRequest->setSession($parentRequest->getSession());
            $this->requestStack->push($subRequest);
            try {
                $response = $adminPanel->handleRequest($subRequest);
            } finally {
                $this->requestStack->pop();
            }

            if (!$response->isSuccessful()) {
                throw new \RuntimeException($this->responseError($response), $response->getStatusCode());
            }
        }

        $this->contentChangeDispatcher->flush();

        return \count($items);
    }

    /** @return list<array{id: int, csrf_token: string}> */
    private function decodeItems(string $itemsJson): array
    {
        try {
            $decoded = json_decode($itemsJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Invalid bulk action selection.', 0, $exception);
        }

        if (!\is_array($decoded) || !array_is_list($decoded) || $decoded === [] || \count($decoded) > 50) {
            throw new \InvalidArgumentException('Select between 1 and 50 items.');
        }

        $items = [];
        foreach ($decoded as $item) {
            if (!\is_array($item)
                || !isset($item['primary_key'])
                || !\is_array($item['primary_key'])
                || \count($item['primary_key']) !== 1
                || !isset($item['primary_key']['id'])
            ) {
                throw new \InvalidArgumentException('Invalid selected item identifier.');
            }

            $id = filter_var($item['primary_key']['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new \InvalidArgumentException('Invalid selected item identifier.');
            }

            $csrfToken = $item['csrf_token'] ?? '';
            if (!\is_string($csrfToken)) {
                throw new \InvalidArgumentException('Invalid selected item security token.');
            }

            $items[$id] = ['id' => $id, 'csrf_token' => $csrfToken];
        }

        return array_values($items);
    }

    private function responseError(Response $response): string
    {
        try {
            $payload = json_decode((string)$response->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'Unable to perform a bulk action on one of the selected items.';
        }

        if (!\is_array($payload) || !isset($payload['errors']) || !\is_array($payload['errors'])) {
            return 'Unable to perform a bulk action on one of the selected items.';
        }

        $messages = array_values(array_filter($payload['errors'], is_string(...)));

        return $messages === []
            ? 'Unable to perform a bulk action on one of the selected items.'
            : implode(' ', $messages);
    }

    private function transactional(callable $callback): int
    {
        $outerTransaction = $this->pdo->inTransaction();
        if (!$outerTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback();
            if (!$outerTransaction) {
                $this->pdo->commit();
            }

            return $result;
        } catch (\Throwable $throwable) {
            $this->contentChangeDispatcher->clearState();
            if (!$outerTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->translator->trans($message),
        ], $status);
    }
}
