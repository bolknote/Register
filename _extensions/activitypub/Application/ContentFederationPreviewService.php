<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Application;

use Register\Author\AuthorProfile;
use Register\Author\AuthorProfileRepository;
use Register\Content\ContentDetails;
use Register\Content\ContentId;
use Register\Content\ContentItem;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\Tag;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlGenerator;
use Register\Core\Pdo\DbLayer;
use Register\Extension\activitypub\Domain\ContentFederationSettings;
use Register\Extension\activitypub\Domain\ContentFederationSettingsDraft;
use Register\Extension\activitypub\Domain\ContentProjectionAction;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\FederationState;
use Register\Extension\activitypub\Infrastructure\FederationStateRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\StoredObjectRepresentation;
use Register\Extension\activitypub\Presentation\CanonicalJson;
use Register\Extension\activitypub\Presentation\ContentObjectDocumentBuilder;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;

/** Produces an exact, side-effect-free object preview from the unsaved AdminYard form. */
final readonly class ContentFederationPreviewService
{
    public function __construct(
        private DbLayer                       $dbLayer,
        private ContentUrlGenerator            $contentUrlGenerator,
        private ContentSlugService              $contentSlugService,
        private AuthorProfileRepository         $authorRepository,
        private FederationStateRepository       $stateRepository,
        private LocalFederationRepository       $federationRepository,
        private FederationUrlGeneratorFactory   $urlGeneratorFactory,
        private ContentActorResolver             $actorResolver,
        private ContentObjectDocumentBuilder    $objectBuilder,
        private CanonicalJson                    $json,
    ) {
    }

    /**
     * @param array<string, mixed> $formData Validated AdminYard form data.
     */
    public function preview(
        ContentType                    $contentType,
        ?int                           $contentIdValue,
        array                          $formData,
        ContentFederationSettingsDraft $settingsDraft,
        int                            $requestingUserId,
        bool                           $mayEditAnyContent,
        int                            $now,
    ): ContentFederationPreview {
        if ($requestingUserId < 1 || $now < 1) {
            throw new \InvalidArgumentException('The ActivityPub preview context is invalid.');
        }

        if ($settingsDraft->contentType !== $contentType) {
            throw new \InvalidArgumentException('The ActivityPub preview settings target another content type.');
        }

        $row = $contentIdValue === null
            ? null
            : $this->contentRow(new ContentId($contentType, $contentIdValue));
        if ($contentIdValue === null && $contentType !== ContentType::POST) {
            throw new \InvalidArgumentException('Only a blog post can be previewed before its first save.');
        }

        $storedAuthorId = $row === null ? null : $this->nullablePositiveInt($row['author_id']);
        if ($row !== null && !$mayEditAnyContent && $storedAuthorId !== $requestingUserId) {
            throw new \DomainException('Permission denied.');
        }

        $contentId = new ContentId($contentType, $contentIdValue ?? 1);
        $title = $this->requiredString($formData, 'title', $row);
        $body = $this->requiredString($formData, 'body', $row);
        $excerpt = $this->optionalString($formData, 'excerpt', $row);
        $description = $this->optionalString($formData, 'meta_description', $row);
        $published = $this->boolean($formData, 'published', $row);
        $publishedAt = $this->timestamp($formData['published_at'] ?? ($row['published_at'] ?? null));
        $updatedAt = $this->timestamp($formData['updated_at'] ?? ($row['updated_at'] ?? null)) ?? $now;
        $slug = $contentType === ContentType::POST && $contentIdValue === null
            ? $this->contentSlugService->generatePost($title)
            : $this->requiredString($formData, 'slug', $row);
        $path = $this->path($contentId, $slug, $row, $published);

        $authorId = $this->authorId(
            $formData,
            $storedAuthorId,
            $contentIdValue === null ? $requestingUserId : null,
            $mayEditAnyContent,
        );
        $author = $authorId === null ? null : $this->authorRepository->find($authorId);
        if ($authorId !== null && !$author instanceof AuthorProfile) {
            throw new \DomainException('The selected content author does not exist.');
        }

        $authorName = $author instanceof AuthorProfile ? $author->displayName : '';
        $details = new ContentDetails(
            new ContentItem(
                id: $contentId,
                title: $title,
                body: $body,
                path: $path,
                publishedAt: $publishedAt,
                description: $description,
                updatedAt: $updatedAt,
                author: $authorName,
                excerpt: $excerpt,
                authorId: $authorId,
                featured: $this->boolean($formData, 'featured', $row),
            ),
            $author,
            $this->tags($this->optionalString($formData, 'tags', $row)),
        );

        $state = $this->stateRepository->state();
        if (!\in_array($state->lifecycle, [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED], true)) {
            throw new \DomainException('ActivityPub preview requires an active or paused federation identity.');
        }

        $settings = $settingsDraft->bind($contentId);
        $federationEnabled = $settings->isEnabled($state);
        $current = $contentIdValue === null ? null : $this->federationRepository->findLiveObject($contentId);
        if (!$published || !$federationEnabled) {
            return new ContentFederationPreview(
                $current instanceof StoredObjectRepresentation
                    ? ContentProjectionAction::TOMBSTONED
                    : ContentProjectionAction::SKIPPED,
                null,
                '',
                '',
                $this->urlGeneratorFactory->create()->resource($path),
                $published,
                $federationEnabled,
                [],
            );
        }

        return $this->buildEnabledPreview($details, $settings, $state, $current, $now);
    }

    /** @return array<string, mixed> */
    private function contentRow(ContentId $contentId): array
    {
        $row = $this->dbLayer->select('*')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $contentId->value)
            ->andWhere('content_type = :content_type')->setParameter('content_type', $contentId->type->value)
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            throw new \DomainException('The content selected for ActivityPub preview does not exist.');
        }

        return $row;
    }

    private function buildEnabledPreview(
        ContentDetails             $details,
        ContentFederationSettings  $settings,
        FederationState            $state,
        ?StoredObjectRepresentation $current,
        int                        $now,
    ): ContentFederationPreview {
        $owner = $this->actorResolver->ownerFor($details->content->authorId);
        $sameIncarnation = null;
        if ($current instanceof StoredObjectRepresentation && $current->ownerActorId === $owner->id) {
            $sameIncarnation = $current;
        }

        $urls = $this->urlGeneratorFactory->create();
        $publicId = $sameIncarnation instanceof StoredObjectRepresentation
            ? $sameIncarnation->publicId
            : $this->previewPublicId($details->content->id);
        $objectType = $sameIncarnation instanceof StoredObjectRepresentation
            ? $sameIncarnation->objectType
            : $settings->resolvesObjectType($state);
        $publishedAt = $sameIncarnation instanceof StoredObjectRepresentation
            ? $sameIncarnation->publishedAt
            : ($details->content->publishedAt ?? $details->content->updatedAt ?? $now);
        $updatedAt = $sameIncarnation instanceof StoredObjectRepresentation
            ? max($sameIncarnation->updatedAt, $details->content->updatedAt ?? 0, $sameIncarnation->publishedAt)
            : max($publishedAt, $details->content->updatedAt ?? 0);
        $provisionalFields = $sameIncarnation instanceof StoredObjectRepresentation ? [] : ['id'];

        $document = $this->objectBuilder->build(
            $details,
            $owner,
            $urls,
            $publicId,
            $objectType,
            $settings->resolvesVisibility($state),
            $settings->resolvesDeliveryMode($state),
            $publishedAt,
            $updatedAt,
            $this->actorResolver->additionalFollowerCollections($owner, $urls),
            $settings->summary,
            $settings->language,
        );
        $canonicalJson = $this->json->encode($document);
        $action = ContentProjectionAction::CREATED;
        if ($sameIncarnation instanceof StoredObjectRepresentation) {
            $featureChanged = ($sameIncarnation->featuredAt !== null) !== $details->content->featured;
            if (hash_equals($sameIncarnation->snapshotHash, hash('sha256', $canonicalJson)) && !$featureChanged) {
                $action = ContentProjectionAction::UNCHANGED;
            } else {
                $action = ContentProjectionAction::UPDATED;
                if ($updatedAt <= $sameIncarnation->updatedAt) {
                    $updatedAt = max($now, $sameIncarnation->updatedAt + 1);
                    $provisionalFields[] = 'updated';
                    $document = $this->objectBuilder->build(
                        $details,
                        $owner,
                        $urls,
                        $publicId,
                        $objectType,
                        $settings->resolvesVisibility($state),
                        $settings->resolvesDeliveryMode($state),
                        $publishedAt,
                        $updatedAt,
                        $this->actorResolver->additionalFollowerCollections($owner, $urls),
                        $settings->summary,
                        $settings->language,
                    );
                    $canonicalJson = $this->json->encode($document);
                }
            }
        } elseif ($current instanceof StoredObjectRepresentation) {
            $action = ContentProjectionAction::REPLACED;
        }

        return new ContentFederationPreview(
            $action,
            $document,
            $canonicalJson,
            $owner->handle,
            $urls->resource($details->content->path),
            true,
            true,
            array_values(array_unique($provisionalFields)),
        );
    }

    /** @param array<string, mixed>|null $row */
    private function path(ContentId $contentId, string $slug, ?array $row, bool $published): string
    {
        if ($contentId->type === ContentType::POST) {
            if ($published && $this->contentSlugService->postStatus($contentId->value, $slug) !== ContentSlugService::STATUS_OK) {
                throw new \DomainException('The post URL is not available for ActivityPub preview.');
            }

            return $this->contentUrlGenerator->postPath($slug);
        }

        if ($row === null) {
            throw new \LogicException('A page preview has no stored page row.');
        }

        if ($row['parent_id'] === null) {
            return '/';
        }

        if ($published && !\in_array(
            $this->contentSlugService->pageStatus($contentId->value, $slug),
            [ContentSlugService::STATUS_OK, ContentSlugService::STATUS_MAIN_PAGE],
            true,
        )) {
            throw new \DomainException('The page URL is not available for ActivityPub preview.');
        }

        $currentPath = $this->contentUrlGenerator->pagePath($contentId->value);
        if ($currentPath === null) {
            throw new \DomainException('The page path cannot be resolved for ActivityPub preview.');
        }

        $hasTrailingSlash = str_ends_with($currentPath, '/');
        $trimmedPath = rtrim($currentPath, '/');
        $lastSlash = strrpos($trimmedPath, '/');
        $prefix = $lastSlash === false ? '' : substr($trimmedPath, 0, $lastSlash + 1);

        return $prefix . rawurlencode($slug) . ($hasTrailingSlash ? '/' : '');
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $row
     */
    private function requiredString(array $data, string $key, ?array $row): string
    {
        $value = $data[$key] ?? ($row[$key] ?? null);
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('A required ActivityPub preview field is missing.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $row
     */
    private function optionalString(array $data, string $key, ?array $row): string
    {
        $value = $data[$key] ?? ($row[$key] ?? '');
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('An ActivityPub preview field has an invalid type.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $row
     */
    private function boolean(array $data, string $key, ?array $row): bool
    {
        $value = $data[$key] ?? ($row[$key] ?? false);

        return \is_bool($value) ? $value : (bool)$value;
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if ($value === null || $value === '' || (int)$value < 1) {
            return null;
        }

        if (!\is_int($value) && !\is_string($value)) {
            throw new \InvalidArgumentException('An ActivityPub preview date has an invalid type.');
        }

        return (int)$value;
    }

    /** @param array<string, mixed> $data */
    private function authorId(
        array $data,
        ?int  $storedAuthorId,
        ?int  $newAuthorId,
        bool  $mayEditAnyContent,
    ): ?int {
        if ($newAuthorId !== null) {
            return $newAuthorId;
        }

        if (!$mayEditAnyContent || !array_key_exists('author_id', $data)) {
            return $storedAuthorId;
        }

        return $this->nullablePositiveInt($data['author_id']);
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ((!\is_int($value) && !\is_string($value)) || (int)$value < 1) {
            throw new \InvalidArgumentException('An ActivityPub preview author identifier is invalid.');
        }

        return (int)$value;
    }

    /** @return list<Tag> */
    private function tags(string $value): array
    {
        $tags = [];
        $used = [];
        foreach (explode(',', $value) as $name) {
            $name = trim($name);
            $key = mb_strtolower($name);
            if ($name === '' || isset($used[$key])) {
                continue;
            }

            $used[$key] = true;
            $tags[] = new Tag(\count($tags) + 1, $name, $name, '');
        }

        return $tags;
    }

    private function previewPublicId(ContentId $contentId): string
    {
        $bytes = substr(hash('sha256', 'activitypub-editor-preview:' . (string)$contentId, true), 0, 16);
        $publicId = sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        if (\strlen($publicId) !== 22) {
            throw new \LogicException('Unable to derive an ActivityPub preview identifier.');
        }

        return $publicId;
    }
}
