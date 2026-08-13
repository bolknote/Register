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
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Url\UniqueSlugGenerator;
use S2\AdminYard\Config\AdminConfig;
use S2\AdminYard\Config\DbColumnFieldType;
use S2\AdminYard\Config\EntityConfig;
use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\Config\Filter;
use S2\AdminYard\Config\FilterLinkTo;
use S2\AdminYard\Config\LinkTo;
use S2\AdminYard\Config\LinkToEntityParams;
use S2\AdminYard\Config\VirtualFieldType;
use S2\AdminYard\Database\Key;
use S2\AdminYard\Database\LogicalExpression;
use S2\AdminYard\Event\AfterLoadEvent;
use S2\AdminYard\Event\AfterSaveEvent;
use S2\AdminYard\Event\BeforeRenderEvent;
use S2\AdminYard\Event\BeforeDeleteEvent;
use S2\AdminYard\Event\BeforeSaveEvent;
use S2\AdminYard\Translator;
use S2\AdminYard\Validator\Length;
use S2\AdminYard\Validator\Regex;
use S2\Cms\Admin\AdminConfigExtenderInterface;
use S2\Cms\Admin\AdminConfigProvider;
use S2\Cms\Admin\Controller\CommentControllerFactory;
use S2\Cms\Admin\Event\VisibleEntityChangedEvent;
use S2\Cms\Comment\Antispam\SpamFeedbackService;
use S2\Cms\Model\PermissionChecker;
use S2\Cms\Model\TagsProvider;
use Register\Module\Blog\BlogUrlBuilder;
use Register\Module\Blog\Model\BlogCommentNotifier;
use Register\Module\Blog\Model\PostProvider;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use S2\Cms\Pdo\DbLayerException;

