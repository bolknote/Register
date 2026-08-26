<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Admin;

use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentPublicationScheduler;
use Register\Content\PublicationMetadataGenerator;
use Register\Content\Admin\ContentRevision;
use Register\Content\Admin\ContentRevisionService;
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlGenerator;
use Register\Url\ContentUrlAliasRepository;
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
use Register\AdminYard\Event\AfterLoadEvent;
use Register\AdminYard\Event\AfterSaveEvent;
use Register\AdminYard\Event\BeforeRenderEvent;
use Register\AdminYard\Event\BeforeDeleteEvent;
use Register\AdminYard\Event\BeforeSaveEvent;
use Register\AdminYard\Translator;
use Register\AdminYard\Validator\Length;
use Register\AdminYard\Validator\Regex;
use Register\Core\Admin\AdminConfigExtenderInterface;
use Register\Core\Admin\AdminConfigProvider;
use Register\Core\Model\PermissionChecker;
use Register\Core\Model\TagsProvider;
use Register\Module\Blog\Model\PostProvider;
use Register\Module\Blog\Model\BlogPageCache;
use Register\Core\Pdo\DbLayerException;

readonly class AdminConfigExtender implements AdminConfigExtenderInterface
{
    public function __construct(
        private PermissionChecker        $permissionChecker,
        private Translator               $translator,
        private TagsProvider             $tagsProvider,
        private TagRepository            $tagRepository,
        private PostProvider             $postProvider,
        private ContentUrlGenerator      $contentUrlGenerator,
        private ContentRevisionService   $contentRevisionService,
        private ContentSlugService       $contentSlugService,
        private ContentUrlAliasRepository $contentUrlAliases,
        private ContentChangeDispatcher  $contentChangeDispatcher,
        private ContentPublicationScheduler $contentPublicationScheduler,
        private PublicationMetadataGenerator $publicationMetadataGenerator,
        private BlogPageCache              $pageCache,
        private string                   $dbType,
        private string                   $dbPrefix
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
                    $this->pageCache->invalidateFirstPage();
                },
            )
            ->addListener(
                EntityConfig::EVENT_BEFORE_DELETE,
                function (BeforeDeleteEvent $_event): void {
                    $this->pageCache->invalidateFirstPage();
                },
            )
        ;

        $configEntity = $adminConfig->findEntityByName('Config');
        $configEntity?->addListener(
            EntityConfig::EVENT_AFTER_PATCH,
            function (AfterSaveEvent $_event): void {
                $this->pageCache->invalidateAll();
            },
        );

        $tagEntity = $adminConfig->findEntityByName('Tag');
        $tagEntity?->addListener(
            [EntityConfig::EVENT_AFTER_CREATE, EntityConfig::EVENT_AFTER_UPDATE],
            function (AfterSaveEvent $_event): void {
                $this->pageCache->invalidateFirstPage();
            },
        );
        $tagEntity?->addListener(
            EntityConfig::EVENT_BEFORE_DELETE,
            function (BeforeDeleteEvent $_event): void {
                $this->pageCache->invalidateFirstPage();
            },
        );

        $userEntity = $adminConfig->findEntityByName('User');
        $userEntity?->addListener(
            [EntityConfig::EVENT_AFTER_PATCH, EntityConfig::EVENT_AFTER_UPDATE],
            function (AfterSaveEvent $_event): void {
                $this->pageCache->invalidateFirstPage();
            },
        );

        $postEntity
            ->setLimit(50)
            ->setPluralName($this->translator->trans('Posts'))
            ->setSingularName($this->translator->trans('Post'))
            ->setEntityDisplayNameBuilder(fn(array $row): string => (string)($row['column_title'] ?? $this->translator->trans('Post')))
            ->setNewTitle($this->translator->trans('New post'))
            ->setEditTitle($this->translator->trans('Edit post'))
            ->addField(new FieldConfig(
                name: 'id',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT, true),
                useOnActions: []
            ))
            ->addField(new FieldConfig(
                name: 'content_type',
                type: new DbColumnFieldType(defaultValue: ContentType::POST->value),
                useOnActions: [],
            ))
            ->addField(new FieldConfig(
                name: 'slug_scope',
                type: new DbColumnFieldType(defaultValue: 'root'),
                useOnActions: [],
            ))
            ->addField(new FieldConfig(
                name: 'created_at',
                // AdminYard accepts mixed here; its published PHPDoc is narrower than the runtime contract.
                // @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_UNIXTIME, defaultValue: new \DateTimeImmutable()),
                useOnActions: [],
            ))
            ->addField(new FieldConfig(
                name: 'excerpt',
                label: $this->translator->trans('Excerpt'),
                hint: $this->translator->trans('Excerpt help'),
                type: new DbColumnFieldType(defaultValue: ''),
                control: 'input',
                useOnActions: [FieldConfig::ACTION_NEW, FieldConfig::ACTION_EDIT],
            ))
            ->addField(new FieldConfig(
                name: 'title',
                label: $this->translator->trans('Title'),
                control: 'input',
                validators: [new Length(max: 255)],
                sortable: true,
                actionOnClick: 'edit',
                viewTemplate: '_admin/templates/article/view-title.php',
            ))
            ->addField(new FieldConfig(
                name: 'tags',
                label: $this->translator->trans('Tags'),
                hint: $this->translator->trans('Tags help'),
                type: new VirtualFieldType((function (): string {
                    $column     = match ($this->dbType) {
                        'pgsql' => "STRING_AGG(t.name, ', ' ORDER BY pt.id)",
                        'sqlite' => "GROUP_CONCAT(t.name, ', ')", // seems like SQLite does not support ORDER BY
                        default => 'GROUP_CONCAT(t.name ORDER BY pt.id SEPARATOR ", ")',
                    };
                    $tableName  = $this->dbPrefix . 'tags';
                    $tableName2 = $this->dbPrefix . ContentTagSchema::TABLE_NAME;
                    return "SELECT $column FROM $tableName AS t JOIN $tableName2 AS pt ON t.id = pt.tag_id WHERE pt.content_type = '" . ContentType::POST->value . "' AND pt.content_id = entity.id";
                })()),
                control: 'input',
                validators: [
                    (static function (): \Register\AdminYard\Validator\Regex {
                        $validator          = new Regex('#^[\p{L}\p{N}_\- ,\.!]*$#u');
                        $validator->message = 'Tags must contain only letters, numbers and spaces.';
                        return $validator;
                    })(),
                ],
                sortable: true,
                useOnActions: [FieldConfig::ACTION_NEW, FieldConfig::ACTION_EDIT, FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'published_at',
                label: $this->translator->trans('Create time'),
                // AdminYard accepts mixed here; its published PHPDoc is narrower than the runtime contract.
                // @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_UNIXTIME, defaultValue: new \DateTimeImmutable()),
                control: 'datetime',
                sortable: true,
                useOnActions: [FieldConfig::ACTION_EDIT, FieldConfig::ACTION_LIST],
                viewTemplate: '_admin/templates/date.php.inc',
            ))
            ->addField(new FieldConfig(
                name: 'scheduled_at',
                label: $this->translator->trans('Scheduled publication'),
                hint: $this->translator->trans('Scheduled publication help'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_UNIXTIME),
                control: 'datetime',
                sortable: true,
                useOnActions: [FieldConfig::ACTION_EDIT, FieldConfig::ACTION_LIST],
                viewTemplate: '_admin/templates/date.php.inc',
            ))
            ->addField(new FieldConfig(
                name: 'date_label',
                label: $this->translator->trans('Display date'),
                hint: $this->translator->trans('Display date help'),
                control: 'input',
                validators: [new Length(max: 255)],
                useOnActions: [FieldConfig::ACTION_NEW, FieldConfig::ACTION_EDIT],
            ))
            ->addField(new FieldConfig(
                name: 'updated_at',
                label: $this->translator->trans('Modify time'),
                hint: $this->translator->trans('Modify time help'),
                // @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_UNIXTIME, defaultValue: new \DateTimeImmutable()),
                control: 'datetime',
                sortable: true,
                useOnActions: [FieldConfig::ACTION_EDIT],
                viewTemplate: '_admin/templates/date.php.inc',
            ))
            ->addField(new FieldConfig(
                name: 'body',
                label: $this->translator->trans('Text'),
                control: 'html_textarea',
                useOnActions: [FieldConfig::ACTION_NEW, FieldConfig::ACTION_EDIT],
            ))
            ->addField(new FieldConfig(
                name: 'meta_description',
                label: $this->translator->trans('Meta description'),
                hint: $this->translator->trans('Meta help'),
                control: 'input',
                validators: [new Length(max: 255)],
                useOnActions: [FieldConfig::ACTION_NEW, FieldConfig::ACTION_EDIT],
            ))
            ->addField(new FieldConfig(
                name: 'meta_keywords',
                label: $this->translator->trans('Meta keywords'),
                hint: $this->translator->trans('Meta help'),
                control: 'input',
                validators: [new Length(max: 255)],
                useOnActions: [FieldConfig::ACTION_NEW, FieldConfig::ACTION_EDIT],
            ))
            ->addField(new FieldConfig(
                name: 'social_image',
                label: $this->translator->trans('Social image'),
                hint: $this->translator->trans('Social image help'),
                control: 'input',
                validators: [new Length(max: 2048)],
                useOnActions: [FieldConfig::ACTION_NEW, FieldConfig::ACTION_EDIT],
            ))
            ->addField(new FieldConfig(
                name: 'published',
                label: $this->translator->trans('Published'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                control: 'checkbox',
                useOnActions: [FieldConfig::ACTION_EDIT, FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'featured',
                label: $this->translator->trans('Favorite'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                control: 'checkbox',
                sortable: true,
                useOnActions: [
                    FieldConfig::ACTION_LIST,
                    ...$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE) ? [FieldConfig::ACTION_EDIT] : [],
                ],
                viewTemplate: '_admin/templates/article/view-favorite.php',
            ))
            ->addField(new FieldConfig(
                name: 'comments_enabled',
                label: $this->translator->trans('Commented'),
                hint: $this->translator->trans('Commented info'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                control: 'checkbox',
                useOnActions: [FieldConfig::ACTION_EDIT, FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'comments',
                label: $this->translator->trans('Comments'),
                type: new VirtualFieldType(
                    "SELECT CASE WHEN COUNT(*) > 0 THEN COUNT(*) ELSE NULL END FROM {$this->dbPrefix}" . CommentSchema::TABLE_NAME . " WHERE content_type = 'post' AND content_id = entity.id",
                    new LinkToEntityParams($commentEntity->getName(), ['content_id'], ['id']),
                ),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST],
                viewTemplate: __DIR__ . '/../resources/views/admin/post/view-comments.php.inc'
            ))
            ->addField(new FieldConfig(
                name: 'series',
                label: $this->translator->trans('Label'),
                hint: $this->translator->trans('Label help'),
                control: 'input',
                useOnActions: [FieldConfig::ACTION_EDIT, FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'slug',
                label: $this->translator->trans('URL part'),
                type: new DbColumnFieldType(defaultValue: ''),
                control: 'input',
                validators: [new Length(max: 255)],
                useOnActions: [FieldConfig::ACTION_EDIT],
            ))
            ->addField($userIdField = new FieldConfig(
                name: 'author_id',
                label: $this->translator->trans('Author'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT, defaultValue: $this->permissionChecker->getUserId()),
                control: 'select',
                linkToEntity: new LinkTo($adminConfig->findEntityByName('User') ?? throw new \LogicException('User admin entity is missing.'), "CASE WHEN name IS NULL OR name = '' THEN login ELSE name END"),
                useOnActions: [
                    FieldConfig::ACTION_LIST,
                    ...$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE) ? [FieldConfig::ACTION_EDIT] : [],
                ],
            ))
            ->addField(new FieldConfig(
                name: 'revision',
                control: 'hidden_input',
                useOnActions: [FieldConfig::ACTION_EDIT],
            ))
            ->setEnabledActions([
                FieldConfig::ACTION_LIST,
                ...$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_CREATE_ARTICLES) ? [FieldConfig::ACTION_NEW] : [],
                ...$this->permissionChecker->isGrantedAny(
                    PermissionChecker::PERMISSION_CREATE_ARTICLES,
                    PermissionChecker::PERMISSION_EDIT_SITE,
                ) ? [FieldConfig::ACTION_EDIT, FieldConfig::ACTION_DELETE] : [],
            ])
            ->setReadAccessControl(
                $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)
                    ? new LogicalExpression('post_content_type', ContentType::POST->value, 'content_type = %s')
                    : new LogicalExpression('read_access_control_author_id', $this->permissionChecker->getUserId(), "content_type = 'post' AND (published = 1 OR author_id = %s)")
            )
            ->setWriteAccessControl(
                $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE)
                    ? new LogicalExpression('post_content_type', ContentType::POST->value, 'content_type = %s')
                    : new LogicalExpression('write_access_control_author_id', $this->permissionChecker->getUserId(), "content_type = 'post' AND author_id = %s")
            )
            ->addListener([EntityConfig::EVENT_AFTER_EDIT_FETCH], function (AfterLoadEvent $event): void {
                if (\is_array($event->data)) {
                    // Convert NULL to an empty string when the edit form is filled with current data
                    $event->data['virtual_tags'] = (string)$event->data['virtual_tags'];
                }
            })
            ->addListener(EntityConfig::EVENT_BEFORE_CREATE, function (BeforeSaveEvent $event): void {
                $event->data['slug'] = $this->contentSlugService->generatePost((string)$event->data['title']);
            })
            ->addListener([EntityConfig::EVENT_BEFORE_CREATE, EntityConfig::EVENT_BEFORE_UPDATE], function (BeforeSaveEvent $event): void {
                $metadata = $this->publicationMetadataGenerator->complete(
                    (string)$event->data['title'],
                    (string)$event->data['body'],
                    (string)$event->data['excerpt'],
                    (string)$event->data['meta_description'],
                );
                $event->data['excerpt'] = $metadata->excerpt;
                $event->data['meta_description'] = $metadata->metaDescription;
            })
            ->addListener(EntityConfig::EVENT_BEFORE_EDIT_RENDER, function (BeforeRenderEvent $event): void {
                if (!\is_array($event->data)) {
                    throw new \LogicException('Blog post render data must be an array.');
                }

                $event->data['tagsList']        = $this->tagsProvider->getAllTags();
                $event->data['labelList']       = $this->postProvider->getAllLabels();

                $formData = $event->data['form']->getData();
                $id       = (int)$event->data['primaryKey']['id'];

                $event->data['commentsNum'] = $this->postProvider->getCommentNum($id, $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN));
                $event->data['statusData']  = $this->getPostStatusData($id, $formData['slug']);
            })
            ->addListener(EntityConfig::EVENT_BEFORE_UPDATE, function (BeforeSaveEvent $event) use ($postEntity): void {
                $this->contentPublicationScheduler->prepareForSave($event->data);

                $oldData = $event->dataProvider->getEntity(
                    $this->dbPrefix . ContentSchema::TABLE_NAME,
                    $postEntity->getFieldDataTypes(FieldConfig::ACTION_EDIT, includePrimaryKey: true),
                    [],
                    [
                        new LogicalExpression('content_type', ContentType::POST->value),
                        ...$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE) ? [] : [
                            new LogicalExpression('author_id', $this->permissionChecker->getUserId()),
                        ],
                    ],
                    $this->requirePrimaryKey($event->primaryKey)
                );
                if ($oldData === null) {
                    $event->errorMessages[] = 'Post not found';
                    return;
                }

                $postId    = $this->requirePrimaryKey($event->primaryKey)->getIntId();
                $urlStatus = $this->contentSlugService->postStatus($postId, $event->data['slug']);
                if ((bool)$event->data['published'] && $urlStatus !== 'ok') {
                    $event->errorMessages[] = $this->getUrlStatusTitle($urlStatus);
                    return;
                }

                $revision = $this->contentRevisionService->resolve(
                    $event->data,
                    $oldData,
                    ['body', 'title', 'slug', 'excerpt', 'date_label', 'meta_keywords', 'meta_description', 'social_image', 'scheduled_at'],
                );
                if (!$revision instanceof ContentRevision) {
                    $event->errorMessages[] = $this->translator->trans('Outdated version');
                    return;
                }

                $event->data['revision']        = $revision->value;
                $event->context['new_revision'] = $revision->value;
                $event->context['post_id']      = $postId;
                $event->context['url']          = $event->data['slug'];
                $event->context['previous_url'] = (string)$oldData['column_slug'];
            })
            ->addListener(EntityConfig::EVENT_AFTER_UPDATE, function (AfterSaveEvent $event): void {
                $this->contentUrlAliases->rememberCanonicalChange(
                    ContentId::post($event->context['post_id']),
                    $event->context['previous_url'],
                    $event->context['url'],
                );
                $this->contentChangeDispatcher->dispatch(ContentId::post($event->context['post_id']));

                $event->ajaxExtraResponse = [
                    ...$this->getPostStatusData($event->context['post_id'], $event->context['url']),
                    'revision' => $event->context['new_revision'],
                ];
            })
            ->addListener([EntityConfig::EVENT_BEFORE_CREATE, EntityConfig::EVENT_BEFORE_UPDATE], function (BeforeSaveEvent $event): void {
                $event->context['tags'] = $event->data['tags'];
                unset($event->data['tags']);
            })
            ->addListener([EntityConfig::EVENT_AFTER_CREATE, EntityConfig::EVENT_AFTER_UPDATE], function (AfterSaveEvent $event): void {
                $tagStr = $event->context['tags'];
                $tags   = array_map(trim(...), explode(',', $tagStr));
                $tags   = array_filter($tags, static fn(string $tag): bool => $tag !== '');

                $newTagIds = AdminConfigProvider::tagIdsFromTags($event->dataProvider, $tags, $this->dbPrefix);

                $this->tagRepository->replace(
                    ContentId::post($this->requirePrimaryKey($event->primaryKey)->getIntId()),
                    array_values(array_map(intval(...), $newTagIds)),
                );
            })
            ->addListener(EntityConfig::EVENT_AFTER_CREATE, function (AfterSaveEvent $event): void {
                $this->contentChangeDispatcher->dispatch(
                    ContentId::post($this->requirePrimaryKey($event->primaryKey)->getIntId()),
                );
            })
            ->addListener(EntityConfig::EVENT_BEFORE_DELETE, function (BeforeDeleteEvent $event): void {
                $contentId = ContentId::post($this->requirePrimaryKey($event->primaryKey)->getIntId());
                $this->tagRepository->remove($contentId);
                $this->contentChangeDispatcher->defer($contentId);
            })
            ->addFilter(
                new Filter(
                    'search',
                    $this->translator->trans('Fulltext Search'),
                    'search_input',
                    'title LIKE %1$s OR body LIKE %1$s',
                    fn(string $value): ?string => $value !== '' ? '%' . $value . '%' : null
                )
            )
            ->addFilter(
                new Filter(
                    'tags',
                    $this->translator->trans('Tags'),
                    'search_input',
                    "id IN (SELECT pt.content_id FROM " . $this->dbPrefix . ContentTagSchema::TABLE_NAME . " AS pt JOIN " . $this->dbPrefix . "tags AS t ON t.id = pt.tag_id WHERE pt.content_type = '" . ContentType::POST->value . "' AND t.name LIKE %1\$s)",
                    fn(string $value): ?string => $value !== '' ? '%' . $value . '%' : null
                )
            )
            ->addFilter(
                new Filter(
                    'is_active',
                    $this->translator->trans('Published'),
                    'radio',
                    'published = %1$s',
                    options: [
                        '' => $this->translator->trans('All'),
                        1  => $this->translator->trans('Yes'),
                        0  => $this->translator->trans('No'),
                    ]
                )
            )
            ->addFilter(
                new Filter(
                    'created_from',
                    $this->translator->trans('Created after'),
                    'date',
                    'published_at >= %1$s',
                    fn(?string $value): int|false|null => $value !== null ? strtotime($value) : null
                )
            )
            ->addFilter(
                new Filter(
                    'created_to',
                    $this->translator->trans('Created before'),
                    'date',
                    'published_at < %1$s',
                    fn(?string $value): int|false|null => $value !== null ? strtotime($value) : null
                )
            )
            ->addFilter(new FilterLinkTo($userIdField, null))
            ->setNewTemplate('_admin/templates/article/edit.php.inc')
            ->setEditTemplate('_admin/templates/article/edit.php.inc')
        ;

        $tagEntity = $adminConfig->findEntityByName('Tag') ?? throw new \LogicException('Tag admin entity is missing.');
        $tagEntity
            ->addField(new FieldConfig(
                name: 'used_in_posts',
                label: $this->translator->trans('Used in posts'),
                hint: $this->translator->trans('Used in posts info'),
                type: new VirtualFieldType(
                    "SELECT COUNT(*) FROM " . $this->dbPrefix . ContentTagSchema::TABLE_NAME . " AS pt WHERE pt.content_type = '" . ContentType::POST->value . "' AND pt.tag_id = entity.id",
                    new LinkToEntityParams($postEntity->getName(), ['tags'], ['name' /* tags.name */])
                ),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST, FieldConfig::ACTION_SHOW]
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
                    ...$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE) ? [FieldConfig::ACTION_EDIT] : [],
                ]
            ))
        ;

        $adminConfig
            ->addEntity($postEntity, 11)
        ;
    }

    /**
     * @throws DbLayerException
     * @return array<string, string>
     */
    private function getPostStatusData(int $postId, string $url): array
    {
        $urlStatus = $this->contentSlugService->postStatus($postId, $url);

        return [
            'url'       => $this->contentUrlGenerator->post($url),
            'urlStatus' => $urlStatus,
            'urlTitle'  => $this->getUrlStatusTitle($urlStatus),
        ];
    }

    private function getUrlStatusTitle(string $urlStatus): string
    {
        return match ($urlStatus) {
            'empty'      => $this->translator->trans('URL empty'),
            'not_unique' => $this->translator->trans('URL not unique'),
            'ok'         => '',
            default      => $this->translator->trans('URL unavailable'),
        };
    }

    private function requirePrimaryKey(?Key $primaryKey): Key
    {
        return $primaryKey ?? throw new \LogicException('This admin event requires a primary key.');
    }
}
