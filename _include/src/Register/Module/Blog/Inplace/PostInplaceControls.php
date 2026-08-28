<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Inplace;

use Register\Ai\AiSettings;
use Register\Core\Model\AuthenticatedPublicUser;
use Register\Core\Model\AuthProvider;
use Register\Core\Model\UrlBuilder;
use Symfony\Component\HttpFoundation\Request;

/** Builds public editing controls only for users allowed to mutate the post. */
final readonly class PostInplaceControls
{
    public function __construct(
        private AuthProvider               $authProvider,
        private PostInplaceTokenManager    $tokenManager,
        private UrlBuilder                 $urlBuilder,
        private AiSettings                 $aiSettings,
    ) {
    }

    /**
     * @return array{action_url: string, tag_suggestions_url: string, token: string, revision: int, return_to: string, ai_enabled: bool, ai_alt_enabled: bool, create: false}|null
     */
    public function forPost(Request $request, int $postId, ?int $authorId, int $revision): ?array
    {
        $editor = $this->editorForPost($request, $authorId);
        if (!$editor instanceof AuthenticatedPublicUser || $postId <= 0 || $revision < 0) {
            return null;
        }

        return [
            'action_url'    => $this->urlBuilder->rawLink('/_inplace/post/' . $postId),
            'tag_suggestions_url' => $this->urlBuilder->rawLink('/_inplace/tags'),
            'token'         => $this->tokenManager->issue($editor, $postId),
            'revision'      => $revision,
            'return_to'     => $request->getPathInfo(),
            'ai_enabled'    => $this->aiSettings->isConfigured(),
            'ai_alt_enabled' => $this->aiSettings->autoAltAvailable(),
            'create'        => false,
        ];
    }

    /** @return array{action_url: string, tag_suggestions_url: string, token: string, revision: int, return_to: string, ai_enabled: bool, ai_alt_enabled: bool, create: true, editor_name: string}|null */
    public function forCreate(Request $request): ?array
    {
        $editor = $this->editorForCreate($request);
        if (!$editor instanceof AuthenticatedPublicUser) {
            return null;
        }

        return [
            'action_url'     => $this->urlBuilder->rawLink('/_inplace/post/new'),
            'tag_suggestions_url' => $this->urlBuilder->rawLink('/_inplace/tags'),
            'token'          => $this->tokenManager->issueForCreate($editor),
            'revision'       => 0,
            'return_to'      => $request->getPathInfo(),
            'ai_enabled'     => $this->aiSettings->isConfigured(),
            'ai_alt_enabled' => $this->aiSettings->autoAltAvailable(),
            'create'         => true,
            'editor_name'    => $editor->displayName(),
        ];
    }

    public function editorForCreate(Request $request): ?AuthenticatedPublicUser
    {
        return $this->authProvider->getAuthenticatedContentEditor($request);
    }

    public function editorForPost(Request $request, ?int $authorId): ?AuthenticatedPublicUser
    {
        $editor = $this->authProvider->getAuthenticatedContentEditor($request);
        if ($editor === null) {
            return null;
        }

        if (!$editor->canEditSite && ($authorId === null || $authorId !== $editor->id)) {
            return null;
        }

        return $editor;
    }

}
