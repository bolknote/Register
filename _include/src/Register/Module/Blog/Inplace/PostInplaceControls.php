<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use S2\Cms\Model\AuthenticatedPublicUser;
use S2\Cms\Model\AuthProvider;
use S2\Cms\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\Request;

/** Builds public editing controls only for users allowed to mutate the post. */
final readonly class PostInplaceControls
{
    public function __construct(
        private AuthProvider               $authProvider,
        private PostInplaceTokenManager    $tokenManager,
        private UrlBuilder                 $urlBuilder,
    ) {
    }

    /**
     * @return array{action_url: string, admin_edit_url: string, editor_module_url: string, token: string, revision: int, return_to: string}|null
     */
    public function forPost(Request $request, int $postId, ?int $authorId, int $revision): ?array
    {
        $editor = $this->editorForPost($request, $authorId);
        if (!$editor instanceof AuthenticatedPublicUser || $postId <= 0 || $revision < 0) {
            return null;
        }

        return [
            'action_url'    => $this->urlBuilder->rawLink('/_inplace/post/' . $postId),
            'admin_edit_url' => $this->urlBuilder->rawLink('/_admin/index.php', [
                'entity=BlogPost',
                'action=edit',
                'id=' . $postId,
            ]),
            'editor_module_url' => $this->urlBuilder->rawLink('/_admin/js/editor/inplace.js'),
            'token'         => $this->tokenManager->issue($editor, $postId),
            'revision'      => $revision,
            'return_to'     => $request->getPathInfo(),
        ];
    }

    public function editorForPost(Request $request, ?int $authorId): ?AuthenticatedPublicUser
    {
        $editor = $this->authProvider->getAuthenticatedContentEditor($request);
        if (!$editor instanceof AuthenticatedPublicUser) {
            return null;
        }

        if (!$editor->canEditSite && ($authorId === null || $authorId !== $editor->id)) {
            return null;
        }

        return $editor;
    }
}
