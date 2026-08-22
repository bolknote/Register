<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Admin;

use Register\Content\ContentId;
use Register\Content\ContentType;
use S2\AdminYard\Config\AdminConfig;
use S2\AdminYard\Config\EntityConfig;
use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\Config\VirtualFieldType;
use S2\AdminYard\Event\AfterSaveEvent;
use S2\AdminYard\Event\BeforeSaveEvent;
use S2\AdminYard\Translator;
use S2\AdminYard\Validator\Length;
use S2\Cms\Admin\AdminConfigExtenderInterface;
use s2_extensions\activitypub\Infrastructure\ActivityPubSchema;

final readonly class AdminConfigExtender implements AdminConfigExtenderInterface
{
    public function __construct(
        private ActivityPubAdminAccess $access,
        private ActivityPubAdminPage $adminPage,
        private ContentSettingsEditor $contentSettingsEditor,
        private ActivityPubContentEditorControllerFactory $contentEditorControllerFactory,
        private Translator           $translator,
        private string               $dbPrefix,
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        $post = $adminConfig->findEntityByName('BlogPost')
            ?? throw new \LogicException('The blog-post admin entity is missing.');
        $page = $adminConfig->findEntityByName('Article')
            ?? throw new \LogicException('The page admin entity is missing.');
        $this->configureContentEntity($post, ContentType::POST, true);
        $this->configureContentEntity($page, ContentType::PAGE, false);

        if (!$this->access->canAccess()) {
            return;
        }

        $adminConfig->setServicePage(
            'ActivityPub',
            $this->adminPage->render(...),
            54,
            $this->adminPage->title(),
        );
    }

    private function configureContentEntity(EntityConfig $entity, ContentType $contentType, bool $allowCreate): void
    {
        if ($entity->getControllerClassOrFactory() !== null) {
            throw new \LogicException('ActivityPub cannot compose with the configured content editor controller.');
        }

        $entity->setControllerClassOrFactory($this->contentEditorControllerFactory);
        $actions = [FieldConfig::ACTION_EDIT, ...$allowCreate ? [FieldConfig::ACTION_NEW] : []];
        $entity
            ->addField(new FieldConfig(
                name: ContentSettingsEditor::PUBLICATION_FIELD,
                label: $this->translator->trans('ActivityPub publication'),
                hint: $this->translator->trans('ActivityPub publication help'),
                type: new VirtualFieldType($this->settingExpression('publication_mode', $contentType, 'inherit')),
                control: 'select',
                options: [
                    'inherit'  => $this->translator->trans('Use federation default'),
                    'enabled'  => $this->translator->trans('Federate this content'),
                    'disabled' => $this->translator->trans('Do not federate this content'),
                ],
                useOnActions: $actions,
            ))
            ->addField(new FieldConfig(
                name: ContentSettingsEditor::DELIVERY_FIELD,
                label: $this->translator->trans('ActivityPub content mode'),
                hint: $this->translator->trans('ActivityPub content mode help'),
                type: new VirtualFieldType($this->settingExpression('delivery_mode', $contentType, 'inherit')),
                control: 'select',
                options: [
                    'inherit' => $this->translator->trans('Use federation default'),
                    'full'    => $this->translator->trans('Full federated text'),
                    'excerpt' => $this->translator->trans('Excerpt and canonical link'),
                ],
                useOnActions: $actions,
            ))
        ;
        if ($contentType === ContentType::POST) {
            $entity->addField(new FieldConfig(
                name: ContentSettingsEditor::OBJECT_TYPE_FIELD,
                label: $this->translator->trans('ActivityPub object type'),
                hint: $this->translator->trans('ActivityPub object type help'),
                type: new VirtualFieldType($this->objectTypeExpression($contentType)),
                control: 'select',
                options: [
                    'inherit' => $this->translator->trans('Use federation default'),
                    'Article' => 'Article',
                    'Note'    => 'Note',
                ],
                useOnActions: $actions,
            ));
        }

        $entity
            ->addField(new FieldConfig(
                name: ContentSettingsEditor::VISIBILITY_FIELD,
                label: $this->translator->trans('ActivityPub visibility'),
                hint: $this->translator->trans('ActivityPub visibility help'),
                type: new VirtualFieldType($this->settingExpression('visibility', $contentType, 'inherit')),
                control: 'select',
                options: [
                    'inherit'  => $this->translator->trans('Use federation default'),
                    'public'   => $this->translator->trans('Public'),
                    'unlisted' => $this->translator->trans('Unlisted'),
                ],
                useOnActions: $actions,
            ))
            ->addField(new FieldConfig(
                name: ContentSettingsEditor::SUMMARY_FIELD,
                label: $this->translator->trans('ActivityPub content warning'),
                hint: $this->translator->trans('ActivityPub content warning help'),
                type: new VirtualFieldType($this->settingExpression('summary', $contentType, '')),
                control: 'textarea',
                validators: [new Length(max: 500)],
                useOnActions: $actions,
            ))
            ->addField(new FieldConfig(
                name: ContentSettingsEditor::LANGUAGE_FIELD,
                label: $this->translator->trans('ActivityPub language'),
                hint: $this->translator->trans('ActivityPub language help'),
                type: new VirtualFieldType($this->settingExpression('language', $contentType, '')),
                control: 'input',
                validators: [new Length(max: 35)],
                useOnActions: $actions,
            ))
        ;

        $entity->addListener(EntityConfig::EVENT_BEFORE_UPDATE, function (BeforeSaveEvent $event) use ($contentType): void {
            try {
                $this->contentSettingsEditor->stageUpdate(
                    new ContentId($contentType, $this->primaryKey($event->primaryKey)),
                    $event->data,
                    $event->context,
                );
            } catch (\DomainException | \InvalidArgumentException $exception) {
                $event->errorMessages[] = $this->translator->trans($exception->getMessage());
            }
        });
        $entity->addListener(EntityConfig::EVENT_AFTER_UPDATE, function (AfterSaveEvent $event) use ($contentType): void {
            $this->contentSettingsEditor->complete(
                new ContentId($contentType, $this->primaryKey($event->primaryKey)),
                $event->context,
            );
        });
        if ($allowCreate) {
            $entity->addListener(EntityConfig::EVENT_BEFORE_CREATE, function (BeforeSaveEvent $event) use ($contentType): void {
                try {
                    $this->contentSettingsEditor->stageCreate($contentType, $event->data, $event->context);
                } catch (\InvalidArgumentException $exception) {
                    $event->errorMessages[] = $this->translator->trans($exception->getMessage());
                }
            });
            $entity->addListener(EntityConfig::EVENT_AFTER_CREATE, function (AfterSaveEvent $event) use ($contentType): void {
                $this->contentSettingsEditor->complete(
                    new ContentId($contentType, $this->primaryKey($event->primaryKey)),
                    $event->context,
                );
            });
        }
    }

    private function settingExpression(string $column, ContentType $contentType, string $default): string
    {
        return "COALESCE((SELECT $column FROM {$this->dbPrefix}" . ActivityPubSchema::CONTENT_SETTING_TABLE
            . " WHERE local_type = '" . $contentType->value . "' AND local_id = entity.id), '$default')";
    }

    private function objectTypeExpression(ContentType $contentType): string
    {
        return 'COALESCE((SELECT object_type FROM ' . $this->dbPrefix . ActivityPubSchema::CONTENT_SETTING_TABLE
            . " WHERE local_type = '" . $contentType->value . "' AND local_id = entity.id), "
            . '(SELECT object_type FROM ' . $this->dbPrefix . ActivityPubSchema::OBJECT_TABLE
            . " WHERE local_type = '" . $contentType->value . "' AND local_id = entity.id AND state = 'live'), 'inherit')";
    }

    private function primaryKey(?\S2\AdminYard\Database\Key $key): int
    {
        if (!$key instanceof \S2\AdminYard\Database\Key) {
            throw new \LogicException('The ActivityPub content editor primary key is missing.');
        }

        return $key->getIntId();
    }
}
