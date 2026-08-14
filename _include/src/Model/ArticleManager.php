<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use Register\Comment\CommentRepository;
use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Url\ContentSlugService;
use Register\Url\ContentUrlCollisionException;
use S2\Cms\Config\BoolProxy;
use S2\AdminYard\Config\FieldConfig;
use S2\AdminYard\Form\FormParams;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\Cms\Framework\Exception\AccessDeniedException;
use S2\Cms\Framework\Exception\NotFoundException;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

readonly class ArticleManager
{
    public function __construct(
        private DbLayer                 $dbLayer,
        private CommentRepository       $commentRepository,
        private TagRepository           $tagRepository,
        private SettingStorageInterface $settingStorage,
        private PermissionChecker       $permissionChecker,
        private BoolProxy               $newPositionOnTop,
        private BoolProxy               $useHierarchy,
        private ContentSlugService      $contentSlugService,
        private ContentChangeDispatcher $contentChangeDispatcher,
    ) {
    }

    /**
     * Builds HTML tree for the admin panel
     *
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getChildBranches(int $id, ?string $search = null): array
    {
        // TODO add published=1 if there is no view_hidden permission

        $commentNumQuery = $this->dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME . ' AS c')
            ->where("c.content_type = '" . ContentType::PAGE->value . "'")
            ->andWhere('a.id = c.content_id')
            ->getSql()
        ;

        $qb = $this->dbLayer
            ->select('title, id, published_at AS create_time, sort_order AS priority, published, (' . $commentNumQuery . ') as comment_num, parent_id')
            ->from(ContentSchema::TABLE_NAME . ' AS a')
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->orderBy('sort_order')
        ;
        if ($id === ArticleProvider::ROOT_ID) {
            $qb->andWhere('parent_id IS NULL');
        } else {
            $qb->andWhere('parent_id = :id')->setParameter('id', $id);
        }

        $searchActive = $search !== null && trim($search) !== '';
        if ($searchActive) {
            // This function also can search through the site :)
            $condition  = [];
            $paramIndex = 0;
            foreach (explode(' ', $search) as $word) {
                if ($word === '') {
                    continue;
                }

                if ($word[0] === ':' && \strlen($word) > 1) {
                    $condition[] = '(' . $this->dbLayer
                            ->select('COUNT(*)')
                            ->from(ContentTagSchema::TABLE_NAME . ' AS at')
                            ->innerJoin('tags AS t', 't.id = at.tag_id')
                            ->where("at.content_type = '" . ContentType::PAGE->value . "'")
                            ->andWhere('a.id = at.content_id')
                            ->andWhere('t.name LIKE :param' . $paramIndex)
                            ->getSql()
                        . ')';
                    $qb->setParameter('param' . $paramIndex, '%' . substr($word, 1) . '%');
                    ++$paramIndex;
                } else {
                    $condition[] = \sprintf("(title LIKE :param%s OR body LIKE :param%s)", $paramIndex, $paramIndex + 1);
                    $qb->setParameter('param' . $paramIndex, '%' . $word . '%');
                    ++$paramIndex;
                    $qb->setParameter('param' . $paramIndex, '%' . $word . '%');
                    ++$paramIndex;
                }
            }

            if (\count($condition) > 0) {
                $qb
                    ->addSelect('(' . implode(' AND ', $condition) . ') AS found')
                    ->addSelect('(' . $this->dbLayer
                            ->select('COUNT(*)')
                            ->from(ContentSchema::TABLE_NAME . ' AS a2')
                            ->where('a2.parent_id = a.id')
                            ->andWhere("a2.content_type = '" . ContentType::PAGE->value . "'")
                            ->getSql()
                        . ') AS child_num')
                ;
            }
        }

        $result = $qb->execute();

        $output = [];
        while (($article = $result->fetchAssoc()) !== false) {
            $articleId = (int)$article['id'];
            $found     = (bool)($article['found'] ?? false);
            $children  = !$searchActive || (int)($article['child_num'] ?? 0) > 0
                ? $this->getChildBranches($articleId, $search)
                : [];

            if ($searchActive && $children === [] && !$found) {
                continue;
            }

            $item = [
                'data' => [
                    'title' => (string)$article['title'],
                ],
                'attr' => [
                    'data-id'         => $articleId,
                    'data-csrf-token' => $this->getCsrfToken($articleId),
                    'id'              => 'node_' . $articleId,
                ],
            ];

            $classes = [];
            if ($searchActive) {
                $classes[] = 'Search';
                if ($found) {
                    $classes[] = 'Match';
                }
            }

            if (!(bool)$article['published']) {
                $classes[] = 'Draft';
            }

            if (\count($classes) > 0) {
                $item['data']['attr']['class'] = implode(' ', $classes);
            }

            $commentNum = (int)$article['comment_num'];
            if ($commentNum > 0) {
                $item['attr']['data-comments'] = $commentNum;
            }

            if ($children !== []) {
                if ($searchActive) {
                    $item['state'] = 'open';
                }

                $item['children'] = $children;
            }

            $output[] = $item;
        }

        return $output;
    }

    /**
     * @throws DbLayerException
     * @throws NotFoundException
     * @throws AccessDeniedException
     */
    public function createArticle(int $parentId, string $title, string $csrfToken): int
    {
        if ($csrfToken !== $this->getCsrfToken($parentId)) {
            throw new AccessDeniedException('Invalid CSRF token!');
        }

        $result = $this->dbLayer
            ->select('1')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $parentId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;

        if ($result->fetchAssoc() === false) {
            // parent_id must be an existing article. E.g. it's impossible to create another root with parent_id = 0.
            throw new NotFoundException('Item not found!');
        }

        $this->dbLayer->startTransaction();

        $slug = $this->contentSlugService->generatePage($parentId, $title);

        if ($this->newPositionOnTop->get()) {
            $this->dbLayer
                ->update(ContentSchema::TABLE_NAME)
                ->set('sort_order', 'sort_order + 1')
                ->where('parent_id = :id')->setParameter('id', $parentId)
                ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
                ->execute()
            ;
            $newPriority = 0;

        } else {
            $result      = $this->dbLayer
                ->select('MAX(sort_order + 1)')
                ->from(ContentSchema::TABLE_NAME)
                ->where('parent_id = :id')->setParameter('id', $parentId)
                ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
                ->execute()
            ;
            $newPriority = (int)$result->result();
        }

        $now = time();

        $this->dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->setValue('parent_id', ':parent_id')->setParameter('parent_id', $parentId)
            ->setValue('slug_scope', ':slug_scope')->setParameter('slug_scope', $this->contentSlugService->pageScope($parentId))
            ->setValue('title', ':title')->setParameter('title', $title)
            ->setValue('sort_order', ':sort_order')->setParameter('sort_order', $newPriority)
            ->setValue('slug', ':slug')->setParameter('slug', $slug)
            ->setValue('author_id', ':author_id')->setParameter('author_id', $this->permissionChecker->getUserId())
            ->setValue('template', ':template')->setParameter('template', $this->useHierarchy->get() ? '' : 'site.php')
            ->setValue('excerpt', ':excerpt')->setParameter('excerpt', '')
            ->setValue('body', ':body')->setParameter('body', '')
            ->setValue('created_at', ':created_at')->setParameter('created_at', $now)
            ->setValue('published_at', ':published_at')->setParameter('published_at', $now)
            ->setValue('updated_at', ':updated_at')->setParameter('updated_at', $now)
            ->execute()
        ;
        $insertId = (int)$this->dbLayer->insertId();

        $this->dbLayer->endTransaction();
        $this->contentChangeDispatcher->dispatch(ContentId::page($insertId));

        return $insertId;
    }

    /**
     * @throws DbLayerException
     * @throws AccessDeniedException
     * @throws NotFoundException
     */
    public function renameArticle(int $id, string $title, string $csrfToken): void
    {
        if (!$this->permissionChecker->isGrantedAny(PermissionChecker::PERMISSION_CREATE_ARTICLES, PermissionChecker::PERMISSION_EDIT_SITE)) {
            throw new AccessDeniedException('Permission denied.');
        }

        if ($csrfToken !== $this->getCsrfToken($id)) {
            throw new AccessDeniedException('Invalid CSRF token!');
        }

        $result = $this->dbLayer
            ->select('author_id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $id)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;
        $this->contentChangeDispatcher->dispatch(ContentId::page($id));

        $row = $result->fetchRow();
        if ($row !== false) {
            [$userId] = $row;
        } else {
            throw new NotFoundException('Item not found!');
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE) && $userId !== $this->permissionChecker->getUserId()) {
            throw new AccessDeniedException('You do not have permission to edit this article!');
        }

        $this->dbLayer
            ->update(ContentSchema::TABLE_NAME)
            ->set('title', ':title')->setParameter('title', $title)
            ->where('id = :id')->setParameter('id', $id)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;
    }

    /**
     * @throws DbLayerException
     * @throws AccessDeniedException
     * @throws NotFoundException
     */
    public function moveBranch(int $sourceId, int $destinationId, int $position, string $csrfToken): void
    {
        if (!$this->permissionChecker->isGrantedAny(PermissionChecker::PERMISSION_CREATE_ARTICLES, PermissionChecker::PERMISSION_EDIT_SITE)) {
            throw new AccessDeniedException('Permission denied.');
        }

        if ($csrfToken !== $this->getCsrfToken($sourceId)) {
            throw new AccessDeniedException('Invalid CSRF token!');
        }

        $result = $this->dbLayer
            ->select('sort_order AS priority, parent_id, author_id AS user_id, id, slug')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id IN (:source_id, :destination_id)')
            ->andWhere('content_type = :content_type')
            ->setParameter('source_id', $sourceId)
            ->setParameter('destination_id', $destinationId)
            ->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;

        $rows = $result->fetchAssocAll();
        if (\count($rows) !== 2) {
            throw new NotFoundException('Item not found!');
        }

        if ((int)$rows[0]['id'] === $sourceId) {
            $sourcePriority = $rows[0]['priority'];
            $sourceParentId = $rows[0]['parent_id'];
            $sourceUserId   = $rows[0]['user_id'];
            $sourceSlug     = (string)$rows[0]['slug'];
        } else {
            $sourcePriority = $rows[1]['priority'];
            $sourceParentId = $rows[1]['parent_id'];
            $sourceUserId   = $rows[1]['user_id'];
            $sourceSlug     = (string)$rows[1]['slug'];
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE) && $sourceUserId !== $this->permissionChecker->getUserId()) {
            throw new AccessDeniedException("You don't have permissions to move this article!");
        }

        if ($this->contentSlugService->pageStatusAtParent($sourceId, $destinationId, $sourceSlug) !== ContentSlugService::STATUS_OK) {
            throw new ContentUrlCollisionException('A page with this URL already exists at the destination.');
        }

        $this->dbLayer->startTransaction();

        $this->dbLayer
            ->update(ContentSchema::TABLE_NAME)
            ->set('sort_order', 'sort_order + 1')
            ->where('sort_order >= :priority')->setParameter('priority', $position)
            ->andWhere('parent_id = :parent_id')->setParameter('parent_id', $destinationId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;

        $this->dbLayer
            ->update(ContentSchema::TABLE_NAME)
            ->set('sort_order', ':priority')->setParameter('priority', $position)
            ->set('parent_id', ':parent_id')->setParameter('parent_id', $destinationId)
            ->set('slug_scope', ':slug_scope')->setParameter('slug_scope', $this->contentSlugService->pageScope($destinationId))
            ->where('id = :id')->setParameter('id', $sourceId)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;

        $this->dbLayer
            ->update(ContentSchema::TABLE_NAME)
            ->set('sort_order', 'sort_order - 1')
            ->where('parent_id = :parent_id')->setParameter('parent_id', $sourceParentId)
            ->andWhere('sort_order > :priority')->setParameter('priority', $sourcePriority)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;

        $this->dbLayer->endTransaction();
        $this->contentChangeDispatcher->dispatchPageBranch($sourceId);
    }

    /**
     * @throws DbLayerException
     * @throws AccessDeniedException
     * @throws NotFoundException
     */
    public function deleteBranch(int $id, string $csrfToken): void
    {
        if (!$this->permissionChecker->isGrantedAny(
            PermissionChecker::PERMISSION_CREATE_ARTICLES,
            PermissionChecker::PERMISSION_EDIT_SITE)
        ) {
            throw new AccessDeniedException('Permission denied.');
        }

        if ($csrfToken !== $this->getCsrfToken($id)) {
            throw new AccessDeniedException('Invalid CSRF token!');
        }

        $result = $this->dbLayer
            ->select('sort_order AS priority, parent_id, author_id AS user_id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $id)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;

        $row = $result->fetchRow();
        if ($row !== false) {
            [$priority, $parentId, $userId] = $row;
        } else {
            throw new NotFoundException('Item not found!');
        }

        if ($parentId === null) {
            throw new AccessDeniedException("Can't delete root item!");
        }

        if (!$this->permissionChecker->isGranted(PermissionChecker::PERMISSION_EDIT_SITE) && $userId !== $this->permissionChecker->getUserId()) {
            throw new AccessDeniedException("You don't have permissions to delete this article!");
        }

        $changedContent = $this->contentChangeDispatcher->pageBranch($id);
        $this->dbLayer->startTransaction();

        $this->dbLayer
            ->update(ContentSchema::TABLE_NAME)
            ->set('sort_order', 'sort_order - 1')
            ->where('parent_id = :parent_id')->setParameter('parent_id', $parentId)
            ->andWhere('sort_order > :priority')->setParameter('priority', $priority)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;

        $this->deleteItemAndChildren($id);

        $this->dbLayer->endTransaction();
        $this->contentChangeDispatcher->dispatch(...$changedContent);
    }

    public function getCsrfToken(int $id): string
    {
        // This token is used for every action in the tree management actions.
        // I chose to use ACTION_DELETE since then it would be compatible with the AdminYard delete token.
        $formParams = new FormParams('Article', [], $this->settingStorage, FieldConfig::ACTION_DELETE, ['id' => (string)$id]);

        return $formParams->getCsrfToken();
    }

    /**
     * @throws DbLayerException
     */
    private function deleteItemAndChildren(int $id): void
    {
        $result = $this->dbLayer
            ->select('id')
            ->from(ContentSchema::TABLE_NAME)
            ->where('parent_id = :id')->setParameter('id', $id)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;

        while ($row = $result->fetchRow()) {
            $this->deleteItemAndChildren($row[0]);
        }

        $this->dbLayer
            ->delete(ContentSchema::TABLE_NAME)
            ->where('id  = :id')->setParameter('id', $id)
            ->andWhere('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
        ;

        $this->tagRepository->remove(ContentId::page($id));

        $this->commentRepository->removeForContent(ContentId::page($id));
    }
}
