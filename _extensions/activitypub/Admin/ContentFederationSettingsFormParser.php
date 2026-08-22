<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Admin;

use Register\Content\ContentType;
use Register\Extension\activitypub\Domain\ContentDeliveryMode;
use Register\Extension\activitypub\Domain\ContentFederationSettingsDraft;
use Register\Extension\activitypub\Domain\ContentPublicationMode;
use Register\Extension\activitypub\Domain\PostObjectType;

/** Converts the shared editorial form fields into one validated federation draft. */
final readonly class ContentFederationSettingsFormParser
{
    /**
     * @param array<string, mixed> $data
     */
    public function extract(ContentType $contentType, array &$data): ContentFederationSettingsDraft
    {
        $draft = $this->parse($contentType, $data);
        foreach (ContentSettingsEditor::fieldNames() as $field) {
            unset($data[$field]);
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function parse(ContentType $contentType, array $data): ContentFederationSettingsDraft
    {
        $publication = $this->string(
            $data,
            ContentSettingsEditor::PUBLICATION_FIELD,
            ContentPublicationMode::INHERIT->value,
        );
        $delivery = $this->string($data, ContentSettingsEditor::DELIVERY_FIELD, 'inherit');
        $objectType = $contentType === ContentType::POST
            ? $this->string($data, ContentSettingsEditor::OBJECT_TYPE_FIELD, 'inherit')
            : 'inherit';
        $visibility = $this->string($data, ContentSettingsEditor::VISIBILITY_FIELD, 'inherit');
        $summary = trim($this->string($data, ContentSettingsEditor::SUMMARY_FIELD, ''));
        $language = strtolower(trim($this->string($data, ContentSettingsEditor::LANGUAGE_FIELD, '')));

        try {
            return new ContentFederationSettingsDraft(
                $contentType,
                ContentPublicationMode::from($publication),
                $delivery === 'inherit' ? null : ContentDeliveryMode::from($delivery),
                $objectType === 'inherit' ? null : PostObjectType::from($objectType),
                $visibility === 'inherit' ? null : $visibility,
                $summary,
                $language === '' ? null : $language,
            );
        } catch (\ValueError $exception) {
            throw new \InvalidArgumentException(
                'The submitted ActivityPub content settings are invalid.',
                0,
                $exception,
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $field, string $default): string
    {
        $value = $data[$field] ?? $default;
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('A submitted ActivityPub editor field has an invalid type.');
        }

        return $value;
    }
}
