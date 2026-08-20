<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use Register\Comment\CommentRepository;
use Register\Content\Admin\ContentRevisionService;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentDeletionGuardInterface;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Live\LiveFragmentRenderer;
use Register\Module\Blog\BlogUrlBuilder;
use S2\Cms\Framework\ControllerInterface;
use S2\Cms\Model\AuthenticatedPublicUser;
use S2\Cms\Model\UrlBuilder;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Applies revision-safe edit and delete mutations initiated on a public post card. */
final readonly class PostInplaceController implements ControllerInterface
{
    private const int MAX_BODY_BYTES = 16 * 1024 * 1024;

    private const int MAX_TAGS = 100;

    private const string SAVEPOINT = 'register_post_inplace';

    /** @var list<string> */
    private const array IMAGE_EXTENSIONS = ['avif', 'bmp', 'gif', 'ico', 'jpeg', 'jpg', 'png', 'webp'];

    /** @var list<string> */
    private const array AUDIO_EXTENSIONS = ['flac', 'mkv', 'mp3', 'mp4', 'ogg', 'wav', 'webm'];

    /** @var list<ContentDeletionGuardInterface> */
    private array $deletionGuards;

    public function __construct(
        private DbLayer                   $dbLayer,
        private \PDO                      $pdo,
        private PostInplaceControls        $controls,
        private PostInplaceTokenManager    $tokenManager,
        private ContentRevisionService     $revisionService,
        private TagRepository              $tagRepository,
        private CommentRepository          $commentRepository,
        private ContentChangeDispatcher    $changeDispatcher,
        private LiveFragmentRenderer       $fragmentRenderer,
        private BlogUrlBuilder             $blogUrlBuilder,
        private UrlBuilder                 $urlBuilder,
        private PostInplaceMediaStorage    $mediaStorage,
        private string                     $mediaUrlPrefix,
        private TranslatorInterface        $translator,
        ContentDeletionGuardInterface ...$deletionGuards,
    ) {
        $this->deletionGuards = array_values($deletionGuards);
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        $postId = $request->attributes->getInt('id');
        if ($postId <= 0) {
            return $this->error($request, 'Invalid post mutation request', Response::HTTP_BAD_REQUEST);
        }

        $post = $this->dbLayer
            ->select('id, author_id, revision, title, body, slug')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $postId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('published = 1')
            ->execute()
            ->fetchAssoc()
        ;
        if ($post === false) {
            return $this->error($request, 'Post not found', Response::HTTP_NOT_FOUND);
        }

        $authorId = $post['author_id'] === null ? null : (int)$post['author_id'];
        $editor   = $this->controls->editorForPost($request, $authorId);
        if (!$editor instanceof AuthenticatedPublicUser) {
            return $this->error($request, 'Post editing forbidden', Response::HTTP_FORBIDDEN);
        }

        if (!$this->tokenManager->isValid($request->request->getString('inplace_token'), $editor, $postId)) {
            return $this->error($request, 'Post editing token expired', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $action = $request->request->getString('inplace_action');
        if ($action === 'media') {
            return $this->uploadMedia($request);
        }

        $revision = $this->revision($request);
        if ($revision === null || !\in_array($action, ['edit', 'delete'], true)) {
            return $this->error($request, 'Invalid post mutation request', Response::HTTP_BAD_REQUEST);
        }

        if ($action === 'delete') {
            return $this->delete($request, $postId, (int)$post['revision'], $revision);
        }

        return $this->edit($request, $post, $revision);
    }

    private function uploadMedia(Request $request): Response
    {
        $file = $request->files->get('media');
        if (!$file instanceof UploadedFile) {
            return $this->error($request, 'Post media file missing', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $kind = $this->mediaKind($file);
        } catch (\RuntimeException $runtimeException) {
            return $this->error(
                $request,
                $runtimeException->getMessage(),
                $this->uploadErrorStatus($runtimeException),
                false,
            );
        }
        if ($kind === null) {
            return $this->error($request, 'Unsupported post media', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $path = '/' . date('Y/m');
        try {
            $storedFile = $this->mediaStorage->store($file, $path);
        } catch (\RuntimeException $runtimeException) {
            return $this->error(
                $request,
                $runtimeException->getMessage(),
                $this->uploadErrorStatus($runtimeException),
                false,
            );
        }

        $payload = [
            'success' => true,
            'action'  => 'media',
            'kind'    => $kind,
            'url'     => rtrim($this->mediaUrlPrefix, '/') . $storedFile,
            'name'    => basename(str_replace('\\', '/', $file->getClientOriginalName())),
        ];
        if ($kind === 'image') {
            $imageInfo = $this->mediaStorage->getImageInfo($storedFile);
            $payload['width']  = isset($imageInfo[0]) ? (int)$imageInfo[0] : null;
            $payload['height'] = isset($imageInfo[1]) ? (int)$imageInfo[1] : null;
        }

        return $this->json($payload);
    }

    private function mediaKind(UploadedFile $file): ?string
    {
        $extension = mb_strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $mimeType  = $this->mediaStorage->detectMimeType($file);

        if (\in_array($extension, self::IMAGE_EXTENSIONS, true) && str_starts_with($mimeType, 'image/')) {
            return 'image';
        }
        if (
            \in_array($extension, self::AUDIO_EXTENSIONS, true)
            && (str_starts_with($mimeType, 'audio/') || $mimeType === 'application/ogg')
        ) {
            return 'audio';
        }

        return null;
    }

    private function uploadErrorStatus(\RuntimeException $runtimeException): int
    {
        $code = $runtimeException->getCode();
        return \is_int($code) && $code >= 400 && $code <= 599
            ? $code
            : Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    /** @param array<string, mixed> $post */
    private function edit(Request $request, array $post, int $submittedRevision): Response
    {
        $postId         = (int)$post['id'];
        $contentId      = ContentId::post($postId);
        $storedTags     = $this->tagRepository->findForContent([$contentId])[(string)$contentId] ?? [];
        $storedTagNames = array_map(static fn(\Register\Content\Tag $tag): string => $tag->name, $storedTags);
        $title          = trim($request->request->getString('title'));
        $body           = $request->request->getString('body');
        $tagNames       = $storedTagNames;
        if ($request->request->has('tags')) {
            $submittedTags = $request->request->get('tags');
            $tagNames      = \is_string($submittedTags) ? $this->parseTags($submittedTags) : null;
            if ($tagNames === null) {
                return $this->error($request, 'Invalid post tags', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (
            $title === ''
            || mb_strlen($title) > 255
            || \strlen($body) > self::MAX_BODY_BYTES
            || str_contains($body, "\0")
        ) {
            return $this->error($request, 'Invalid post content', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $revision = $this->revisionService->resolve(
            [
                'title'    => $title,
                'body'     => $body,
                'tags'     => $tagNames,
                'revision' => $submittedRevision,
            ],
            [
                'column_title'    => (string)$post['title'],
                'column_body'     => (string)$post['body'],
                'column_tags'     => $storedTagNames,
                'column_revision' => (int)$post['revision'],
            ],
            ['title', 'body', 'tags'],
        );
        if (!$revision instanceof \Register\Content\Admin\ContentRevision) {
            return $this->error($request, 'Post has changed in another window', Response::HTTP_CONFLICT);
        }

        $tagsChanged = $tagNames !== $storedTagNames;
        if ($revision->contentChanged) {
            $updated = $this->transactional(function () use ($contentId, $postId, $title, $body, $tagNames, $tagsChanged, $revision, $submittedRevision): bool {
                $affectedRows = $this->dbLayer
                    ->update(ContentSchema::TABLE_NAME)
                    ->set('title', ':title')->setParameter('title', $title)
                    ->set('body', ':body')->setParameter('body', $body)
                    ->set('updated_at', ':updated_at')->setParameter('updated_at', time())
                    ->set('revision', ':new_revision')->setParameter('new_revision', (int)$revision->value)
                    ->where('id = :id')->setParameter('id', $postId)
                    ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
                    ->andWhere('published = 1')
                    ->andWhere('revision = :revision')->setParameter('revision', $submittedRevision)
                    ->execute()
                    ->affectedRows()
                ;
                if ($affectedRows !== 1) {
                    return false;
                }

                if ($tagsChanged) {
                    $this->tagRepository->replace(
                        $contentId,
                        $this->tagRepository->findOrCreateIdsByNames($tagNames),
                    );
                }

                $this->changeDispatcher->dispatch($contentId);

                return true;
            });
            if (!$updated) {
                return $this->error($request, 'Post has changed in another window', Response::HTTP_CONFLICT);
            }
        }

        if (!$this->wantsJson($request)) {
            return new RedirectResponse(
                $this->safeReturnPath($request->request->getString('return_to')),
                Response::HTTP_SEE_OTHER,
            );
        }

        $savedTags = $tagsChanged
            ? ($this->tagRepository->findForContent([$contentId])[(string)$contentId] ?? [])
            : $storedTags;

        return $this->json([
            'success'   => true,
            'action'    => 'edit',
            'title'     => $title,
            'revision'  => (int)$revision->value,
            'body_html' => $this->fragmentRenderer->render(
                '<div class="post body" data-post-inplace-body>' . $body . '</div>',
            ),
            'tags'      => array_map(
                fn(\Register\Content\Tag $tag): array => [
                    'name' => $tag->name,
                    'url'  => $this->blogUrlBuilder->tag($tag->slug),
                ],
                $savedTags,
            ),
            'message'   => $this->translator->trans('Post changes saved'),
        ]);
    }

    /** @return list<string>|null */
    private function parseTags(string $value): ?array
    {
        $tags = [];
        $used = [];
        $parts = preg_split('/[,;\n]+/u', $value);
        if ($parts === false) {
            return null;
        }
        foreach ($parts as $part) {
            $tag = preg_replace('/^\s*#+\s*/u', '', $part);
            $tag = preg_replace('/\s+/u', ' ', $tag ?? '');
            $tag = trim($tag ?? '');
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag) > 255 || preg_match('/^[\p{L}\p{N}_\- !.]+$/uD', $tag) !== 1) {
                return null;
            }

            $key = mb_strtolower($tag);
            if (!isset($used[$key])) {
                $used[$key] = true;
                $tags[]     = $tag;
            }
            if (\count($tags) > self::MAX_TAGS) {
                return null;
            }
        }

        return $tags;
    }

    private function delete(Request $request, int $postId, int $storedRevision, int $submittedRevision): Response
    {
        if ($storedRevision !== $submittedRevision) {
            return $this->error($request, 'Post has changed in another window', Response::HTTP_CONFLICT);
        }

        $contentId = ContentId::post($postId);
        $violations = [];
        foreach ($this->deletionGuards as $deletionGuard) {
            array_push($violations, ...$deletionGuard->violations($contentId));
        }
        if ($violations !== []) {
            return $this->error($request, implode(' ', $violations), Response::HTTP_CONFLICT, false);
        }

        $deleted = $this->transactional(function () use ($contentId, $postId, $submittedRevision): bool {
            $this->tagRepository->remove($contentId);
            $this->commentRepository->removeForContent($contentId);
            $affectedRows = $this->dbLayer
                ->delete(ContentSchema::TABLE_NAME)
                ->where('id = :id')->setParameter('id', $postId)
                ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
                ->andWhere('published = 1')
                ->andWhere('revision = :revision')->setParameter('revision', $submittedRevision)
                ->execute()
                ->affectedRows()
            ;
            if ($affectedRows !== 1) {
                return false;
            }

            $this->changeDispatcher->dispatch($contentId);

            return true;
        });
        if (!$deleted) {
            return $this->error($request, 'Post has changed in another window', Response::HTTP_CONFLICT);
        }

        if (!$this->wantsJson($request)) {
            return new RedirectResponse($this->blogUrlBuilder->main(), Response::HTTP_SEE_OTHER);
        }

        return $this->json([
            'success'  => true,
            'action'   => 'delete',
            'redirect' => $this->blogUrlBuilder->main(),
            'message'  => $this->translator->trans('Post deleted'),
        ]);
    }

    private function revision(Request $request): ?int
    {
        $revision = $request->request->get('revision');
        if (!\is_string($revision) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $revision) !== 1) {
            return null;
        }

        $value = filter_var($revision, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $value === false ? null : $value;
    }

    private function safeReturnPath(string $path): string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\n") || str_contains($path, "\r")) {
            return $this->blogUrlBuilder->main();
        }

        return $this->urlBuilder->rawLink($path);
    }

    /** @param \Closure(): bool $operation */
    private function transactional(\Closure $operation): bool
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }

        try {
            $success = $operation();
            if ($success) {
                if ($ownsTransaction) {
                    $this->pdo->commit();
                } else {
                    $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
                }

                return true;
            }

            if ($ownsTransaction) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            return false;
        } catch (\Throwable $throwable) {
            if ($ownsTransaction) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            throw $throwable;
        }
    }

    private function error(Request $request, string $message, int $status, bool $translate = true): Response
    {
        $message = $translate ? $this->translator->trans($message) : $message;
        if ($this->wantsJson($request)) {
            return $this->json(['success' => false, 'message' => $message], $status);
        }

        return new Response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || str_contains($request->headers->get('Accept') ?? '', 'application/json');
    }
}
