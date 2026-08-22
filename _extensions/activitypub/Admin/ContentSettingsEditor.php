<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Admin;

use Register\Content\ContentId;
use Register\Content\ContentType;
use Register\Extension\activitypub\Application\ContentProjectionService;
use Register\Extension\activitypub\Application\ContentProjectionStaging;
use Register\Extension\activitypub\Domain\ContentFederationSettingsDraft;
use Register\Extension\activitypub\Domain\PostObjectType;
use Register\Extension\activitypub\Infrastructure\ContentFederationSettingsRepository;
use Register\Extension\activitypub\Infrastructure\LocalFederationRepository;
use Register\Extension\activitypub\Infrastructure\StoredObjectRepresentation;

/** Parses editor controls, freezes protocol invariants and commits one post-save projection. */
final readonly class ContentSettingsEditor
{
    public const string PUBLICATION_FIELD = 'activitypub_publication';

    public const string DELIVERY_FIELD = 'activitypub_delivery';

    public const string OBJECT_TYPE_FIELD = 'activitypub_object_type';

    public const string VISIBILITY_FIELD = 'activitypub_visibility';

    public const string SUMMARY_FIELD = 'activitypub_summary';

    public const string LANGUAGE_FIELD = 'activitypub_language';

    private const string CONTEXT_KEY = 'activitypub_content_settings';

    public function __construct(
        private ContentFederationSettingsRepository $settingsRepository,
        private LocalFederationRepository           $federationRepository,
        private ContentProjectionService            $projectionService,
        private ContentProjectionStaging            $staging,
        private ContentFederationSettingsFormParser $formParser,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    public function stageUpdate(ContentId $contentId, array &$data, array &$context): void
    {
        $draft = $this->formParser->extract($contentId->type, $data);
        $current = $this->federationRepository->findLiveObject($contentId);
        if ($current instanceof StoredObjectRepresentation
            && $draft->postObjectType instanceof PostObjectType
            && $draft->postObjectType->value !== $current->objectType
        ) {
            throw new \DomainException('The ActivityPub object type is frozen after its first publication.');
        }

        $context[self::CONTEXT_KEY] = $draft;
        $this->staging->defer($contentId);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    public function stageCreate(ContentType $contentType, array &$data, array &$context): void
    {
        $context[self::CONTEXT_KEY] = $this->formParser->extract($contentType, $data);
        $this->staging->deferNew($contentType);
    }

    /** @param array<string, mixed> $context */
    public function complete(ContentId $contentId, array $context, ?int $now = null): void
    {
        $draft = $context[self::CONTEXT_KEY] ?? null;
        if (!$draft instanceof ContentFederationSettingsDraft) {
            throw new \LogicException('The ActivityPub editor settings draft is missing after save.');
        }

        $timestamp = $now ?? time();
        try {
            $this->settingsRepository->save($draft->bind($contentId), $timestamp);
            $this->projectionService->synchronize($contentId, now: $timestamp);
        } finally {
            $this->staging->release($contentId);
        }
    }

    /** @return list<string> */
    public static function fieldNames(): array
    {
        return [
            self::PUBLICATION_FIELD,
            self::DELIVERY_FIELD,
            self::OBJECT_TYPE_FIELD,
            self::VISIBILITY_FIELD,
            self::SUMMARY_FIELD,
            self::LANGUAGE_FIELD,
        ];
    }
}
