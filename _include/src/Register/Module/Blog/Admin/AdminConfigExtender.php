<?php
/**
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

use Register\AdminYard\Config\AdminConfig;
use Register\AdminYard\Config\DbColumnFieldType;
use Register\AdminYard\Config\EntityConfig;
use Register\AdminYard\Config\FieldConfig;
use Register\AdminYard\Config\Filter;
use Register\AdminYard\Config\FilterLinkTo;
use Register\AdminYard\Config\LinkTo;
use Register\AdminYard\Config\LinkToEntityParams;
use Register\AdminYard\Config\VirtualFieldType;
use Register\AdminYard\Database\Key;
use Register\AdminYard\Database\LogicalExpression;
use Register\AdminYard\Event\AfterSaveEvent;
use Register\AdminYard\Event\BeforeDeleteEvent;
use Register\AdminYard\Translator;
use Register\Comment\CommentSchema;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Admin\AdminConfigExtenderInterface;
use Register\Core\Model\PermissionChecker;
use Register\Module\Blog\Model\BlogPageCache;

/** Adds a list-only post section. Posts are created and edited on the public site. */
readonly class AdminConfigExtender implements AdminConfigExtenderInterface
{
    public function __construct(
        private PermissionChecker       $permissionChecker,
        private Translator              $translator,
        private TagRepository           $tagRepository,
        private ContentChangeDispatcher $contentChangeDispatcher,
        private BlogPageCache           $pageCache,
        private string                  $dbType,
        private string                  $dbPrefix,
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        $postEntity    = new EntityConfig('BlogPost', $this->dbPrefix . ContentSchema::TABLE_NAME);
        $commentEntity = $adminConfig->findEntityByName('Comment')
            ?? throw new \LogicException('Comment admin entity is missing.');

        $commentEntity
            ->addListener(
                [EntityConfig::EVENT_AFTER_PATCH, EntityConfig::EVENT_AFTER_UPDATE],
                function (AfterSaveEvent $_event): void {
                    $this->pageCache->invalidateAll();
                },
            )
            ->addListener(
                EntityConfig::EVENT_BEFORE_DELETE,
                function (BeforeDeleteEvent $_event): void {
                    $this->pageCache->invalidateAll();
                },
            )
        ;

        $adminConfig->findEntityByName('Config')?->addListener(
            EntityConfig::EVENT_AFTER_PATCH,
            function (AfterSaveEvent $_event): void {
                $this->pageCache->invalidateAll();
            },
        );

        $tagEntity = $adminConfig->findEntityByName('Tag')
            ?? throw new \LogicException('Tag admin entity is missing.');
        $tagEntity->addListener(
            [EntityConfig::EVENT_AFTER_CREATE, EntityConfig::EVENT_AFTER_UPDATE],
            function (AfterSaveEvent $_event): void {
                $this->pageCache->invalidateAll();
            },
        );
        $tagEntity->addListener(
            EntityConfig::EVENT_BEFORE_DELETE,
            function (BeforeDeleteEvent $_event): void {
                $this->pageCache->invalidateAll();
            },
        );

        $adminConfig->findEntityByName('User')?->addListener(
            [EntityConfig::EVENT_AFTER_PATCH, EntityConfig::EVENT_AFTER_UPDATE],
            function (AfterSaveEvent $_event): void {
                $this->pageCache->invalidateAll();
            },
        );

        $postEntity
            ->setLimit(50)
            ->setPluralName($this->translator->trans('Posts'))
            ->setSingularName($this->translator->trans('Post'))
            ->setEntityDisplayNameBuilder(
                fn(array $row): string => (string)($row['column_title'] ?? $this->translator->trans('Post')),
            )
            ->addField(new FieldConfig(
                name: 'id',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT, true),
                useOnActions: [],
            ))
            ->addField(new FieldConfig(
                name: 'content_type',
                type: new DbColumnFieldType(defaultValue: ContentType::POST->value),
                useOnActions: [],
            ))
            ->addField(new FieldConfig(
                name: 'title',
                label: $this->translator->trans('Title'),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST],
                viewTemplate: '_admin/templates/article/view-title.php',
            ))
            ->addField(new FieldConfig(
                name: 'tags',
                label: $this->translator->trans('Tags'),
                type: new VirtualFieldType($this->tagsSql()),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'published_at',
                label: $this->translator->trans('Create time'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_UNIXTIME),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST],
                viewTemplate: '_admin/templates/date.php.inc',
            ))
            ->addField(new FieldConfig(
                name: 'scheduled_at',
                label: $this->translator->trans('Scheduled publication'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_UNIXTIME),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST],
                viewTemplate: '_admin/templates/date.php.inc',
            ))
            ->addField(new FieldConfig(
                name: 'published',
                label: $this->translator->trans('Published'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'featured',
                label: $this->translator->trans('Favorite'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST],
                viewTemplate: '_admin/templates/article/view-favorite.php',
            ))
            ->addField(new FieldConfig(
                name: 'comments_enabled',
                label: $this->translator->trans('Commented'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'comments',
                label: $this->translator->trans('Comments'),
                type: new VirtualFieldType(
                    "SELECT CASE WHEN COUNT(*) > 0 THEN COUNT(*) ELSE NULL END FROM {$this->dbPrefix}"
                    . CommentSchema::TABLE_NAME . " WHERE content_type = 'post' AND content_id = entity.id",
                    new LinkToEntityParams($commentEntity->getName(), ['content_id'], ['id']),
                ),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST],
                viewTemplate: __DIR__ . '/../resources/views/admin/post/view-comments.php.inc',
            ))
            ->addField(new FieldConfig(
                name: 'series',
                label: $this->translator->trans('Label'),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->addField($userIdField = new FieldConfig(
                name: 'author_id',
                label: $this->translator->trans('Author'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT),
                linkToEntity: new LinkTo(
                    $adminConfig->findEntityByName('User')
                        ?? throw new \LogicException('User admin entity is missing.'),
                    "CASE WHEN name IS NULL OR name = '' THEN login ELSE name END",
                ),
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->setEnabledActions([
                FieldConfig::ACTION_LIST,
                ...$this->permissionChecker->isGrantedAny(
                    PermissionChecker::PERMISSION_CREATE_ARTICLES,
                    PermissionChecker::PERMISSION_EDIT_SITE,
                ) ? [FieldConfig::ACTION_DELETE] : [],
            ])
            ->setReadAccessControl(
                $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)
                    ? new LogicalExpression('post_content_type', ContentType::POST->value, 'content_type = %s')
                    : new LogicalExpression(
                        'read_access_control_author_id',
                        $this->permissionChecker->getUserId(),
                        "content_type = 'post' AND (published = 1 OR author_id = %s)",
                    ),
            )
            ->setWriteAccessControl(
                $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)
                    ? new LogicalExpression('post_content_type', ContentType::POST->value, 'content_type = %s')
                    : new LogicalExpression(
                        'write_access_control_author_id',
                        $this->permissionChecker->getUserId(),
                        "content_type = 'post' AND author_id = %s",
                    ),
            )
            ->addListener(EntityConfig::EVENT_BEFORE_DELETE, function (BeforeDeleteEvent $event): void {
                $contentId = ContentId::post($this->requirePrimaryKey($event->primaryKey)->getIntId());
                $this->tagRepository->remove($contentId);
                $this->contentChangeDispatcher->defer($contentId);
            })
            ->addFilter(new Filter(
                'search',
                $this->translator->trans('Fulltext Search'),
                'search_input',
                'title LIKE %1$s OR body LIKE %1$s',
                fn(string $value): ?string => $value !== '' ? '%' . $value . '%' : null,
            ))
            ->addFilter(new Filter(
                'tags',
                $this->translator->trans('Tags'),
                'search_input',
                "id IN (SELECT pt.content_id FROM {$this->dbPrefix}" . ContentTagSchema::TABLE_NAME
                . " AS pt JOIN {$this->dbPrefix}tags AS t ON t.id = pt.tag_id"
                . " WHERE pt.content_type = '" . ContentType::POST->value . "' AND t.name LIKE %1\$s)",
                fn(string $value): ?string => $value !== '' ? '%' . $value . '%' : null,
            ))
            ->addFilter(new Filter(
                'is_active',
                $this->translator->trans('Published'),
                'radio',
                'published = %1$s',
                options: [
                    '' => $this->translator->trans('All'),
                    1  => $this->translator->trans('Yes'),
                    0  => $this->translator->trans('No'),
                ],
            ))
            ->addFilter(new Filter(
                'created_from',
                $this->translator->trans('Created after'),
                'date',
                'published_at >= %1$s',
                fn(?string $value): int|false|null => $value !== null ? strtotime($value) : null,
            ))
            ->addFilter(new Filter(
                'created_to',
                $this->translator->trans('Created before'),
                'date',
                'published_at < %1$s',
                fn(?string $value): int|false|null => $value !== null ? strtotime($value) : null,
            ))
            ->addFilter(new FilterLinkTo($userIdField, null))
        ;

        $tagEntity
            ->addField(new FieldConfig(
                name: 'used_in_posts',
                label: $this->translator->trans('Used in posts'),
                hint: $this->translator->trans('Used in posts info'),
                type: new VirtualFieldType(
                    "SELECT COUNT(*) FROM {$this->dbPrefix}" . ContentTagSchema::TABLE_NAME
                    . " AS pt WHERE pt.content_type = '" . ContentType::POST->value
                    . "' AND pt.tag_id = entity.id",
                    new LinkToEntityParams($postEntity->getName(), ['tags'], ['name']),
                ),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST, FieldConfig::ACTION_SHOW],
            ), 'used_in_articles')
            ->addField(new FieldConfig(
                name: 'register_blog_important',
                label: $this->translator->trans('Important tag'),
                hint: $this->translator->trans('Important tag info'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                control: 'checkbox',
                sortable: true,
                inlineEdit: $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE),
                useOnActions: [
                    FieldConfig::ACTION_LIST,
                    FieldConfig::ACTION_SHOW,
                    ...$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)
                        ? [FieldConfig::ACTION_EDIT]
                        : [],
                ],
            ))
        ;

        $adminConfig->addEntity($postEntity, 11);
    }

    private function tagsSql(): string
    {
        $column = match ($this->dbType) {
            'pgsql' => "STRING_AGG(t.name, ', ' ORDER BY pt.id)",
            'sqlite' => "GROUP_CONCAT(t.name, ', ')",
            default => 'GROUP_CONCAT(t.name ORDER BY pt.id SEPARATOR ", ")',
        };

        return "SELECT $column FROM {$this->dbPrefix}tags AS t JOIN {$this->dbPrefix}"
            . ContentTagSchema::TABLE_NAME . " AS pt ON t.id = pt.tag_id WHERE pt.content_type = '"
            . ContentType::POST->value . "' AND pt.content_id = entity.id";
    }

    private function requirePrimaryKey(?Key $primaryKey): Key
    {
        return $primaryKey ?? throw new \LogicException('This admin event requires a primary key.');
    }
}
