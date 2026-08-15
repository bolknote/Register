<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth\Admin;

use Register\Content\ContentChangeDispatcher;
use Register\Content\ContentId;
use S2\AdminYard\Config\AdminConfig;
use S2\AdminYard\Config\EntityConfig;
use S2\AdminYard\Event\BeforeDeleteEvent;
use S2\Cms\Admin\AdminConfigExtenderInterface;
use S2\Cms\Model\PermissionChecker;

final readonly class AdminConfigExtender implements AdminConfigExtenderInterface
{
    public function __construct(
        private PermissionChecker       $permissionChecker,
        private LocalLinkDeletionGuard  $deletionGuard,
        private ContentChangeDispatcher $contentChangeDispatcher,
        private LinkHealthAdminPage     $adminPage,
    ) {
    }

    #[\Override]
    public function extend(AdminConfig $adminConfig): void
    {
        $article = $adminConfig->findEntityByName('Article')
            ?? throw new \LogicException('The article admin entity is missing.');
        $post = $adminConfig->findEntityByName('BlogPost')
            ?? throw new \LogicException('The blog-post admin entity is missing.');

        $article->addListener(EntityConfig::EVENT_BEFORE_DELETE, function (BeforeDeleteEvent $event): void {
            $contentIds = $this->contentChangeDispatcher->pageBranch($event->primaryKey->getIntId());
            array_push($event->errorMessages, ...$this->deletionGuard->violations(...$contentIds));
        });
        $post->addListener(EntityConfig::EVENT_BEFORE_DELETE, function (BeforeDeleteEvent $event): void {
            array_push(
                $event->errorMessages,
                ...$this->deletionGuard->violations(ContentId::post($event->primaryKey->getIntId())),
            );
        });

        if ($this->permissionChecker->isGranted(PermissionChecker::PERMISSION_VIEW_HIDDEN)) {
            $adminConfig->setServicePage(
                'LinkHealth',
                $this->adminPage->render(...),
                55,
                $this->adminPage->title(),
            );
        }
    }
}
