<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use Register\Ai\AiClient;
use Register\Ai\AiException;
use Register\Ai\AiSettings;
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
use Register\Module\Blog\Model\PostProvider;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlGenerator;
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

    private const int MAX_AI_TEXT_LENGTH = 60000;

    private const int MAX_TAGS = 100;

    private const int STALE_PENDING_MEDIA_AGE = 7 * 24 * 60 * 60;

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
        private PostMediaRepository         $mediaRepository,
        private ContentSlugService          $contentSlugService,
        private ContentUrlGenerator         $contentUrlGenerator,
        private PostProvider                $postProvider,
        private AiClient                   $aiClient,
        private AiSettings                 $aiSettings,
        private TranslatorInterface        $translator,
        ContentDeletionGuardInterface ...$deletionGuards,
    ) {
        $this->deletionGuards = array_values($deletionGuards);
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if ($request->attributes->getBoolean('create')) {
            return $this->handleCreateRequest($request);
        }

        $postId = $request->attributes->getInt('id');
        if ($postId <= 0) {
            return $this->error($request, 'Invalid post mutation request', Response::HTTP_BAD_REQUEST);
        }

        $post = $this->dbLayer
            ->select('id, author_id, revision, title, body, slug, published_at, date_label')
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
            return $this->uploadMedia($request, $editor);
        }

        if ($action === 'media_conflict') {
            return $this->resolveMediaConflict($request, $editor);
        }

        if ($action === 'media_release') {
            return $this->releaseMedia($request, $editor);
        }

        if ($action === 'ai') {
            return $this->generateWithAi($request, (string)$post['title']);
        }

        $revision = $this->revision($request);
        if ($revision === null || !\in_array($action, ['edit', 'delete'], true)) {
            return $this->error($request, 'Invalid post mutation request', Response::HTTP_BAD_REQUEST);
        }

        if ($action === 'delete') {
            return $this->delete($request, $postId, (int)$post['revision'], $revision);
        }

        return $this->edit($request, $post, $revision, $editor);
    }

    private function handleCreateRequest(Request $request): Response
    {
        $editor = $this->controls->editorForCreate($request);
        if (!$editor instanceof AuthenticatedPublicUser) {
            return $this->error($request, 'Post editing forbidden', Response::HTTP_FORBIDDEN);
        }

        if (!$this->tokenManager->isValidForCreate($request->request->getString('inplace_token'), $editor)) {
            return $this->error($request, 'Post editing token expired', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return match ($request->request->getString('inplace_action')) {
            'media'         => $this->uploadMedia($request, $editor),
            'media_conflict' => $this->resolveMediaConflict($request, $editor),
            'media_release' => $this->releaseMedia($request, $editor),
            'ai'            => $this->generateWithAi($request, ''),
            'create'        => $this->create($request, $editor),
            default         => $this->error($request, 'Invalid post mutation request', Response::HTTP_BAD_REQUEST),
        };
    }

    private function uploadMedia(Request $request, AuthenticatedPublicUser $editor): Response
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

        $this->purgeMedia(
            $this->mediaRepository->stalePendingUploads(time() - self::STALE_PENDING_MEDIA_AGE),
            false,
        );

        $path         = '/' . date('Y/m');
        $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $mimeType     = $this->mediaStorage->detectMimeType($file);
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

        $imageInfo = $kind === 'image' ? $this->mediaStorage->getImageInfo($storedFile) : [];
        try {
            $mediaId = $this->mediaRepository->register([
                'original_name'   => $originalName,
                'normalized_name' => $this->mediaStorage->normalizeName($originalName),
                'storage_path'    => $storedFile,
                'mime_type'       => $mimeType,
                'kind'            => $kind,
                'byte_size'       => $this->mediaStorage->fileSize($storedFile),
                'width'           => isset($imageInfo[0]) ? (int)$imageInfo[0] : null,
                'height'          => isset($imageInfo[1]) ? (int)$imageInfo[1] : null,
                'uploaded_by'     => $editor->id,
            ]);
        } catch (\Throwable $throwable) {
            $this->mediaStorage->delete($storedFile);
            throw $throwable;
        }

        $media = $this->mediaRepository->find($mediaId);
        if ($media === null) {
            throw new \LogicException('The uploaded media registry row was not created.');
        }

        if ($kind === 'image') {
            $existing = $this->mediaRepository->findImageWithName(
                (string)$media['normalized_name'],
                $mediaId,
                $editor->id,
                $editor->canEditSite,
            );
            if ($existing !== null) {
                return $this->json([
                    'success'       => false,
                    'action'        => 'media_conflict',
                    'incoming'      => $this->mediaPayload($media),
                    'existing'      => $this->mediaPayload($existing),
                    'can_overwrite' => $this->canOverwrite($editor, $existing),
                ], Response::HTTP_CONFLICT);
            }
        }

        return $this->json($this->mediaPayload($media));
    }

    private function resolveMediaConflict(Request $request, AuthenticatedPublicUser $editor): Response
    {
        $candidateId = $request->request->getInt('media_id');
        $existingId  = $request->request->getInt('existing_id');
        $decision    = $request->request->getString('conflict_action');
        $candidate   = $this->mediaRepository->find($candidateId);
        $existing    = $this->mediaRepository->find($existingId);
        if (
            $candidate === null
            || (int)$candidate['uploaded_by'] !== $editor->id
            || !(bool)$candidate['pending']
            || (int)$candidate['usage_count'] !== 0
            || (string)$candidate['kind'] !== 'image'
            || !\in_array($decision, ['cancel', 'keep', 'overwrite'], true)
        ) {
            return $this->error($request, 'Invalid post mutation request', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($decision === 'cancel') {
            $this->purgeMedia([$candidate], true);

            return $this->json(['success' => true, 'action' => 'media_cancelled']);
        }

        if ($decision === 'keep') {
            return $this->json($this->mediaPayload($candidate));
        }

        if (
            $existing === null
            || (string)$existing['kind'] !== 'image'
            || (string)$existing['normalized_name'] !== (string)$candidate['normalized_name']
            || !$this->canOverwrite($editor, $existing)
        ) {
            return $this->error($request, 'Post editing forbidden', Response::HTTP_FORBIDDEN);
        }

        $this->mediaStorage->replace(
            (string)$existing['storage_path'],
            (string)$candidate['storage_path'],
        );
        $this->mediaRepository->replaceMetadata((int)$existing['id'], [
            'original_name'   => (string)$candidate['original_name'],
            'normalized_name' => (string)$candidate['normalized_name'],
            'mime_type'       => (string)$candidate['mime_type'],
            'byte_size'       => (int)$candidate['byte_size'],
            'width'           => $candidate['width'] === null ? null : (int)$candidate['width'],
            'height'          => $candidate['height'] === null ? null : (int)$candidate['height'],
        ]);
        $this->mediaRepository->deleteUnused($candidateId);

        $replaced = $this->mediaRepository->find($existingId);
        if ($replaced === null) {
            throw new \LogicException('The replaced media registry row disappeared.');
        }

        return $this->json($this->mediaPayload($replaced));
    }

    private function releaseMedia(Request $request, AuthenticatedPublicUser $editor): JsonResponse
    {
        $media = $this->mediaRepository->releasableUploads(
            $this->mediaIds($request->request->getString('media_ids')),
            $editor->id,
        );
        $this->purgeMedia($media, true);

        return $this->json(['success' => true, 'action' => 'media_release']);
    }

    private function generateWithAi(Request $request, string $storedTitle): Response
    {
        $action = $request->request->getString('ai_action');
        $title  = trim($request->request->getString('title'));
        $text   = trim($request->request->getString('text'));
        if (!AiClient::supportsAction($action) || $text === '') {
            return $this->error($request, 'Invalid AI request', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (mb_strlen($text) > self::MAX_AI_TEXT_LENGTH) {
            return $this->error($request, 'AI text is too long', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$this->aiSettings->isConfigured()) {
            return $this->error($request, 'AI is not configured', Response::HTTP_CONFLICT);
        }

        try {
            $result = $this->aiClient->generate(
                $action,
                mb_substr($title !== '' ? $title : $storedTitle, 0, 500),
                $text,
            );
        } catch (AiException) {
            return $this->error($request, 'AI request failed', Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'success'   => true,
            'action'    => 'ai',
            'ai_action' => $action,
            'result'    => $result,
        ]);
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
    private function edit(
        Request                 $request,
        array                   $post,
        int                     $submittedRevision,
        AuthenticatedPublicUser $editor,
    ): Response
    {
        $postId         = (int)$post['id'];
        $contentId      = ContentId::post($postId);
        $storedTags     = $this->tagRepository->findForContent([$contentId])[(string)$contentId] ?? [];
        $storedTagNames = array_map(static fn(\Register\Content\Tag $tag): string => $tag->name, $storedTags);
        $title          = trim($request->request->getString('title'));
        $body           = $request->request->getString('body');
        $publishedAt    = $this->publishedAt($request, (int)$post['published_at']);
        $tagNames       = $storedTagNames;
        if ($request->request->has('tags')) {
            $submittedTags = $request->request->get('tags');
            $tagNames      = \is_string($submittedTags) ? $this->parseTags($submittedTags) : null;
            if ($tagNames === null) {
                return $this->error($request, 'Invalid post tags', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (
            $publishedAt === null
            || $title === ''
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
                'published_at' => $publishedAt,
                'revision' => $submittedRevision,
            ],
            [
                'column_title'    => (string)$post['title'],
                'column_body'     => (string)$post['body'],
                'column_tags'     => $storedTagNames,
                'column_published_at' => (int)$post['published_at'],
                'column_revision' => (int)$post['revision'],
            ],
            ['title', 'body', 'tags', 'published_at'],
        );
        if (!$revision instanceof \Register\Content\Admin\ContentRevision) {
            return $this->error($request, 'Post has changed in another window', Response::HTTP_CONFLICT);
        }

        $tagsChanged = $tagNames !== $storedTagNames;
        $dateChanged = $publishedAt !== (int)$post['published_at'];
        $orphanMedia = [];
        if ($revision->contentChanged) {
            $updated = $this->transactional(function () use ($request, $contentId, $postId, $title, $body, $publishedAt, $dateChanged, $tagNames, $tagsChanged, $revision, $submittedRevision, $editor, &$orphanMedia): bool {
                $update = $this->dbLayer
                    ->update(ContentSchema::TABLE_NAME)
                    ->set('title', ':title')->setParameter('title', $title)
                    ->set('body', ':body')->setParameter('body', $body)
                    ->set('updated_at', ':updated_at')->setParameter('updated_at', time())
                    ->set('revision', ':new_revision')->setParameter('new_revision', (int)$revision->value)
                    ->where('id = :id')->setParameter('id', $postId)
                    ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
                    ->andWhere('published = 1')
                    ->andWhere('revision = :revision')->setParameter('revision', $submittedRevision)
                ;
                if ($dateChanged) {
                    $update
                        ->set('published_at', ':published_at')->setParameter('published_at', $publishedAt)
                        ->set('date_label', "''")
                    ;
                }

                $affectedRows = $update->execute()
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

                $orphanMedia = $this->mediaRepository->syncPost(
                    $postId,
                    $body,
                    $this->mediaIds($request->request->getString('uploaded_media_ids')),
                    $editor->id,
                );

                $this->changeDispatcher->dispatch($contentId);

                return true;
            });
            if (!$updated) {
                return $this->error($request, 'Post has changed in another window', Response::HTTP_CONFLICT);
            }

            $this->purgeMedia($orphanMedia, false);
        } else {
            $orphanMedia = $this->mediaRepository->releasableUploads(
                $this->mediaIds($request->request->getString('uploaded_media_ids')),
                $editor->id,
            );
            $this->purgeMedia($orphanMedia, false);
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
            'published_at' => $publishedAt,
            'datetime'  => gmdate(DATE_ATOM, $publishedAt),
            'time'      => $this->postProvider->displayDate($publishedAt, $dateChanged ? '' : (string)$post['date_label']),
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

    private function create(Request $request, AuthenticatedPublicUser $editor): Response
    {
        $title       = trim($request->request->getString('title'));
        $body        = $request->request->getString('body');
        $publishedAt = $this->publishedAt($request, time());
        $tagNames    = $this->parseTags($request->request->getString('tags'));
        if (
            $publishedAt === null
            || $tagNames === null
            || $title === ''
            || mb_strlen($title) > 255
            || \strlen($body) > self::MAX_BODY_BYTES
            || str_contains($body, "\0")
        ) {
            return $this->error($request, 'Invalid post content', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $postId      = 0;
        $slug        = '';
        $orphanMedia = [];
        $created = $this->transactional(function () use ($request, $editor, $title, $body, $publishedAt, $tagNames, &$postId, &$slug, &$orphanMedia): bool {
            $now  = time();
            $slug = $this->contentSlugService->generatePost($title);
            $this->dbLayer
                ->insert(ContentSchema::TABLE_NAME)
                ->values([
                    'content_type'     => ':content_type',
                    'slug_scope'       => "'root'",
                    'slug'             => ':slug',
                    'title'            => ':title',
                    'excerpt'          => "''",
                    'body'             => ':body',
                    'created_at'       => ':created_at',
                    'published_at'     => ':published_at',
                    'updated_at'       => ':updated_at',
                    'revision'         => '1',
                    'published'        => '1',
                    'comments_enabled' => '1',
                    'author_id'        => ':author_id',
                ])
                ->execute([
                    'content_type' => ContentType::POST->value,
                    'slug'         => $slug,
                    'title'        => $title,
                    'body'         => $body,
                    'created_at'   => $now,
                    'published_at' => $publishedAt,
                    'updated_at'   => $now,
                    'author_id'    => $editor->id,
                ])
            ;
            $postId = (int)$this->dbLayer->insertId();
            if ($postId <= 0) {
                return false;
            }

            $contentId = ContentId::post($postId);
            if ($tagNames !== []) {
                $this->tagRepository->replace(
                    $contentId,
                    $this->tagRepository->findOrCreateIdsByNames($tagNames),
                );
            }

            $orphanMedia = $this->mediaRepository->syncPost(
                $postId,
                $body,
                $this->mediaIds($request->request->getString('uploaded_media_ids')),
                $editor->id,
            );
            $this->changeDispatcher->dispatch($contentId);

            return true;
        });
        if (!$created) {
            return $this->error($request, 'Post editing failed', Response::HTTP_CONFLICT);
        }

        $this->purgeMedia($orphanMedia, false);

        $url = $this->contentUrlGenerator->post($slug);
        if (!$this->wantsJson($request)) {
            return new RedirectResponse($url, Response::HTTP_SEE_OTHER);
        }

        $controls = $this->controls->forPost($request, $postId, $editor->id, 1);
        if ($controls === null) {
            throw new \LogicException('The newly created post is not editable by its author.');
        }

        $savedTags = $this->tagRepository->findForContent([ContentId::post($postId)])[(string)ContentId::post($postId)] ?? [];

        return $this->json([
            'success'        => true,
            'action'         => 'create',
            'id'             => $postId,
            'url'            => $url,
            'action_url'     => $controls['action_url'],
            'admin_edit_url' => $controls['admin_edit_url'],
            'token'          => $controls['token'],
            'title'          => $title,
            'revision'       => 1,
            'published_at'   => $publishedAt,
            'datetime'       => gmdate(DATE_ATOM, $publishedAt),
            'time'           => $this->postProvider->displayDate($publishedAt, ''),
            'body_html'      => $this->fragmentRenderer->render(
                '<div class="post body" data-post-inplace-body>' . $body . '</div>',
            ),
            'tags'           => array_map(
                fn(\Register\Content\Tag $tag): array => [
                    'name' => $tag->name,
                    'url'  => $this->blogUrlBuilder->tag($tag->slug),
                ],
                $savedTags,
            ),
            'message'        => $this->translator->trans('Post created'),
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

        $orphanMedia = [];
        $deleted = $this->transactional(function () use ($contentId, $postId, $submittedRevision, &$orphanMedia): bool {
            $this->tagRepository->remove($contentId);
            $this->commentRepository->removeForContent($contentId);
            $orphanMedia = $this->mediaRepository->releasePost($postId);
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

        $this->purgeMedia($orphanMedia, false);

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

    private function publishedAt(Request $request, int $fallback): ?int
    {
        if (!$request->request->has('published_at')) {
            return $fallback;
        }

        $value = $request->request->get('published_at');
        if (!\is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $timestamp = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4_102_444_799],
        ]);

        return $timestamp === false ? null : $timestamp;
    }

    /** @return list<int> */
    private function mediaIds(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $value) as $part) {
            if (preg_match('/^[1-9][0-9]*$/D', $part) !== 1) {
                continue;
            }

            $ids[(int)$part] = true;
            if (\count($ids) >= 1000) {
                break;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<string, mixed> $media
     *
     * @return array<string, mixed>
     */
    private function mediaPayload(array $media): array
    {
        $url = $this->mediaRepository->url((string)$media['storage_path']);

        return [
            'success'     => true,
            'action'      => 'media',
            'media_id'    => (int)$media['id'],
            'kind'        => (string)$media['kind'],
            'url'         => $url,
            'preview_url' => $url . '?editor-media=' . (int)$media['created_at'],
            'name'        => (string)$media['original_name'],
            'width'       => $media['width'] === null ? null : (int)$media['width'],
            'height'      => $media['height'] === null ? null : (int)$media['height'],
        ];
    }

    /** @param array<string, mixed> $media */
    private function canOverwrite(AuthenticatedPublicUser $editor, array $media): bool
    {
        return $editor->canEditSite
            || ($media['uploaded_by'] !== null && (int)$media['uploaded_by'] === $editor->id);
    }

    /** @param array<int, array<string, mixed>> $media */
    private function purgeMedia(array $media, bool $strict): void
    {
        foreach ($media as $item) {
            try {
                if ($this->mediaRepository->deleteUnused((int)$item['id'])) {
                    $this->mediaStorage->delete((string)$item['storage_path']);
                }
            } catch (\RuntimeException $runtimeException) {
                if ($strict) {
                    throw $runtimeException;
                }
            }
        }
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
