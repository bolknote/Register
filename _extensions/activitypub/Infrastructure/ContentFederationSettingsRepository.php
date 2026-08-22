<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Infrastructure;

use Register\Content\ContentId;
use S2\Cms\Pdo\DbLayer;
use s2_extensions\activitypub\Domain\ContentDeliveryMode;
use s2_extensions\activitypub\Domain\ContentFederationSettings;
use s2_extensions\activitypub\Domain\ContentPublicationMode;
use s2_extensions\activitypub\Domain\PostObjectType;

/** Persists sparse content overrides without depending on Register's mutable content schema. */
final readonly class ContentFederationSettingsRepository
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function find(ContentId $contentId): ContentFederationSettings
    {
        $row = $this->dbLayer->select('*')
            ->from(ActivityPubSchema::CONTENT_SETTING_TABLE)
            ->where('local_type = :local_type')->setParameter('local_type', $contentId->type->value)
            ->andWhere('local_id = :local_id')->setParameter('local_id', $contentId->value)
            ->execute()
            ->fetchAssoc()
        ;
        if (!\is_array($row)) {
            return ContentFederationSettings::inherited($contentId);
        }

        try {
            return new ContentFederationSettings(
                $contentId,
                ContentPublicationMode::from((string)$row['publication_mode']),
                $row['delivery_mode'] === null ? null : ContentDeliveryMode::from((string)$row['delivery_mode']),
                $row['object_type'] === null ? null : PostObjectType::from((string)$row['object_type']),
                $row['visibility'] === null ? null : (string)$row['visibility'],
                (string)$row['summary'],
                $row['language'] === null ? null : (string)$row['language'],
            );
        } catch (\ValueError | \InvalidArgumentException $exception) {
            throw new \RuntimeException('Stored per-content ActivityPub settings are invalid.', 0, $exception);
        }
    }

    public function save(ContentFederationSettings $settings, int $now): void
    {
        if ($now < 1) {
            throw new \InvalidArgumentException('The per-content ActivityPub settings timestamp must be positive.');
        }

        $stored = $this->dbLayer->select('created_at')
            ->from(ActivityPubSchema::CONTENT_SETTING_TABLE)
            ->where('local_type = :local_type')->setParameter('local_type', $settings->contentId->type->value)
            ->andWhere('local_id = :local_id')->setParameter('local_id', $settings->contentId->value)
            ->execute()
            ->fetchAssoc()
        ;
        $createdAt = \is_array($stored) ? (int)$stored['created_at'] : $now;
        $this->dbLayer->upsert(ActivityPubSchema::CONTENT_SETTING_TABLE)
            ->setKey('local_type', ':local_type')->setParameter('local_type', $settings->contentId->type->value)
            ->setKey('local_id', ':local_id')->setParameter('local_id', $settings->contentId->value)
            ->setValue('publication_mode', ':publication_mode')
            ->setParameter('publication_mode', $settings->publicationMode->value)
            ->setValue('delivery_mode', ':delivery_mode')
            ->setParameter('delivery_mode', $settings->deliveryMode?->value)
            ->setValue('object_type', ':object_type')
            ->setParameter('object_type', $settings->postObjectType?->value)
            ->setValue('visibility', ':visibility')->setParameter('visibility', $settings->visibility)
            ->setValue('language', ':language')->setParameter('language', $settings->language)
            ->setValue('summary', ':summary')->setParameter('summary', $settings->summary)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $createdAt)
            ->setValue('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->execute()
        ;
    }

    public function remove(ContentId $contentId): bool
    {
        return $this->dbLayer->delete(ActivityPubSchema::CONTENT_SETTING_TABLE)
            ->where('local_type = :local_type')->setParameter('local_type', $contentId->type->value)
            ->andWhere('local_id = :local_id')->setParameter('local_id', $contentId->value)
            ->execute()
            ->affectedRows() === 1
        ;
    }
}