readonly class AdminConfigExtender implements AdminConfigExtenderInterface
{
    public function __construct(
        private PermissionChecker        $permissionChecker,
        private Translator               $translator,
        private TagsProvider             $tagsProvider,
        private TagRepository            $tagRepository,
        private PostProvider             $postProvider,
        private BlogUrlBuilder           $blogUrlBuilder,
        private BlogCommentNotifier      $blogCommentNotifier,
        private SpamFeedbackService      $spamFeedbackService,
        private UniqueSlugGenerator      $uniqueSlugGenerator,
        private EventDispatcherInterface $eventDispatcher,
        private string                   $dbType,
        private string                   $dbPrefix
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        $postEntity    = new EntityConfig('BlogPost', $this->dbPrefix . ContentSchema::TABLE_NAME);
        $commentEntity = new EntityConfig('BlogComment', $this->dbPrefix . CommentSchema::TABLE_NAME);

        $commentEntity
            ->setPluralName($this->translator->trans('Blog comments'))
            ->setSingularName($this->translator->trans('Comment'))
            ->setEntityDisplayNameBuilder(fn(array $row): string => $this->buildCommentDetails($row))
            ->setEditTitle($this->translator->trans('Edit comment'))
            ->addField(new FieldConfig(
                name: 'id',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT, true),
                useOnActions: []
            ))
            ->addField($postIdField = new FieldConfig(
                name: 'content_id',
                label: $this->translator->trans('Post'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_INT),
                control: 'autocomplete',
                linkToEntity: new LinkTo($postEntity, 'title'),
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'nick',
                label: $this->translator->trans('Author'),
                control: 'input',
                validators: [new Length(max: 50)],
                viewTemplate: '_admin/templates/comment/view-author.php',
            ))
            ->addField(new FieldConfig(
                name: 'email',
                label: $this->translator->trans('Email'),
                control: 'input',
                validators: [new Length(max: 80)],
                useOnActions: $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN) ? [FieldConfig::ACTION_EDIT, FieldConfig::ACTION_LIST] : [],
            ))
            ->addField(new FieldConfig(
                name: 'show_email',
                label: $this->translator->trans('Show email'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                control: 'checkbox',
            ))
            ->addField(new FieldConfig(
                name: 'subscribed',
                label: $this->translator->trans('Subscribed to comments'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                control: 'checkbox',
            ))
            ->addField(new FieldConfig(
                name: 'time',
                label: $this->translator->trans('Date'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_UNIXTIME),
                control: 'datetime',
                sortable: true,
                useOnActions: [FieldConfig::ACTION_SHOW, FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'text',
                label: $this->translator->trans('Comment'),
                control: 'textarea',
            ))
            ->addField(new FieldConfig(
                name: 'ip',
                label: $this->translator->trans('IP address'),
                sortable: true,
                useOnActions: $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN) ? [FieldConfig::ACTION_LIST] : [],
            ))
            ->addField(new FieldConfig(
                name: 'shown',
                label: $this->translator->trans('Published'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                control: 'checkbox',
                inlineEdit: $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_HIDE_COMMENTS),
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'sent',
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                control: 'checkbox',
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'good',
                label: $this->translator->trans('Good comment'),
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_BOOL),
                control: 'checkbox',
                inlineEdit: $this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_COMMENTS),
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'spam_score',
                label: $this->translator->trans('Spam score'),
                type: new VirtualFieldType(
                    "SELECT score FROM {$this->dbPrefix}spam_assessments AS sa WHERE sa.target_type = 'post' AND sa.comment_id = entity.id ORDER BY sa.id DESC LIMIT 1"
                ),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'spam_label',
                label: $this->translator->trans('Spam label'),
                type: new VirtualFieldType(
                    "SELECT moderator_label FROM {$this->dbPrefix}spam_assessments AS sa WHERE sa.target_type = 'post' AND sa.comment_id = entity.id ORDER BY sa.id DESC LIMIT 1"
                ),
                sortable: true,
                useOnActions: [FieldConfig::ACTION_LIST],
            ))
            ->addField(new FieldConfig(
                name: 'spam_reasons',
                label: $this->translator->trans('Spam reasons'),
                type: new VirtualFieldType(
                    "SELECT reasons FROM {$this->dbPrefix}spam_assessments AS sa WHERE sa.target_type = 'post' AND sa.comment_id = entity.id ORDER BY sa.id DESC LIMIT 1"
                ),
                useOnActions: [FieldConfig::ACTION_LIST],
                viewTemplate: '_admin/templates/comment/view-spam-reasons.php',
            ))
            ->addFilter(new Filter(
                'search',
                $this->translator->trans('Search'),
                'search_input',
                'text LIKE %1$s OR nick LIKE %1$s OR email LIKE %1$s OR ip LIKE %1$s',
                fn(string $value): ?string => $value !== '' ? '%' . $value . '%' : null
            ))
            ->addFilter(new FilterLinkTo(
                $postIdField,
                $this->translator->trans('Post'),
            ))
            ->addFilter(new Filter(
                'good',
                $this->translator->trans('Mark'),
                'radio',
                'good = %1$s',
                options: [
                    '' => $this->translator->trans('All'),
                    1  => $this->translator->trans('Good'),
                    0  => $this->translator->trans('Usual'),
                ],
            ))
            ->addFilter(new Filter(
                'published',
                $this->translator->trans('Published'),
                'radio',
                'shown = %1$s',
                options: [
                    '' => $this->translator->trans('All'),
                    1  => $this->translator->trans('Yes'),
                    0  => $this->translator->trans('No'),
                ]
            ))
            ->addFilter(new Filter(
                'status',
                $this->translator->trans('Status'),
                'radio',
                '(sent = 0 AND shown = 0) = (0 = %1$s)',
                options: [
                    '' => $this->translator->trans('All'),
                    0  => $this->translator->trans('Pending'),
                    1  => $this->translator->trans('Considered'),
                ]
            ))
            ->setControllerClassOrFactory(new CommentControllerFactory(
                $this->spamFeedbackService,
                ContentType::POST,
                $this->blogCommentNotifier->notify(...),
            ))
            ->setEnabledActions([
                FieldConfig::ACTION_LIST,
                ...$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_COMMENTS) ? [FieldConfig::ACTION_EDIT, FieldConfig::ACTION_DELETE] : [],
            ])
            ->setListActionsTemplate('_admin/templates/comment/list-actions.php.inc')
            ->addListener(EntityConfig::EVENT_BEFORE_PATCH, function (BeforeSaveEvent $event): void {
                if (isset($event->data['shown'])) {
                    $this->blogCommentNotifier->notify($this->requirePrimaryKey($event->primaryKey)->getIntId());
                }
            })
        ;

        $commentEntity
            ->setReadAccessControl($this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)
                ? new LogicalExpression('content_type', ContentType::POST->value)
                : new LogicalExpression('content_type', ContentType::POST->value, 'content_type = %s AND shown = 1'))
            ->setWriteAccessControl(new LogicalExpression('content_type', ContentType::POST->value))
        ;

        $postEntity
            ->setPluralName($this->translator->trans('Posts'))
            ->setEntityDisplayNameBuilder(fn(array $row): string => (string)($row['column_title'] ?? $this->translator->trans('Post')))
            ->setNewTitle($this->translator->trans('New post'))
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
                name: 'created_at',
                // AdminYard accepts mixed here; its published PHPDoc is narrower than the runtime contract.
                // @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal
                type: new DbColumnFieldType(FieldConfig::DATA_TYPE_UNIXTIME, defaultValue: new \DateTimeImmutable()),
                useOnActions: [],
            ))
            ->addField(new FieldConfig(
                name: 'excerpt',
                type: new DbColumnFieldType(defaultValue: ''),
                useOnActions: [],
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
                    (static function (): \S2\AdminYard\Validator\Regex {
                        $validator          = new Regex('#^[\p{L}\p{N}_\- ,\.!]*$#u');
                        $validator->message = 'Tags must contain only letters, numbers and spaces.';
                        return $validator;
                    })(),
                ],
                sortable: true,
                useOnActions: [FieldConfig::ACTION_EDIT, FieldConfig::ACTION_LIST],
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
                ...$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_CREATE_ARTICLES) ? [FieldConfig::ACTION_EDIT, FieldConfig::ACTION_DELETE, FieldConfig::ACTION_NEW] : [],
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
                    if (trim($event->data['virtual_tags']) !== '') {
                        // Add an extra comma to simplify adding a new tag
                        $event->data['virtual_tags'] .= ', ';
                    }
                }
            })
            ->addListener(EntityConfig::EVENT_BEFORE_CREATE, function (BeforeSaveEvent $event): void {
                $event->data['slug'] = $this->uniqueSlugGenerator->generate(
                    (string)$event->data['title'],
                    fn(string $slug): bool => $this->postProvider->checkUrlStatus(0, $slug) === 'ok',
                );
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
                $urlStatus = $this->postProvider->checkUrlStatus($postId, $event->data['slug']);
                if ((bool)$event->data['published'] && $urlStatus !== 'ok') {
                    $event->errorMessages[] = $this->getUrlStatusTitle($urlStatus);
                    return;
                }

                $changed = false;
                foreach (['body', 'title', 'slug', 'date_label'] as $field) {
                    if ($event->data[$field] !== $oldData['column_' . $field]) {
                        $changed = true;
                    }
                }

                if ($changed) {
                    // If the page text has been modified, we check if this modification is done by current user
                    if ($event->data['revision'] !== $oldData['column_revision']) {
                        // No, it's somebody else
                        $event->errorMessages[] = $this->translator->trans('Outdated version');
                        return;
                    }

                    $event->data['revision']        = (string)($event->data['revision'] + 1);
                    $event->context['new_revision'] = $event->data['revision'];
                } else {
                    // Changes might be in unimportant fields only.
                    // So we ignore $event->data['revision'] and refresh it on client side to the current value.
                    $event->data['revision']        = $oldData['column_revision'];
                    $event->context['new_revision'] = $oldData['column_revision'];
                }

                $newPublished = (bool)$event->data['published'];
                $oldPublished = (bool)$oldData['column_published'];

                if (
                    ($newPublished && (!$oldPublished || $changed)) // Publish a new article or update an existing one
                    || (!$newPublished && $oldPublished) // Withdraw a published article
                ) {
                    $event->context['visible_changed_event'] = new VisibleEntityChangedEvent(
                        $postEntity->getName(),
                        $postId
                    );
                }

                $event->context['post_id'] = $postId;
                $event->context['url']     = $event->data['slug'];
            })
            ->addListener(EntityConfig::EVENT_AFTER_UPDATE, function (AfterSaveEvent $event): void {
                $visibleChangedEvent = $event->context['visible_changed_event'] ?? null;
                if ($visibleChangedEvent instanceof VisibleEntityChangedEvent) {
                    $this->eventDispatcher->dispatch($visibleChangedEvent);
                }

                $event->ajaxExtraResponse = [
                    ...$this->getPostStatusData($event->context['post_id'], $event->context['url']),
                    'revision' => $event->context['new_revision'],
                ];
            })
            ->addListener([EntityConfig::EVENT_BEFORE_UPDATE], function (BeforeSaveEvent $event): void {
                $event->context['tags'] = $event->data['tags'];
                unset($event->data['tags']);
            })
            ->addListener([EntityConfig::EVENT_AFTER_UPDATE], function (AfterSaveEvent $event): void {
                $tagStr = $event->context['tags'];
                $tags   = array_map(trim(...), explode(',', $tagStr));
                $tags   = array_filter($tags, static fn(string $tag): bool => $tag !== '');

                $newTagIds = AdminConfigProvider::tagIdsFromTags($event->dataProvider, $tags, $this->dbPrefix);

                $this->tagRepository->replace(
                    ContentId::post($this->requirePrimaryKey($event->primaryKey)->getIntId()),
                    array_values(array_map(intval(...), $newTagIds)),
                );
            })
            ->addListener(EntityConfig::EVENT_BEFORE_DELETE, function (BeforeDeleteEvent $event): void {
                $this->tagRepository->remove(ContentId::post($this->requirePrimaryKey($event->primaryKey)->getIntId()));
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
            ->setEditTemplate(__DIR__ . '/../resources/views/admin/post/edit.php.inc')
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
                name: 's2_blog_important',
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
            ->addEntity($commentEntity, 12)
        ;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildCommentDetails(array $row): string
    {
        $author = \trim((string)($row['column_nick'] ?? ''));
        $text   = $this->buildTextPreview($row['column_text'] ?? null);

        $parts = array_filter([$author, $text], static fn(string $value): bool => $value !== '');

        return implode(' — ', $parts);
    }

    private function buildTextPreview(?string $text): string
    {
        $text = \trim((string)$text);
        if ($text === '') {
            return '';
        }

        $text = (string)\preg_replace('/\\s+/u', ' ', $text);
        $limit = 80;
        if (\mb_strlen($text) > $limit) {
            return \mb_substr($text, 0, $limit - 1) . '…';
        }

        return $text;
    }

    /**
     * @throws DbLayerException
     * @return array<string, string>
     */
    private function getPostStatusData(int $postId, string $url): array
    {
        $urlStatus = $this->postProvider->checkUrlStatus($postId, $url);

        return [
            'url'       => $this->blogUrlBuilder->post($url),
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
